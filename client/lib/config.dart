import 'dart:io' show Platform;

/// Control-plane configuration.
///
/// Override at build time:
///   --dart-define=MVPN_API=https://vpn.mbuniehub.com
///   --dart-define=MVPN_API_FALLBACKS=https://vpn2.mbuniehub.com,https://198.51.100.7
class MvpnConfig {
  static const _override = String.fromEnvironment('MVPN_API');
  static const _fallbacks = String.fromEnvironment('MVPN_API_FALLBACKS');

  /// Production control plane.
  static const _prod = 'https://vpn.mbuniehub.com';

  static String get apiBase => apiBases.first;

  /// Ordered list the client tries in turn when one is unreachable
  /// (domain blocked from inside China, DNS poisoning, etc.) — FR-CN-09.
  static List<String> get apiBases {
    final list = <String>[];

    if (_override.isNotEmpty) {
      list.add(_override);
    } else {
      var local = 'http://localhost:8000';
      try {
        if (Platform.isAndroid) local = 'http://10.0.2.2:8000';
      } catch (_) {}
      // Debug builds still default to prod so a sideloaded APK "just works";
      // point at local with --dart-define when developing against WAMP.
      list.add(_prod);
      list.add(local);
    }

    for (final f in _fallbacks.split(',')) {
      final t = f.trim();
      if (t.isNotEmpty) list.add(t);
    }
    return list;
  }

  static const appVersion = 'v1.0.0';

  /// Payment providers surfaced in the app (control plane also enforces).
  static const providers = <String, String>{
    'stripe': 'Alipay · WeChat · Card',
    'cryptomus': 'Crypto (USDT)',
  };
}
