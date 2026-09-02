import 'dart:async';
import 'dart:math';

import '../../models.dart';
import 'tunnel_engine.dart';

/// Placeholder engine — no real tunnel. Keeps the UI fully exercised on
/// platforms where the sing-box process engine isn't wired yet (mobile).
class SimulatedEngine implements TunnelEngine {
  @override
  bool get isReal => false;

  final _reports = StreamController<EngineReport>.broadcast();
  final _traffic = StreamController<EngineTraffic>.broadcast();
  final _rng = Random();

  Timer? _tick;
  int _up = 0, _down = 0;

  @override
  Stream<EngineReport> get reports => _reports.stream;

  @override
  Stream<EngineTraffic> get traffic => _traffic.stream;

  @override
  Future<void> start({
    required String subUrl,
    required String apiToken,
    required String apiBase,
    ProtocolPref pref = ProtocolPref.auto,
  }) async {
    _reports.add(const EngineReport(EngineStatus.starting));
    await Future<void>.delayed(const Duration(milliseconds: 1200));
    _up = 0;
    _down = 0;
    _reports.add(const EngineReport(EngineStatus.up, message: 'demo'));
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      final d = (40 + _rng.nextDouble() * 380) * 125000 ~/ 1; // ~Mbps→Bps
      final u = (10 + _rng.nextDouble() * 90) * 125000 ~/ 1;
      _down += d;
      _up += u;
      _traffic.add(EngineTraffic(upBytes: _up, downBytes: _down, upBps: u, downBps: d));
    });
  }

  @override
  Future<void> stop() async {
    _tick?.cancel();
    _tick = null;
    _reports.add(const EngineReport(EngineStatus.down));
  }

  @override
  void dispose() {
    _tick?.cancel();
    _reports.close();
    _traffic.close();
  }
}
