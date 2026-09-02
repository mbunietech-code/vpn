import 'package:flutter/widgets.dart';

import 'app_state.dart';

/// Single app-wide state holder (auth + subscription + VPN), no extra packages.
class MvpnScope extends InheritedNotifier<AppState> {
  const MvpnScope({
    super.key,
    required AppState state,
    required super.child,
  }) : super(notifier: state);

  static AppState of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<MvpnScope>();
    assert(scope != null, 'MvpnScope not found in widget tree');
    return scope!.notifier!;
  }

  static AppState read(BuildContext context) {
    return context.getInheritedWidgetOfExactType<MvpnScope>()!.notifier!;
  }
}
