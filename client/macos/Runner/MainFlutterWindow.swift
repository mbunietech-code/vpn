import Cocoa
import FlutterMacOS

class MainFlutterWindow: NSWindow {
  override func awakeFromNib() {
    let flutterViewController = FlutterViewController()
    let windowFrame = self.frame
    self.contentViewController = flutterViewController
    self.setFrame(windowFrame, display: true)

    RegisterGeneratedPlugins(registry: flutterViewController)

    // Closing only hides the window (DesktopTray); keep it so the menu bar
    // icon and the Dock icon can always bring it back.
    self.isReleasedWhenClosed = false

    super.awakeFromNib()
  }
}
