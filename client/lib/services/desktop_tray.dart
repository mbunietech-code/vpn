import 'dart:async';
import 'dart:io';

import 'package:tray_manager/tray_manager.dart';
import 'package:window_manager/window_manager.dart';

import '../l10n/app_text.dart';
import '../models.dart';
import 'app_state.dart';

/// Windows: keeps Mbunie VPN in the notification area. Closing the window
/// only hides it and the VPN keeps running; the tray icon shows whether the
/// VPN is on, and its menu connects, disconnects or quits.
class DesktopTray with TrayListener, WindowListener {
  DesktopTray(this.state);

  final AppState state;

  static bool get supported => Platform.isWindows;

  // The app ships in English only.
  static final _tr = AppText('en');

  VpnStatus? _shown;
  AuthGate? _shownGate;

  /// Call after `windowManager.ensureInitialized()`.
  Future<void> init() async {
    await windowManager.setPreventClose(true);
    windowManager.addListener(this);
    trayManager.addListener(this);
    state.beforeExit = _removeIcon;
    state.addListener(_refresh);
    await _refresh();
  }

  Future<void> _refresh() async {
    final status = state.vpn.status;
    if (status == _shown && state.gate == _shownGate) return;
    _shown = status;
    _shownGate = state.gate;

    final on = status == VpnStatus.connected;
    final busy = status == VpnStatus.connecting || status == VpnStatus.reconnecting;
    final label = _tr.t(switch (status) {
      VpnStatus.connected => 'home.connected',
      VpnStatus.connecting => 'home.connecting',
      VpnStatus.reconnecting => 'home.reconnecting',
      VpnStatus.error || VpnStatus.disconnected => 'home.disconnected',
    });
    try {
      await trayManager.setIcon(on ? 'assets/tray/on.ico' : 'assets/tray/off.ico');
      await trayManager.setToolTip('Mbunie VPN · $label');
      await trayManager.setContextMenu(
        Menu(
          items: [
            MenuItem(key: 'status', label: label, disabled: true),
            MenuItem.separator(),
            MenuItem(
              key: 'toggle',
              label: _tr.t(on || busy ? 'tray.disconnect' : 'tray.connect'),
            ),
            MenuItem(key: 'open', label: _tr.t('tray.open')),
            MenuItem.separator(),
            MenuItem(key: 'quit', label: _tr.t('tray.quit')),
          ],
        ),
      );
    } catch (_) {
      // A missing tray (e.g. Explorer restarting) must not break the app.
    }
  }

  Future<void> _show() async {
    if (await windowManager.isMinimized()) await windowManager.restore();
    await windowManager.show();
    await windowManager.focus();
  }

  Future<void> _quit() async {
    if (state.vpn.status != VpnStatus.disconnected) await state.vpn.disconnect();
    await _removeIcon();
    await windowManager.setPreventClose(false);
    await windowManager.destroy();
  }

  Future<void> _removeIcon() async {
    try {
      await trayManager.destroy();
    } catch (_) {}
  }

  // ---- tray ------------------------------------------------------------

  @override
  void onTrayIconMouseDown() => unawaited(_show());

  @override
  void onTrayIconRightMouseDown() => unawaited(trayManager.popUpContextMenu());

  @override
  void onTrayMenuItemClick(MenuItem menuItem) {
    switch (menuItem.key) {
      case 'toggle':
        // Not signed in or no active plan: the window says what to do.
        if (state.gate != AuthGate.ready) {
          unawaited(_show());
        } else {
          unawaited(state.vpn.toggle());
        }
      case 'open':
        unawaited(_show());
      case 'quit':
        unawaited(_quit());
    }
  }

  // ---- window ----------------------------------------------------------

  /// The close button hides the window to the tray; Quit is in the menu.
  @override
  void onWindowClose() => unawaited(windowManager.hide());
}
