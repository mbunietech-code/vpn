import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/l10n/app_text.dart';
import 'package:mvpn/screens/settings_screen.dart';

void main() {
  test('ovpn link is the subscription link with format=ovpn', () {
    expect(
      ovpnProfileUri('https://vpn.mbuniehub.com/sub/AbC123').toString(),
      'https://vpn.mbuniehub.com/sub/AbC123?format=ovpn',
    );
    // An existing query is kept, and an old format is replaced.
    expect(
      ovpnProfileUri(
        'https://x.test/sub/T?lang=sw&format=singbox',
      ).queryParameters,
      {'lang': 'sw', 'format': 'ovpn'},
    );
  });

  test('ovpn strings exist in every language', () {
    for (final code in ['en', 'sw', 'zh']) {
      final tr = AppText(code);
      for (final key in [
        'settings.ovpn',
        'settings.ovpnSub',
        'settings.ovpnNotReady',
        'settings.ovpnNoPlan',
        'settings.ovpnFailed',
      ]) {
        expect(tr.t(key), isNot(key), reason: '$code $key');
      }
    }
  });
}
