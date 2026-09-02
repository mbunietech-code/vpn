import 'package:flutter/widgets.dart';

import 'vpn_controller.dart';

/// Lightweight DI without extra packages.
class MvpnScope extends InheritedNotifier<VpnController> {
  const MvpnScope({
    super.key,
    required VpnController controller,
    required super.child,
  }) : super(notifier: controller);

  static VpnController of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<MvpnScope>();
    assert(scope != null, 'MvpnScope not found in widget tree');
    return scope!.notifier!;
  }

  /// Read without subscribing to rebuilds.
  static VpnController read(BuildContext context) {
    return (context.getInheritedWidgetOfExactType<MvpnScope>()!).notifier!;
  }
}
