import 'dart:io' show Platform;

/// Control-plane base URL.
///
/// Override at build time:  --dart-define=MVPN_API=https://cp.mbunievpn.com
class MvpnConfig {
  static const _override = String.fromEnvironment('MVPN_API');

  static String get apiBase {
    if (_override.isNotEmpty) return _override;
    // Android emulator reaches the host loopback via 10.0.2.2
    try {
      if (Platform.isAndroid) return 'http://10.0.2.2:8000';
    } catch (_) {}
    return 'http://localhost:8000';
  }

  static const appVersion = 'v1.0.0';

  /// Payment providers surfaced in the app (control plane also enforces).
  static const providers = <String, String>{
    'stripe': 'Alipay · WeChat · Card',
    'cryptomus': 'Crypto (USDT)',
  };
}
