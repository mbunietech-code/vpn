import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/main.dart';
import 'package:mvpn/services/mvpn_scope.dart';

void main() {
  testWidgets('app boots on Home in disconnected state', (tester) async {
    await tester.pumpWidget(const MvpnApp());

    expect(find.text('Mbunie VPN'), findsOneWidget);
    expect(find.text('Disconnected'), findsOneWidget);
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Servers'), findsOneWidget);
    expect(find.text('Settings'), findsOneWidget);
  });

  testWidgets('tapping connect moves to connecting then connected', (tester) async {
    await tester.pumpWidget(const MvpnApp());

    await tester.tap(find.byIcon(Icons.power_settings_new_rounded));
    await tester.pump();
    expect(find.text('Connecting…'), findsOneWidget);

    await tester.pump(const Duration(milliseconds: 1600));
    expect(find.text('Connected'), findsOneWidget);

    // stop the periodic stats timer
    final ctx = tester.element(find.byType(RootShell));
    MvpnScope.of(ctx).disconnect();
    await tester.pump();
  });
}
