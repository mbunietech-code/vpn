import Cocoa
import FlutterMacOS

@main
class AppDelegate: FlutterAppDelegate {
  override func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
    return true
  }

  override func applicationSupportsSecureRestorableState(_ app: NSApplication) -> Bool {
    return true
  }

  // The engine runs as root through the helper, so it outlives the app
  // unless we stop it here (quitting must not leave the tunnel up).
  override func applicationWillTerminate(_ notification: Notification) {
    let helper = "/Library/PrivilegedHelperTools/com.mbunie.mvpn/mvpn-engine"
    guard FileManager.default.fileExists(atPath: helper) else { return }
    let p = Process()
    p.executableURL = URL(fileURLWithPath: "/usr/bin/sudo")
    p.arguments = ["-n", helper, "stop"]
    try? p.run()
    p.waitUntilExit()
  }
}
