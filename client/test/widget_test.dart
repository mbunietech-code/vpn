import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('with no token the app lands on the auth screen', (tester) async {
    await tester.pumpWidget(const MvpnApp());
    await tester.pumpAndSettle();

    expect(find.text('Mbunie VPN'), findsOneWidget);
    expect(find.text('Tuma msimbo'), findsOneWidget);
  });
}
