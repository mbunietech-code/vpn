import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/l10n/app_text.dart';
import 'package:mvpn/screens/iphone_screen.dart';
import 'package:mvpn/theme/mvpn_theme.dart';
import 'package:qr_flutter/qr_flutter.dart';

void main() {
  const sub = 'https://vpn.mbuniehub.com/sub/abc123';

  test('iPhone profile asks for the sing-box iOS config', () {
    final uri = iphoneProfileUri(sub);
    expect(uri.path, '/sub/abc123');
    expect(uri.queryParameters, {
      'format': 'singbox',
      'platform': 'ios',
      'protocol': 'auto',
    });
  });

  test('a link that already has a query keeps it, overriding the format', () {
    final uri = iphoneProfileUri('$sub?lang=sw&format=ovpn');
    expect(uri.queryParameters['lang'], 'sw');
    expect(uri.queryParameters['format'], 'singbox');
  });

  test('sing-box import link round-trips to the profile URL', () {
    final link = Uri.parse(singboxImportLink(sub));
    expect(link.scheme, 'sing-box');
    expect(link.host, 'import-remote-profile');
    expect(link.queryParameters['url'], iphoneProfileUri(sub).toString());
    expect(Uri.decodeComponent(link.fragment), 'Mbunie VPN');
  });

  for (final code in ['en', 'sw', 'zh']) {
    testWidgets('screen lays out on a small phone ($code)', (tester) async {
      tester.view.physicalSize = const Size(360 * 3, 640 * 3);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        AppTextScope(
          code: code,
          child: MaterialApp(
            theme: MvpnTheme.light,
            home: const IphoneScreen(subUrl: sub),
          ),
        ),
      );
      expect(find.byType(QrImageView), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
