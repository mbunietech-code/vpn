import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;
import 'package:mvpn/services/api_client.dart';
import 'package:mvpn/services/app_state.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  /// Fake control plane: OTP -> token, then subscription flips to active
  /// after dev/pay, and /sub/{token} returns a one-node bundle.
  http.Client fakeCp() {
    var paid = false;
    return MockClient((req) async {
      final path = req.url.path;
      if (path == '/api/auth/otp/request') {
        return http.Response(jsonEncode({'channel': 'sms', 'debug_code': '123456'}), 200);
      }
      if (path == '/api/auth/otp/verify') {
        return http.Response(jsonEncode({'token': 'tok_1', 'user': {'id': 1}}), 200);
      }
      if (path == '/api/dev/pay') {
        paid = true;
        return http.Response(jsonEncode({'status': 'activated'}), 200);
      }
      if (path == '/api/subscription') {
        return http.Response(
          jsonEncode(paid
              ? {
                  'status': 'active',
                  'plan': 'm1',
                  'expires_at': '2026-12-01T00:00:00Z',
                  'sub_url': 'http://cp.test/sub/abc',
                  'awaiting_payment': false,
                }
              : {'status': 'none'}),
          200,
        );
      }
      if (path == '/sub/abc') {
        final link =
            'vless://u@hk1.test:443?type=tcp&security=reality&sni=x&pbk=k&sid=1#MVPN%20Hong%20Kong%201';
        return http.Response(base64.encode(utf8.encode(link)), 200);
      }
      return http.Response('not found', 404);
    });
  }

  test('gate walks needsAuth -> needsPlan -> ready through pay', () async {
    final api = ApiClient(baseUrl: 'http://cp.test', httpClient: fakeCp());
    final state = AppState(api: api);

    await state.bootstrap();
    expect(state.gate, AuthGate.needsAuth);

    await state.requestOtp('+255700000000');
    await state.verifyOtp('123456');
    expect(state.gate, AuthGate.needsPlan);
    expect(state.subStatus, 'none');

    await state.devCompletePayment();
    expect(state.gate, AuthGate.ready);
    expect(state.subStatus, 'active');
    expect(state.vpn.nodes.first.name, 'Hong Kong 1');
  });
}
