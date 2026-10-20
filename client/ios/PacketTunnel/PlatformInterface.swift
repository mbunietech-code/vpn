import Foundation
import Libbox
import Network
import NetworkExtension
import UIKit

/// What libbox asks of the host on iOS: open the tun (network settings from
/// the config's tun inbound), watch the default interface, and the command
/// server callbacks. Trimmed from sing-box-for-apple's
/// ExtensionPlatformInterface (no macOS / jailbreak / SSH / bridge parts).
final class PlatformInterface: NSObject, LibboxPlatformInterfaceProtocol,
  LibboxCommandServerHandlerProtocol
{
  private unowned let tunnel: PacketTunnelProvider
  private var networkSettings: NEPacketTunnelNetworkSettings?
  private var pathMonitor: NWPathMonitor?

  init(_ tunnel: PacketTunnelProvider) {
    self.tunnel = tunnel
  }

  func reset() {
    networkSettings = nil
    pathMonitor?.cancel()
    pathMonitor = nil
  }

  // MARK: - Tun

  func openTun(_ options: LibboxTunOptionsProtocol?, ret0_: UnsafeMutablePointer<Int32>?) throws {
    guard let options, let ret0_ else { throw Self.error("openTun: missing options") }
    let settings = try Self.networkSettings(options)
    try runBlocking { [tunnel] in
      try await tunnel.setTunnelNetworkSettings(settings)
    }
    networkSettings = settings

    if let fd = tunnel.packetFlow.value(forKeyPath: "socket.fileDescriptor") as? Int32 {
      ret0_.pointee = fd
      return
    }
    let fd = LibboxGetTunnelFileDescriptor()
    guard fd != -1 else { throw Self.error("openTun: missing tun file descriptor") }
    ret0_.pointee = fd
  }

  private static func networkSettings(_ options: LibboxTunOptionsProtocol) throws
    -> NEPacketTunnelNetworkSettings
  {
    let settings = NEPacketTunnelNetworkSettings(tunnelRemoteAddress: "127.0.0.1")
    guard options.getAutoRoute() else { return settings }
    settings.mtu = NSNumber(value: options.getMTU())

    // All lookups go to the tun, where the config's hijack-dns answers them.
    // Without this the Wi-Fi resolver is used and China poisons the answers
    // (youtube.com resolved to a Twitter address on macOS).
    if options.getDNSMode()?.value != LibboxDNSModeDisabled {
      let servers = try strings(options.getDNSServerAddress())
      if !servers.isEmpty {
        let dns = NEDNSSettings(servers: servers)
        dns.matchDomains = [""]
        dns.matchDomainsNoSearch = true
        settings.dnsSettings = dns
      }
    }

    let v4 = prefixes(options.getInet4Address())
    let ipv4 = NEIPv4Settings(addresses: v4.map { $0.address() }, subnetMasks: v4.map { $0.mask() })
    var v4Routes = prefixes(options.getInet4RouteAddress()).map {
      NEIPv4Route(destinationAddress: $0.address(), subnetMask: $0.mask())
    }
    if v4Routes.isEmpty { v4Routes = [NEIPv4Route.default()] }
    ipv4.includedRoutes = v4Routes
    ipv4.excludedRoutes = prefixes(options.getInet4RouteExcludeAddress()).map {
      NEIPv4Route(destinationAddress: $0.address(), subnetMask: $0.mask())
    }
    settings.ipv4Settings = ipv4

    let v6 = prefixes(options.getInet6Address())
    if !v6.isEmpty {
      let ipv6 = NEIPv6Settings(
        addresses: v6.map { $0.address() },
        networkPrefixLengths: v6.map { NSNumber(value: $0.prefix()) })
      var v6Routes = prefixes(options.getInet6RouteAddress()).map {
        NEIPv6Route(destinationAddress: $0.address(), networkPrefixLength: NSNumber(value: $0.prefix()))
      }
      if v6Routes.isEmpty { v6Routes = [NEIPv6Route.default()] }
      ipv6.includedRoutes = v6Routes
      ipv6.excludedRoutes = prefixes(options.getInet6RouteExcludeAddress()).map {
        NEIPv6Route(destinationAddress: $0.address(), networkPrefixLength: NSNumber(value: $0.prefix()))
      }
      settings.ipv6Settings = ipv6
    }

    if options.isHTTPProxyEnabled() {
      let proxy = NEProxySettings()
      let server = NEProxyServer(
        address: options.getHTTPProxyServer(), port: Int(options.getHTTPProxyServerPort()))
      proxy.httpServer = server
      proxy.httpsServer = server
      proxy.httpEnabled = true
      proxy.httpsEnabled = true
      let bypass = strings(options.getHTTPProxyBypassDomain())
      if !bypass.isEmpty { proxy.exceptionList = bypass }
      let match = strings(options.getHTTPProxyMatchDomain())
      if !match.isEmpty { proxy.matchDomains = match }
      settings.proxySettings = proxy
    }
    return settings
  }

  // MARK: - Default interface

  func startDefaultInterfaceMonitor(_ listener: LibboxInterfaceUpdateListenerProtocol?) throws {
    guard let listener else { return }
    let monitor = NWPathMonitor()
    pathMonitor = monitor
    let first = DispatchSemaphore(value: 0)
    var signalled = false
    monitor.pathUpdateHandler = { path in
      Self.update(listener, path)
      if !signalled {
        signalled = true
        first.signal()
      }
    }
    monitor.start(queue: DispatchQueue(label: "com.mbunie.mvpn.tunnel.path"))
    first.wait()
  }

  private static func update(_ listener: LibboxInterfaceUpdateListenerProtocol, _ path: Network.NWPath) {
    guard path.status != .unsatisfied, let interface = path.availableInterfaces.first else {
      listener.updateDefaultInterface("", interfaceIndex: -1, isExpensive: false, isConstrained: false)
      return
    }
    listener.updateDefaultInterface(
      interface.name, interfaceIndex: Int32(interface.index),
      isExpensive: path.isExpensive, isConstrained: path.isConstrained)
  }

  func closeDefaultInterfaceMonitor(_: LibboxInterfaceUpdateListenerProtocol?) throws {
    pathMonitor?.cancel()
    pathMonitor = nil
  }

  func getInterfaces() throws -> LibboxNetworkInterfaceIteratorProtocol {
    guard let pathMonitor else { throw Self.error("interface monitor not started") }
    let path = pathMonitor.currentPath
    guard path.status != .unsatisfied else { return InterfaceIterator([]) }
    return InterfaceIterator(
      path.availableInterfaces.map { it in
        let interface = LibboxNetworkInterface()
        interface.name = it.name
        interface.index = Int32(it.index)
        switch it.type {
        case .wifi: interface.type = LibboxInterfaceTypeWIFI
        case .cellular: interface.type = LibboxInterfaceTypeCellular
        case .wiredEthernet: interface.type = LibboxInterfaceTypeEthernet
        default: interface.type = LibboxInterfaceTypeOther
        }
        return interface
      })
  }

  // MARK: - Simple answers

  func underNetworkExtension() -> Bool { true }
  func includeAllNetworks() -> Bool { false }
  func useProcFS() -> Bool { false }
  func usePlatformAutoDetectControl() -> Bool { false }
  func autoDetectControl(_: Int32) throws {}
  func usePlatformShell() -> Bool { false }
  func usePlatformBridge() -> Bool { false }
  func tailscaleHostname() -> String { UIDevice.current.name }
  func localDNSTransport() -> (any LibboxLocalDNSTransportProtocol)? { nil }
  func readWIFIState() -> LibboxWIFIState? { nil }
  func registerMyInterface(_: String?) {}
  func startNeighborMonitor(_: LibboxNeighborUpdateListenerProtocol?) throws {}
  func closeNeighborMonitor(_: LibboxNeighborUpdateListenerProtocol?) throws {}
  func send(_: LibboxNotification?) throws {}
  func cancelNotification(_: String?, typeID _: Int32) throws {}

  func clearDNSCache() {
    guard let networkSettings else { return }
    try? runBlocking { [tunnel] in
      tunnel.reasserting = true
      defer { tunnel.reasserting = false }
      try await tunnel.setTunnelNetworkSettings(nil)
      try await tunnel.setTunnelNetworkSettings(networkSettings)
    }
  }

  func findConnectionOwner(
    _: Int32, sourceAddress _: String?, sourcePort _: Int32, destinationAddress _: String?,
    destinationPort _: Int32
  ) throws -> LibboxConnectionOwner {
    throw Self.error("not supported on iOS")
  }

  func checkPlatformShell() throws { throw Self.error("not supported on iOS") }

  func openShellSession(
    _: LibboxPlatformUser?, command _: String?, environ _: (any LibboxStringIteratorProtocol)?,
    term _: String?, rows _: Int32, cols _: Int32
  ) throws -> any LibboxShellSessionProtocol {
    throw Self.error("not supported on iOS")
  }

  func readSystemSSHHostKey(_ error: NSErrorPointer) -> String {
    error?.pointee = Self.error("not supported on iOS")
    return ""
  }

  func lookupSFTPServer(_ error: NSErrorPointer) -> String {
    error?.pointee = Self.error("not supported on iOS")
    return ""
  }

  func lookupUser(_: String?) throws -> LibboxPlatformUser {
    throw Self.error("not supported on iOS")
  }

  func createBridge(_: LibboxBridgeOptions?) throws -> any LibboxBridgeSessionProtocol {
    throw Self.error("not supported on iOS")
  }

  // MARK: - Command server handler

  func serviceStop() throws {
    tunnel.stopService()
  }

  func serviceReload() throws {
    try tunnel.reloadService()
  }

  func getSystemProxyStatus() throws -> LibboxSystemProxyStatus {
    let status = LibboxSystemProxyStatus()
    if let proxy = networkSettings?.proxySettings, proxy.httpServer != nil {
      status.available = true
      status.enabled = proxy.httpEnabled
    }
    return status
  }

  func setSystemProxyEnabled(_: Bool) throws {}
  func connectSSHAgent(_: UnsafeMutablePointer<Int32>?) throws {
    throw Self.error("not supported on iOS")
  }
  func triggerNativeCrash() throws {}
  func writeDebugMessage(_: String?) {}

  // MARK: - Helpers

  private static func error(_ message: String) -> NSError {
    NSError(domain: "MbunieTunnel", code: 0, userInfo: [NSLocalizedDescriptionKey: message])
  }

  private static func prefixes(_ iterator: LibboxRoutePrefixIteratorProtocol?) -> [LibboxRoutePrefix] {
    var result: [LibboxRoutePrefix] = []
    while let iterator, iterator.hasNext() {
      if let next = iterator.next() { result.append(next) }
    }
    return result
  }

  private static func strings(_ iterator: LibboxStringIteratorProtocol?) -> [String] {
    var result: [String] = []
    while let iterator, iterator.hasNext() {
      result.append(iterator.next())
    }
    return result
  }
}

private final class InterfaceIterator: NSObject, LibboxNetworkInterfaceIteratorProtocol {
  private var items: IndexingIterator<[LibboxNetworkInterface]>
  private var current: LibboxNetworkInterface?

  init(_ items: [LibboxNetworkInterface]) {
    self.items = items.makeIterator()
  }

  func hasNext() -> Bool {
    current = items.next()
    return current != nil
  }

  func next() -> LibboxNetworkInterface? {
    current
  }
}

/// Runs async work from libbox's synchronous callbacks (they come in on Go
/// threads, never the main thread).
private func runBlocking<T>(_ body: @escaping @Sendable () async throws -> T) throws -> T {
  let box = ResultBox<T>()
  let done = DispatchSemaphore(value: 0)
  Task.detached {
    do { box.result = .success(try await body()) } catch { box.result = .failure(error) }
    done.signal()
  }
  done.wait()
  return try box.result!.get()
}

private final class ResultBox<T>: @unchecked Sendable {
  var result: Result<T, Error>?
}
