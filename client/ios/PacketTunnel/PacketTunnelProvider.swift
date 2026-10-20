import Foundation
import Libbox
import NetworkExtension

/// Runs sing-box (libbox) with the config the app fetched from
/// `{sub_url}?format=singbox&platform=ios`. The app passes it in the start
/// options and also leaves it in the App Group, which is what iOS restarts
/// use (they come without options). Reference: SagerNet/sing-box-for-apple.
final class PacketTunnelProvider: NEPacketTunnelProvider {
  static let appGroup = "group.com.mbunie.mvpn"

  private var commandServer: LibboxCommandServer?
  private lazy var platform = PlatformInterface(self)

  override func startTunnel(options: [String: NSObject]?) async throws {
    guard
      let shared = FileManager.default.containerURL(
        forSecurityApplicationGroupIdentifier: Self.appGroup)
    else {
      throw TunnelError("App Group \(Self.appGroup) is not available")
    }
    let configURL = shared.appendingPathComponent("config.json")
    let config: String
    if let fromApp = options?["configContent"] as? String {
      config = fromApp
    } else if let saved = try? String(contentsOf: configURL, encoding: .utf8) {
      config = saved
    } else {
      throw TunnelError("No VPN config. Open Mbunie VPN and press Connect.")
    }

    let working = shared.appendingPathComponent("Working", isDirectory: true)
    let temp = FileManager.default.temporaryDirectory
    try FileManager.default.createDirectory(at: working, withIntermediateDirectories: true)

    let setup = LibboxSetupOptions()
    setup.basePath = shared.path
    setup.workingPath = working.path
    setup.tempPath = temp.path
    setup.logMaxLines = 1000
    // The extension is killed above ~50 MB: let libbox shed connections first.
    setup.oomKillerEnabled = true
    setup.crashReportSource = "NetworkExtension"
    let info = Bundle.main.infoDictionary
    setup.appVersion = info?["CFBundleVersion"] as? String ?? ""
    setup.appMarketingVersion = info?["CFBundleShortVersionString"] as? String ?? ""

    var error: NSError?
    LibboxSetup(setup, &error)
    if let error { throw TunnelError("setup: \(error.localizedDescription)") }

    commandServer = LibboxNewCommandServer(platform, platform, &error)
    if let error { throw TunnelError("command server: \(error.localizedDescription)") }
    do {
      try commandServer?.start()
      try commandServer?.startOrReloadService(config, options: LibboxOverrideOptions())
    } catch {
      throw TunnelError("start: \(error.localizedDescription)")
    }
  }

  override func stopTunnel(with _: NEProviderStopReason) async {
    stopService()
    if let server = commandServer {
      try? await Task.sleep(nanoseconds: 100 * NSEC_PER_MSEC)
      server.close()
      commandServer = nil
    }
  }

  override func sleep() async {
    commandServer?.pause()
  }

  override func wake() {
    commandServer?.wake()
  }

  func stopService() {
    try? commandServer?.closeService()
    platform.reset()
  }

  func reloadService() throws {
    guard
      let shared = FileManager.default.containerURL(
        forSecurityApplicationGroupIdentifier: Self.appGroup),
      let config = try? String(
        contentsOf: shared.appendingPathComponent("config.json"), encoding: .utf8)
    else { return }
    reasserting = true
    defer { reasserting = false }
    try commandServer?.startOrReloadService(config, options: LibboxOverrideOptions())
  }
}

struct TunnelError: LocalizedError {
  let message: String
  init(_ message: String) { self.message = message }
  var errorDescription: String? { message }
  var failureReason: String? { message }
}
