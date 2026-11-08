import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mvpn/models.dart';
import 'package:mvpn/services/api_client.dart';
import 'package:mvpn/services/app_state.dart';
import 'package:mvpn/services/tunnel/tunnel_engine.dart';
import 'package:mvpn/services/vpn_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _IdleEngine implements TunnelEngine {
  @override
  bool get isReal => false;
  @override
  Stream<EngineReport> get reports => const Stream.empty();
  @override
  Stream<EngineTraffic> get traffic => const Stream.empty();
  @override
  Future<void> start({
    required String subUrl,
    required String apiToken,
    required String apiBase,
    ProtocolPref pref = ProtocolPref.auto,
  }) async {}
  @override
  Future<void> stop() async {}
  @override
  Future<bool> selectServer(String tag) async => true;
  @override
  void dispose() {}
}

/// The server can't be reached at all (Cloudflare blocked in China).
http.Client unreachable() =>
    MockClient((_) async => throw http.ClientException('Connection reset'));

AppState newState() => AppState(
  api: ApiClient(baseUrl: 'http://cp.test', httpClient: unreachable()),
  vpn: VpnController(engine: _IdleEngine()),
);

void main() {
  test('signed in with a saved active plan: starts from it, not the splash', () async {
    final link =
        'vless://u@kl1.test:443?type=tcp&security=reality&sni=x&pbk=k&sid=1#MVPN%20Kuala%20Lumpur%201';
    SharedPreferences.setMockInitialValues({
      'mvpn_token': 'tok_1',
      'mvpn_last_subscription': jsonEncode({
        'status': 'active',
        'plan': 'y1',
        'expires_at': DateTime.now().add(const Duration(days: 30)).toIso8601String(),
        'sub_url': 'http://cp.test/sub/abc',
      }),
      'mvpn_last_nodes': base64.encode(utf8.encode(link)),
    });
    final state = newState();

    await state.bootstrap();

    expect(state.gate, AuthGate.ready);
    expect(state.offline, isTrue);
    expect(state.vpn.nodes.first.name, 'Kuala Lumpur 1');
    state.dispose();
  });

  test('signed in, nothing saved: shows the can\'t-reach screen', () async {
    SharedPreferences.setMockInitialValues({'mvpn_token': 'tok_1'});
    final state = newState();

    await state.bootstrap();

    expect(state.gate, AuthGate.offline);
    state.dispose();
  });

  test('an expired saved plan is not used', () async {
    SharedPreferences.setMockInitialValues({
      'mvpn_token': 'tok_1',
      'mvpn_last_subscription': jsonEncode({
        'status': 'active',
        'plan': 'm1',
        'expires_at': DateTime.now().subtract(const Duration(days: 1)).toIso8601String(),
        'sub_url': 'http://cp.test/sub/abc',
      }),
    });
    final state = newState();

    await state.bootstrap();

    expect(state.gate, AuthGate.offline);
    state.dispose();
  });
}
