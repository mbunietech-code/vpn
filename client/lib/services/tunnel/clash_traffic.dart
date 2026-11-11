import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import 'tunnel_engine.dart';

/// Live traffic from sing-box's Clash API (127.0.0.1:9095, set by the
/// server config on every platform), polled once a second.
///
/// Only while the app is on screen: in the background (window hidden to the
/// tray / menu bar, phone screen off, app switched away) nobody sees the
/// numbers, and a request every second would keep waking the CPU. The tunnel
/// itself is not affected. Totals are the engine's own counters, so nothing
/// is lost while paused.
class ClashTraffic {
  ClashTraffic(this._http, this._out);

  /// Whether a window of the app is showing. Set by AppState (app lifecycle)
  /// and DesktopTray (hide to tray / menu bar).
  static final visible = ValueNotifier<bool>(true);

  static const _api = 'http://127.0.0.1:9095/connections';

  final http.Client _http;
  final StreamController<EngineTraffic> _out;

  bool _running = false;
  Timer? _timer;
  int? _lastUp;
  int _lastDown = 0;
  DateTime _lastPoll = DateTime.now();

  bool get polling => _timer != null;

  /// The tunnel is up: report traffic whenever the app is visible.
  void start() {
    if (_running) return;
    _running = true;
    visible.addListener(_sync);
    _sync();
  }

  /// The tunnel is down.
  void stop() {
    if (!_running) return;
    _running = false;
    visible.removeListener(_sync);
    _sync();
  }

  void _sync() {
    final want = _running && visible.value;
    if (want && _timer == null) {
      _lastUp = null; // first sample after a pause only sets the baseline
      _timer = Timer.periodic(const Duration(seconds: 1), (_) => _poll());
      unawaited(_poll());
    } else if (!want && _timer != null) {
      _timer!.cancel();
      _timer = null;
    }
  }

  Future<void> _poll() async {
    try {
      final r = await _http
          .get(Uri.parse(_api))
          .timeout(const Duration(seconds: 3));
      if (r.statusCode != 200 || _timer == null || _out.isClosed) return;
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      final up = (j['uploadTotal'] as num?)?.toInt() ?? 0;
      final down = (j['downloadTotal'] as num?)?.toInt() ?? 0;
      final now = DateTime.now();
      final dt = now.difference(_lastPoll).inMilliseconds / 1000.0;
      final lastUp = _lastUp;
      int rate(int now, int before) =>
          lastUp == null || dt <= 0 ? 0 : ((now - before) / dt).round().clamp(0, 1 << 34);
      _out.add(
        EngineTraffic(
          upBytes: up,
          downBytes: down,
          upBps: rate(up, lastUp ?? up),
          downBps: rate(down, _lastDown),
        ),
      );
      _lastUp = up;
      _lastDown = down;
      _lastPoll = now;
    } catch (_) {
      /* transient */
    }
  }
}
