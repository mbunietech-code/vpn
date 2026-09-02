import '../../models.dart';

enum EngineStatus { down, starting, up, error }

class EngineReport {
  const EngineReport(this.status, {this.message});
  final EngineStatus status;
  final String? message;
}

/// Cumulative byte counters + instantaneous speed (bytes/sec).
class EngineTraffic {
  const EngineTraffic({
    required this.upBytes,
    required this.downBytes,
    required this.upBps,
    required this.downBps,
  });

  final int upBytes;
  final int downBytes;
  final int upBps;
  final int downBps;

  static const zero = EngineTraffic(upBytes: 0, downBytes: 0, upBps: 0, downBps: 0);
}

/// A VPN tunnel implementation.
///
///  - [SingboxEngine]   — real tunnel via the bundled sing-box process (desktop)
///  - [SimulatedEngine] — animated placeholder (mobile / unsupported / demo)
abstract class TunnelEngine {
  /// True when traffic is really being tunnelled.
  bool get isReal;

  Stream<EngineReport> get reports;
  Stream<EngineTraffic> get traffic;

  Future<void> start({
    required String subUrl,
    required String apiToken,
    required String apiBase,
    ProtocolPref pref = ProtocolPref.auto,
  });

  Future<void> stop();

  void dispose();
}
