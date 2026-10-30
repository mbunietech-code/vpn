import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/models.dart';
import 'package:mvpn/services/tunnel/tunnel_engine.dart';
import 'package:mvpn/services/vpn_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Engine whose reports the test pushes by hand.
class _FakeEngine implements TunnelEngine {
  final _reports = StreamController<EngineReport>.broadcast();
  int starts = 0;

  void report(EngineReport r) => _reports.add(r);

  @override
  bool get isReal => false;
  @override
  Stream<EngineReport> get reports => _reports.stream;
  @override
  Stream<EngineTraffic> get traffic => const Stream.empty();
  @override
  Future<void> start({
    required String subUrl,
    required String apiToken,
    required String apiBase,
    ProtocolPref pref = ProtocolPref.auto,
  }) async => starts++;
  @override
  Future<void> stop() async {}
  @override
  Future<bool> selectServer(String tag) async => true;
  @override
  void dispose() {}
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('a tunnel already up when the app opens shows as connected', () async {
    final engine = _FakeEngine();
    final vpn = VpnController(engine: engine);
    engine.report(const EngineReport(EngineStatus.up));
    await Future<void>.delayed(Duration.zero);
    expect(vpn.status, VpnStatus.connected);
    expect(engine.starts, 0);
  });

  test('turned off outside the app: stays off', () async {
    final engine = _FakeEngine();
    final vpn = VpnController(engine: engine);
    engine.report(const EngineReport(EngineStatus.up));
    engine.report(const EngineReport(EngineStatus.down, external: true));
    await Future<void>.delayed(Duration.zero);
    expect(vpn.status, VpnStatus.disconnected);
  });

  test('a drop the user did not ask for reconnects', () async {
    final engine = _FakeEngine();
    final vpn = VpnController(engine: engine);
    engine.report(const EngineReport(EngineStatus.up));
    engine.report(const EngineReport(EngineStatus.down));
    await Future<void>.delayed(Duration.zero);
    expect(vpn.status, VpnStatus.reconnecting);
  });
}
