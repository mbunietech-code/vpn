import Foundation
import Libbox
import Network
import NetworkExtension

/// libbox's view of iOS: opens the TUN through NEPacketTunnelProvider and
/// reports the default network. Based on SFI 1.14.0
/// (Library/Network/ExtensionPlatformInterface.swift), iOS parts only;
/// shell, bridge, notifications and Tailscale are not used here.
final class TunnelPlatformInterface: NSObject, LibboxPlatformInterfaceProtocol, LibboxCommandServerHandlerProtocol {
    private unowned let tunnel: PacketTunnelProvider
    private var networkSettings: NEPacketTunnelNetworkSettings?
    private var nwMonitor: NWPathMonitor?

    init(_ tunnel: PacketTunnelProvider) {
        self.tunnel = tunnel
    }

    func reset() {
        networkSettings = nil
        nwMonitor?.cancel()
        nwMonitor = nil
    }

    // ---- TUN --------------------------------------------------------------

    func openTun(_ options: LibboxTunOptionsProtocol?, ret0_: UnsafeMutablePointer<Int32>?) throws {
        try runBlocking { [self] in
            try await openTun0(options, ret0_)
        }
    }

    private func openTun0(_ options: LibboxTunOptionsProtocol?, _ ret0_: UnsafeMutablePointer<Int32>?) async throws {
        guard let options, let ret0_ else {
            throw TunnelError("openTun: missing options")
        }
        let settings = NEPacketTunnelNetworkSettings(tunnelRemoteAddress: "127.0.0.1")
        if options.getAutoRoute() {
            settings.mtu = NSNumber(value: options.getMTU())

            var dnsSettings: NEDNSSettings?
            if options.getDNSMode()!.value != LibboxDNSModeDisabled {
                let servers = try options.getDNSServerAddress().toArray()
                if !servers.isEmpty {
                    dnsSettings = NEDNSSettings(servers: servers)
                    settings.dnsSettings = dnsSettings
                }
            }

            var v4Addresses: [String] = []
            var v4Masks: [String] = []
            let v4 = options.getInet4Address()!
            while v4.hasNext() {
                let p = v4.next()!
                v4Addresses.append(p.address())
                v4Masks.append(p.mask())
            }
            let v4Settings = NEIPv4Settings(addresses: v4Addresses, subnetMasks: v4Masks)
            var v4Routes: [NEIPv4Route] = []
            let v4Route = options.getInet4RouteAddress()!
            while v4Route.hasNext() {
                let p = v4Route.next()!
                v4Routes.append(NEIPv4Route(destinationAddress: p.address(), subnetMask: p.mask()))
            }
            if v4Routes.isEmpty { v4Routes.append(NEIPv4Route.default()) }
            var v4Exclude: [NEIPv4Route] = []
            let v4Ex = options.getInet4RouteExcludeAddress()!
            while v4Ex.hasNext() {
                let p = v4Ex.next()!
                v4Exclude.append(NEIPv4Route(destinationAddress: p.address(), subnetMask: p.mask()))
            }
            v4Settings.includedRoutes = v4Routes
            v4Settings.excludedRoutes = v4Exclude
            settings.ipv4Settings = v4Settings

            var v6Addresses: [String] = []
            var v6Prefixes: [NSNumber] = []
            let v6 = options.getInet6Address()!
            while v6.hasNext() {
                let p = v6.next()!
                v6Addresses.append(p.address())
                v6Prefixes.append(NSNumber(value: p.prefix()))
            }
            let v6Settings = NEIPv6Settings(addresses: v6Addresses, networkPrefixLengths: v6Prefixes)
            var v6Routes: [NEIPv6Route] = []
            let v6Route = options.getInet6RouteAddress()!
            while v6Route.hasNext() {
                let p = v6Route.next()!
                v6Routes.append(NEIPv6Route(destinationAddress: p.address(), networkPrefixLength: NSNumber(value: p.prefix())))
            }
            if v6Routes.isEmpty { v6Routes.append(NEIPv6Route.default()) }
            var v6Exclude: [NEIPv6Route] = []
            let v6Ex = options.getInet6RouteExcludeAddress()!
            while v6Ex.hasNext() {
                let p = v6Ex.next()!
                v6Exclude.append(NEIPv6Route(destinationAddress: p.address(), networkPrefixLength: NSNumber(value: p.prefix())))
            }
            v6Settings.includedRoutes = v6Routes
            v6Settings.excludedRoutes = v6Exclude
            settings.ipv6Settings = v6Settings

            let hasDefaultRoute = v4Routes.contains {
                $0.destinationAddress == "0.0.0.0" && $0.destinationSubnetMask == "0.0.0.0"
            }
            if !hasDefaultRoute {
                dnsSettings?.matchDomains = [""]
                dnsSettings?.matchDomainsNoSearch = true
            }
        }

        if options.isHTTPProxyEnabled() {
            let proxy = NEProxySettings()
            let server = NEProxyServer(address: options.getHTTPProxyServer(), port: Int(options.getHTTPProxyServerPort()))
            proxy.httpServer = server
            proxy.httpsServer = server
            proxy.httpEnabled = true
            proxy.httpsEnabled = true
            let bypass = options.getHTTPProxyBypassDomain()!.toArray()
            if !bypass.isEmpty { proxy.exceptionList = bypass }
            let match = options.getHTTPProxyMatchDomain()!.toArray()
            if !match.isEmpty { proxy.matchDomains = match }
            settings.proxySettings = proxy
        }

        networkSettings = settings
        try await tunnel.setTunnelNetworkSettings(settings)

        if let fd = tunnel.packetFlow.value(forKeyPath: "socket.fileDescriptor") as? Int32 {
            ret0_.pointee = fd
            return
        }
        let fd = LibboxGetTunnelFileDescriptor()
        if fd == -1 {
            throw TunnelError("openTun: missing file descriptor")
        }
        ret0_.pointee = fd
    }

    // ---- default network ---------------------------------------------------

    func startDefaultInterfaceMonitor(_ listener: LibboxInterfaceUpdateListenerProtocol?) throws {
        guard let listener else { return }
        let monitor = NWPathMonitor()
        nwMonitor = monitor
        let first = DispatchSemaphore(value: 0)
        monitor.pathUpdateHandler = { path in
            Self.update(listener, path)
            first.signal()
            monitor.pathUpdateHandler = { path in Self.update(listener, path) }
        }
        monitor.start(queue: DispatchQueue.global())
        first.wait()
    }

    private static func update(_ listener: LibboxInterfaceUpdateListenerProtocol, _ path: Network.NWPath) {
        guard path.status != .unsatisfied, let iface = path.availableInterfaces.first else {
            listener.updateDefaultInterface("", interfaceIndex: -1, isExpensive: false, isConstrained: false)
            return
        }
        listener.updateDefaultInterface(iface.name, interfaceIndex: Int32(iface.index), isExpensive: path.isExpensive, isConstrained: path.isConstrained)
    }

    func closeDefaultInterfaceMonitor(_: LibboxInterfaceUpdateListenerProtocol?) throws {
        nwMonitor?.cancel()
        nwMonitor = nil
    }

    func getInterfaces() throws -> LibboxNetworkInterfaceIteratorProtocol {
        guard let nwMonitor else { throw TunnelError("network monitor not started") }
        let path = nwMonitor.currentPath
        if path.status == .unsatisfied { return InterfaceIterator([]) }
        let list: [LibboxNetworkInterface] = path.availableInterfaces.map { it in
            let iface = LibboxNetworkInterface()
            iface.name = it.name
            iface.index = Int32(it.index)
            switch it.type {
            case .wifi: iface.type = LibboxInterfaceTypeWIFI
            case .cellular: iface.type = LibboxInterfaceTypeCellular
            case .wiredEthernet: iface.type = LibboxInterfaceTypeEthernet
            default: iface.type = LibboxInterfaceTypeOther
            }
            return iface
        }
        return InterfaceIterator(list)
    }

    private final class InterfaceIterator: NSObject, LibboxNetworkInterfaceIteratorProtocol {
        private var iterator: IndexingIterator<[LibboxNetworkInterface]>
        private var current: LibboxNetworkInterface?
        init(_ list: [LibboxNetworkInterface]) { iterator = list.makeIterator() }
        func hasNext() -> Bool {
            current = iterator.next()
            return current != nil
        }
        func next() -> LibboxNetworkInterface? { current }
    }

    func underNetworkExtension() -> Bool { true }
    func includeAllNetworks() -> Bool { false }
    func usePlatformAutoDetectControl() -> Bool { false }
    func autoDetectControl(_: Int32) throws {}
    func useProcFS() -> Bool { false }
    func registerMyInterface(_: String?) {}
    func localDNSTransport() -> (any LibboxLocalDNSTransportProtocol)? { nil }
    func readWIFIState() -> LibboxWIFIState? { nil }

    func clearDNSCache() {
        guard let networkSettings else { return }
        runBlocking {
            self.tunnel.reasserting = true
            defer { self.tunnel.reasserting = false }
            try? await self.tunnel.setTunnelNetworkSettings(nil)
            try? await self.tunnel.setTunnelNetworkSettings(networkSettings)
        }
    }

    // ---- not used on iOS ---------------------------------------------------

    func findConnectionOwner(_: Int32, sourceAddress _: String?, sourcePort _: Int32, destinationAddress _: String?, destinationPort _: Int32) throws -> LibboxConnectionOwner {
        throw TunnelError("not supported")
    }

    func send(_: LibboxNotification?) throws {}
    func cancelNotification(_: String?, typeID _: Int32) throws {}
    func startNeighborMonitor(_: LibboxNeighborUpdateListenerProtocol?) throws {}
    func closeNeighborMonitor(_: LibboxNeighborUpdateListenerProtocol?) throws {}
    func usePlatformShell() -> Bool { false }
    func checkPlatformShell() throws { throw TunnelError("not supported") }
    func openShellSession(_: LibboxPlatformUser?, command _: String?, environ _: (any LibboxStringIteratorProtocol)?, term _: String?, rows _: Int32, cols _: Int32) throws -> any LibboxShellSessionProtocol {
        throw TunnelError("not supported")
    }

    func lookupUser(_: String?) throws -> LibboxPlatformUser { throw TunnelError("not supported") }

    func lookupSFTPServer(_ error: NSErrorPointer) -> String {
        error?.pointee = TunnelError("not supported") as NSError
        return ""
    }

    func readSystemSSHHostKey(_ error: NSErrorPointer) -> String {
        error?.pointee = TunnelError("not supported") as NSError
        return ""
    }

    func tailscaleHostname() -> String { "" }
    func usePlatformBridge() -> Bool { false }
    func createBridge(_: LibboxBridgeOptions?) throws -> any LibboxBridgeSessionProtocol {
        throw TunnelError("not supported")
    }

    // ---- LibboxCommandServerHandler ----------------------------------------

    func serviceStop() throws {
        tunnel.stopService()
    }

    func serviceReload() throws {
        try runBlocking { [self] in
            try await tunnel.reloadService()
        }
    }

    func getSystemProxyStatus() throws -> LibboxSystemProxyStatus {
        let status = LibboxSystemProxyStatus()
        guard let proxy = networkSettings?.proxySettings, proxy.httpServer != nil else { return status }
        status.available = true
        status.enabled = proxy.httpEnabled
        return status
    }

    func setSystemProxyEnabled(_: Bool) throws {}
    func connectSSHAgent(_: UnsafeMutablePointer<Int32>?) throws { throw TunnelError("not supported") }
    func triggerNativeCrash() throws {}
    func writeDebugMessage(_: String?) {}
}

private extension LibboxStringIteratorProtocol {
    func toArray() -> [String] {
        var out: [String] = []
        while hasNext() { out.append(next()) }
        return out
    }
}

// From SFI Library/Network/Extension+RunBlocking.swift: libbox calls the
// platform interface synchronously from Go.
func runBlocking<T>(_ block: @escaping () async -> T) -> T {
    let semaphore = DispatchSemaphore(value: 0)
    let box = ResultBox<T>()
    Task.detached(priority: .userInitiated) {
        box.value = await block()
        semaphore.signal()
    }
    semaphore.wait()
    return box.value
}

func runBlocking<T>(_ block: @escaping () async throws -> T) throws -> T {
    let semaphore = DispatchSemaphore(value: 0)
    let box = ResultBox<Result<T, Error>>()
    Task.detached(priority: .userInitiated) {
        do {
            box.value = .success(try await block())
        } catch {
            box.value = .failure(error)
        }
        semaphore.signal()
    }
    semaphore.wait()
    return try box.value.get()
}

private final class ResultBox<T> {
    var value: T!
}
