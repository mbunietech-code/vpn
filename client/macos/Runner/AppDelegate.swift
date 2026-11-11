import Cocoa
import FlutterMacOS

@main
class AppDelegate: FlutterAppDelegate {
  /// Another copy was already running; this one only handed over to it.
  private var isDuplicate = false

  // One copy only (e.g. one opened from the DMG and one from Applications):
  // a second copy brings the first one's window back and exits, so there is
  // never a second engine.
  override func applicationWillFinishLaunching(_ notification: Notification) {
    let me = NSRunningApplication.current
    guard let id = Bundle.main.bundleIdentifier,
          let other = NSRunningApplication.runningApplications(withBundleIdentifier: id)
            .first(where: { $0.processIdentifier != me.processIdentifier && !$0.isTerminated }),
          let url = other.bundleURL
    else {
      super.applicationWillFinishLaunching(notification)
      return
    }
    isDuplicate = true
    // Opening a running app sends it a "reopen", which shows its window.
    // Wait here so this copy never starts its own UI or engine.
    let config = NSWorkspace.OpenConfiguration()
    config.activates = true
    let done = DispatchSemaphore(value: 0)
    NSWorkspace.shared.openApplication(at: url, configuration: config) { _, _ in done.signal() }
    _ = done.wait(timeout: .now() + 5)
    exit(0) // not terminate(): applicationWillTerminate would stop its tunnel
  }

  // Closing the window hides it (DesktopTray, window_manager); the app and
  // the VPN keep running in the menu bar and the Dock.
  override func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
    return false
  }

  // Dock icon (or opening the app again) while the window is hidden.
  override func applicationShouldHandleReopen(_ sender: NSApplication, hasVisibleWindows flag: Bool) -> Bool {
    if !flag, let window = mainFlutterWindow {
      window.makeKeyAndOrderFront(nil)
      NSApp.activate(ignoringOtherApps: true)
    }
    return true
  }

  override func applicationSupportsSecureRestorableState(_ app: NSApplication) -> Bool {
    return true
  }

  // The engine runs as root through the helper, so it outlives the app
  // unless we stop it here (quitting must not leave the tunnel up).
  override func applicationWillTerminate(_ notification: Notification) {
    if isDuplicate { return }
    let helper = "/Library/PrivilegedHelperTools/com.mbunie.mvpn/mvpn-engine"
    guard FileManager.default.fileExists(atPath: helper) else { return }
    let p = Process()
    p.executableURL = URL(fileURLWithPath: "/usr/bin/sudo")
    p.arguments = ["-n", helper, "stop"]
    try? p.run()
    p.waitUntilExit()
  }
}
