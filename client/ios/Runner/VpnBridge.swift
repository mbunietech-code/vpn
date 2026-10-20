import Flutter
import NetworkExtension
import UIKit

/// iOS side of the channels the Dart mobile engine also uses on Android
/// (lib/services/tunnel/android_hiddify_engine.dart):
///   com.hiddify.app/method          setup / start / stop / device_info
///   com.hiddify.app/service.status  {"status": "Starting|Started|Stopping|Stopped"}
///   com.hiddify.app/service.alerts  {"status", "alert", "message"}
/// The tunnel itself runs in the PacketTunnel extension (libbox); this class
/// only manages its NETunnelProviderManager and passes the config on.
final class VpnBridge: NSObject {
  static let appGroup = "group.com.mbunie.mvpn"
  static let tunnelBundleId = "com.mbunie.mvpn.tunnel"

  private let method: FlutterMethodChannel
  private let status: FlutterEventChannel
  private let alerts: FlutterEventChannel
  private let statusSink = EventSink()
  private let alertsSink = EventSink()
  private var observer: NSObjectProtocol?
  private var manager: NETunnelProviderManager?
  /// True from "start" until the tunnel is up or has failed: a disconnect in
  /// that window is a failed start and is reported as an alert.
  private var starting = false

  init(messenger: FlutterBinaryMessenger) {
    let json = FlutterJSONMethodCodec.sharedInstance()
    method = FlutterMethodChannel(name: "com.hiddify.app/method", binaryMessenger: messenger)
    status = FlutterEventChannel(
      name: "com.hiddify.app/service.status", binaryMessenger: messenger, codec: json)
    alerts = FlutterEventChannel(
      name: "com.hiddify.app/service.alerts", binaryMessenger: messenger, codec: json)
    super.init()

    method.setMethodCallHandler { [weak self] call, result in
      self?.handle(call, result)
    }
    status.setStreamHandler(statusSink)
    alerts.setStreamHandler(alertsSink)
    statusSink.onListen = { [weak self] in
      self?.loadManager { manager in
        if let connection = manager?.connection { self?.report(connection.status) }
      }
    }
    observer = NotificationCenter.default.addObserver(
      forName: .NEVPNStatusDidChange, object: nil, queue: .main
    ) { [weak self] note in
      guard let connection = note.object as? NEVPNConnection else { return }
      self?.onStatus(connection)
    }
  }

  deinit {
    if let observer { NotificationCenter.default.removeObserver(observer) }
  }

  private func handle(_ call: FlutterMethodCall, _ result: @escaping FlutterResult) {
    switch call.method {
    case "setup":
      // The extension keeps its files in the App Group; nothing to prepare.
      result(nil)
    case "start":
      let args = call.arguments as? [String: Any]
      guard let path = args?["path"] as? String,
        let config = try? String(contentsOfFile: path, encoding: .utf8)
      else {
        result(FlutterError(code: "config", message: "VPN config file not found", details: nil))
        return
      }
      start(config: config, result: result)
    case "stop":
      starting = false
      manager?.connection.stopVPNTunnel()
      result(nil)
    case "device_info":
      result(Self.deviceInfo())
    default:
      result(FlutterMethodNotImplemented)
    }
  }

  // MARK: - Tunnel

  private func start(config: String, result: @escaping FlutterResult) {
    #if targetEnvironment(simulator)
      // The Simulator has no VPN service (saving fails with "IPC failed").
      result(
        FlutterError(
          code: "simulator",
          message: "The VPN cannot run in the iOS Simulator. Install Mbunie VPN on an iPhone.",
          details: nil))
      return
    #endif
    // Also on disk for the extension: iOS restarts it without start options
    // (after a crash, or from Settings → VPN).
    if let url = Self.sharedConfigURL {
      try? config.write(to: url, atomically: true, encoding: .utf8)
    }
    loadManager { [weak self] existing in
      guard let self else { return }
      let manager = existing ?? NETunnelProviderManager()
      let proto = (manager.protocolConfiguration as? NETunnelProviderProtocol)
        ?? NETunnelProviderProtocol()
      proto.providerBundleIdentifier = Self.tunnelBundleId
      proto.serverAddress = "Mbunie VPN"
      proto.disconnectOnSleep = false
      manager.protocolConfiguration = proto
      manager.localizedDescription = "Mbunie VPN"
      manager.isEnabled = true

      // The first save shows the system "Add VPN Configurations" prompt.
      manager.saveToPreferences { error in
        if let error {
          let denied = (error as NSError).domain == NEVPNErrorDomain
            && Self.describe(error).localizedCaseInsensitiveContains("denied")
          result(
            FlutterError(
              code: "permission",
              message: denied
                ? "VPN permission was not given. Tap Connect again and choose Allow."
                : "Could not save the VPN configuration: \(Self.describe(error))",
              details: nil))
          return
        }
        // Apple: reload after saving, or the first start fails.
        manager.loadFromPreferences { error in
          if let error {
            result(FlutterError(code: "load", message: Self.describe(error), details: nil))
            return
          }
          self.manager = manager
          do {
            self.starting = true
            try manager.connection.startVPNTunnel(options: [
              "configContent": config as NSString
            ])
            result(nil)
          } catch {
            self.starting = false
            result(FlutterError(code: "start", message: Self.describe(error), details: nil))
          }
        }
      }
    }
  }

  private func loadManager(_ done: @escaping (NETunnelProviderManager?) -> Void) {
    if let manager {
      done(manager)
      return
    }
    NETunnelProviderManager.loadAllFromPreferences { [weak self] managers, _ in
      let ours = managers?.first {
        ($0.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier
          == Self.tunnelBundleId
      }
      DispatchQueue.main.async {
        if let ours { self?.manager = ours }
        done(ours)
      }
    }
  }

  private func onStatus(_ connection: NEVPNConnection) {
    report(connection.status)
    switch connection.status {
    case .connected:
      starting = false
    case .disconnected, .invalid:
      guard starting else { return }
      starting = false
      reportStartError(connection)
    default:
      break
    }
  }

  private func report(_ status: NEVPNStatus) {
    let name: String
    switch status {
    case .connecting, .reasserting: name = "Starting"
    case .connected: name = "Started"
    case .disconnecting: name = "Stopping"
    default: name = "Stopped"
    }
    statusSink.send(["status": name])
  }

  private func reportStartError(_ connection: NEVPNConnection) {
    let send: (String) -> Void = { [weak self] message in
      self?.alertsSink.send(["status": "Stopped", "alert": "StartService", "message": message])
    }
    guard #available(iOS 16.0, *) else {
      send("The VPN stopped while starting.")
      return
    }
    connection.fetchLastDisconnectError { error in
      DispatchQueue.main.async {
        send(error.map(Self.describe) ?? "The VPN stopped while starting.")
      }
    }
  }

  // MARK: - Helpers

  private static var sharedConfigURL: URL? {
    FileManager.default
      .containerURL(forSecurityApplicationGroupIdentifier: appGroup)?
      .appendingPathComponent("config.json")
  }

  private static func describe(_ error: Error) -> String {
    let ns = error as NSError
    if let reason = ns.userInfo[NSLocalizedFailureReasonErrorKey] as? String { return reason }
    return ns.localizedDescription
  }

  private static func deviceInfo() -> [String: String] {
    var system = utsname()
    uname(&system)
    let machine = withUnsafeBytes(of: &system.machine) { raw in
      String(decoding: raw.prefix(while: { $0 != 0 }), as: UTF8.self)
    }
    let device = UIDevice.current
    return [
      "model": "Apple \(machine)",
      "os_version": "\(device.systemName) \(device.systemVersion)",
      // Stable while any app from this developer stays installed.
      "hardware_id": device.identifierForVendor?.uuidString ?? "",
    ]
  }
}

/// A FlutterStreamHandler that drops events while nobody listens.
private final class EventSink: NSObject, FlutterStreamHandler {
  private var sink: FlutterEventSink?
  var onListen: (() -> Void)?

  func onListen(withArguments _: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? {
    sink = events
    onListen?()
    return nil
  }

  func onCancel(withArguments _: Any?) -> FlutterError? {
    sink = nil
    return nil
  }

  func send(_ event: [String: Any]) {
    sink?(event)
  }
}
