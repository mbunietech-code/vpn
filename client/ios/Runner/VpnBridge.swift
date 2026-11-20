import Flutter
import Foundation
import NetworkExtension

/// Flutter <-> Network Extension bridge for ios_engine.dart.
///
/// Method channel `com.mbunie.mvpn/vpn`:
///   appGroupPath() -> String     the App Group container (config + rule sets)
///   start({configPath})          saves the VPN profile on first use (iOS asks
///                                "Add VPN Configurations"), then starts it
///   stop()
/// Event channel `com.mbunie.mvpn/vpn.status`:
///   {status: connecting|up|down|error, message?, external?}
final class VpnBridge: NSObject, FlutterPlugin, FlutterStreamHandler {
    static let appGroup = "group.com.mbunie.mvpn"
    static let tunnelBundleId = "com.mbunie.mvpn.tunnel"

    private var manager: NETunnelProviderManager?
    private var sink: FlutterEventSink?
    private var observer: NSObjectProtocol?
    /// stop() was asked for: the next "disconnected" is ours, not a failure.
    private var stopping = false

    static func register(with registrar: FlutterPluginRegistrar) {
        let bridge = VpnBridge()
        let method = FlutterMethodChannel(name: "com.mbunie.mvpn/vpn", binaryMessenger: registrar.messenger())
        registrar.addMethodCallDelegate(bridge, channel: method)
        let events = FlutterEventChannel(name: "com.mbunie.mvpn/vpn.status", binaryMessenger: registrar.messenger())
        events.setStreamHandler(bridge)
    }

    private static var container: URL? {
        FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: appGroup)
    }

    func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
        switch call.method {
        case "appGroupPath":
            result(Self.container?.path)
        case "start":
            let args = call.arguments as? [String: Any]
            guard let path = args?["configPath"] as? String else {
                result(FlutterError(code: "args", message: "configPath is required", details: nil))
                return
            }
            Task { @MainActor in
                do {
                    try await self.start(configPath: path)
                    result(nil)
                } catch {
                    result(FlutterError(code: "start", message: error.localizedDescription, details: nil))
                }
            }
        case "stop":
            Task { @MainActor in
                self.stopping = true
                (try? await self.loadManager())?.connection.stopVPNTunnel()
                result(nil)
            }
        default:
            result(FlutterMethodNotImplemented)
        }
    }

    // ---- tunnel -------------------------------------------------------------

    @MainActor
    private func loadManager() async throws -> NETunnelProviderManager? {
        if let manager { return manager }
        let all = try await NETunnelProviderManager.loadAllFromPreferences()
        manager = all.first {
            ($0.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier == Self.tunnelBundleId
        }
        if manager != nil { observe() }
        return manager
    }

    @MainActor
    private func start(configPath: String) async throws {
        let m: NETunnelProviderManager
        if let existing = try await loadManager() {
            m = existing
        } else {
            m = NETunnelProviderManager()
            manager = m
            observe()
        }
        let proto = (m.protocolConfiguration as? NETunnelProviderProtocol) ?? NETunnelProviderProtocol()
        proto.providerBundleIdentifier = Self.tunnelBundleId
        proto.serverAddress = "Mbunie VPN"
        m.protocolConfiguration = proto
        m.localizedDescription = "Mbunie VPN"
        if !m.isEnabled || m.connection.status == .invalid {
            m.isEnabled = true
            // First time: iOS shows "Add VPN Configurations". Declining throws.
            try await m.saveToPreferences()
            try await m.loadFromPreferences()
        }
        if let url = Self.container?.appendingPathComponent("last-error.txt") {
            try? FileManager.default.removeItem(at: url)
        }
        stopping = false
        try m.connection.startVPNTunnel(options: ["configPath": configPath as NSString])
    }

    // ---- status -------------------------------------------------------------

    private func observe() {
        guard observer == nil, let m = manager else { return }
        observer = NotificationCenter.default.addObserver(
            forName: .NEVPNStatusDidChange, object: m.connection, queue: .main
        ) { [weak self] _ in
            self?.report()
        }
    }

    private func report() {
        guard let m = manager else {
            sink?(["status": "down", "external": false])
            return
        }
        switch m.connection.status {
        case .connecting, .reasserting:
            sink?(["status": "connecting"])
        case .connected:
            sink?(["status": "up"])
        case .disconnected, .invalid:
            let ours = stopping
            stopping = false
            if ours {
                sink?(["status": "down", "external": false])
                return
            }
            reportDisconnect(m.connection)
        case .disconnecting:
            break
        @unknown default:
            break
        }
    }

    /// Why the tunnel went down: the extension's own error text first, then
    /// what iOS recorded; neither means the user turned it off in Settings.
    private func reportDisconnect(_ connection: NEVPNConnection) {
        if let url = Self.container?.appendingPathComponent("last-error.txt"),
           let text = try? String(contentsOf: url, encoding: .utf8), !text.isEmpty
        {
            try? FileManager.default.removeItem(at: url)
            sink?(["status": "error", "message": text])
            return
        }
        if #available(iOS 16.0, *) {
            connection.fetchLastDisconnectError { [weak self] error in
                DispatchQueue.main.async {
                    if let error {
                        self?.sink?(["status": "error", "message": error.localizedDescription])
                    } else {
                        self?.sink?(["status": "down", "external": true])
                    }
                }
            }
        } else {
            sink?(["status": "down", "external": true])
        }
    }

    func onListen(withArguments _: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? {
        sink = events
        // A reopened app learns straight away whether the tunnel is still up.
        Task { @MainActor in
            _ = try? await self.loadManager()
            if let m = self.manager {
                switch m.connection.status {
                case .connected: events(["status": "up"])
                case .connecting, .reasserting: events(["status": "connecting"])
                default: break
                }
            }
        }
        return nil
    }

    func onCancel(withArguments _: Any?) -> FlutterError? {
        sink = nil
        return nil
    }
}
