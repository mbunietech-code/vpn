import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../models.dart';

enum EngineStatus { down, starting, up, error }

class EngineReport {
  const EngineReport(this.status, {this.message, this.external = false});
  final EngineStatus status;
  final String? message;

  /// [EngineStatus.down] because the user turned the VPN off outside the
  /// app (Quick Settings tile, Android settings, another VPN took over):
  /// not a drop to reconnect from.
  final bool external;
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

  /// Switch the running tunnel's server: a member of the config's "proxy"
  /// selector ("auto", or e.g. "Kuala Lumpur 1 · OpenVPN"). False when the
  /// config does not offer it.
  Future<bool> selectServer(String tag);

  void dispose();
}

/// [TunnelEngine.selectServer] for engines that run sing-box with its Clash
/// API on 127.0.0.1:9095 (the server-built config sets it).
Future<bool> clashSelectServer(String tag) async {
  try {
    final res = await http
        .put(
          Uri.parse('http://127.0.0.1:9095/proxies/proxy'),
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode({'name': tag}),
        )
        .timeout(const Duration(seconds: 5));
    return res.statusCode == 204 || res.statusCode == 200;
  } catch (_) {
    return false;
  }
}
