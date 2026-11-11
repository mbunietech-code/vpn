import Foundation
import Libbox
import NetworkExtension

/// Runs sing-box (libbox) for the app. The app writes the config into the
/// App Group container and starts the tunnel with `configPath`; the China
/// rule sets it references are local files in the same container.
///
/// Modelled on the official sing-box Apple client (SFI 1.14.0,
/// Library/Network/ExtensionProvider.swift), trimmed to what iOS needs.
class PacketTunnelProvider: NEPacketTunnelProvider {
    static let appGroup = "group.com.mbunie.mvpn"

    private var commandServer: LibboxCommandServer?
    private lazy var platformInterface = TunnelPlatformInterface(self)

    override init() {
        LibboxPrepareCrashSignalHandlers()
        LibboxReinstallCrashSignalHandlers()
        super.init()
    }

    override func startTunnel(options: [String: NSObject]?) async throws {
        do {
            try await start(options)
            clearLastError()
        } catch {
            // The app shows this through friendly_error.dart and sends the
            // full text to Admin > Devices.
            saveLastError(error.localizedDescription)
            throw error
        }
    }

    private func start(_ options: [String: NSObject]?) async throws {
        guard let container = Self.containerURL else {
            throw TunnelError("App Group \(Self.appGroup) is not available")
        }
        // On-demand / "Connect" from iOS Settings starts without options:
        // reuse the config the app wrote last.
        let configPath = (options?["configPath"] as? String)
            ?? container.appendingPathComponent("config.json").path
        let configContent: String
        do {
            configContent = try String(contentsOfFile: configPath, encoding: .utf8)
        } catch {
            throw TunnelError("read config: \(error.localizedDescription)")
        }

        let caches = container.appendingPathComponent("Library/Caches", isDirectory: true)
        let working = caches.appendingPathComponent("Working", isDirectory: true)
        try? FileManager.default.createDirectory(at: working, withIntermediateDirectories: true)

        let setup = LibboxSetupOptions()
        setup.basePath = container.path
        setup.workingPath = working.path
        setup.tempPath = caches.path
        setup.logMaxLines = 300
        setup.crashReportSource = "NetworkExtension"
        setup.appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? ""
        setup.appMarketingVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? ""
        // The extension is killed at about 50 MB: let libbox shed connections first.
        setup.oomKillerEnabled = true
        var setupError: NSError?
        LibboxSetup(setup, &setupError)
        if let setupError {
            throw TunnelError("setup: \(setupError.localizedDescription)")
        }

        var serverError: NSError?
        commandServer = LibboxNewCommandServer(platformInterface, platformInterface, &serverError)
        if let serverError {
            throw TunnelError("command server: \(serverError.localizedDescription)")
        }
        do {
            try commandServer!.start()
        } catch {
            throw TunnelError("command server: \(error.localizedDescription)")
        }
        do {
            try commandServer!.startOrReloadService(configContent, options: LibboxOverrideOptions())
        } catch {
            throw TunnelError(error.localizedDescription)
        }
        writeMessage("(packet-tunnel) started")
    }

    func writeMessage(_ message: String) {
        commandServer?.writeMessage(2, message: message)
    }

    func stopService() {
        do {
            try commandServer?.closeService()
        } catch {
            writeMessage("(packet-tunnel) stop service: \(error.localizedDescription)")
        }
        platformInterface.reset()
    }

    func reloadService() async throws {
        reasserting = true
        defer { reasserting = false }
        guard let container = Self.containerURL else { return }
        let config = try String(contentsOf: container.appendingPathComponent("config.json"), encoding: .utf8)
        try commandServer?.startOrReloadService(config, options: LibboxOverrideOptions())
    }

    override func stopTunnel(with reason: NEProviderStopReason) async {
        writeMessage("(packet-tunnel) stopping, reason: \(reason.rawValue)")
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

    // ---- last error, read by the app (VpnBridge.swift) -----------------

    static var containerURL: URL? {
        FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: appGroup)
    }

    private func saveLastError(_ message: String) {
        guard let url = Self.containerURL?.appendingPathComponent("last-error.txt") else { return }
        try? message.write(to: url, atomically: true, encoding: .utf8)
    }

    private func clearLastError() {
        guard let url = Self.containerURL?.appendingPathComponent("last-error.txt") else { return }
        try? FileManager.default.removeItem(at: url)
    }
}

struct TunnelError: LocalizedError {
    let message: String
    init(_ message: String) { self.message = message }
    var errorDescription: String? { message }
}
