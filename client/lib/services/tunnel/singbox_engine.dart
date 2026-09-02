import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

import '../../models.dart';
import 'tunnel_engine.dart';

/// Real tunnel for Windows / Linux / macOS.
///
/// Runs the bundled `sing-box` as a child process against a config fetched
/// from the control plane (`/sub/{token}?format=singbox`). Reads live traffic
/// from sing-box's Clash API. Needs elevated privileges to create the TUN
/// device (Administrator on Windows; root / CAP_NET_ADMIN on Linux; root on
/// macOS) — a permission failure is surfaced as an [EngineStatus.error].
class SingboxEngine implements TunnelEngine {
  @override
  bool get isReal => true;

  static bool get supported =>
      Platform.isWindows || Platform.isLinux || Platform.isMacOS;

  static const _clashApi = 'http://127.0.0.1:9095';

  final _reports = StreamController<EngineReport>.broadcast();
  final _traffic = StreamController<EngineTraffic>.broadcast();
  final _http = http.Client();

  Process? _proc;
  Timer? _poll;
  int _lastUp = 0, _lastDown = 0;
  DateTime _lastPoll = DateTime.now();
  final _errBuf = <String>[];

  @override
  Stream<EngineReport> get reports => _reports.stream;

  @override
  Stream<EngineTraffic> get traffic => _traffic.stream;

  // ---- lifecycle --------------------------------------------------------

  @override
  Future<void> start({
    required String subUrl,
    required String apiToken,
    required String apiBase,
    ProtocolPref pref = ProtocolPref.auto,
  }) async {
    if (!supported) {
      throw StateError('SingboxEngine is desktop-only');
    }
    _reports.add(const EngineReport(EngineStatus.starting));

    final support = await getApplicationSupportDirectory();
    final dir = Directory('${support.path}${Platform.pathSeparator}engine')
      ..createSync(recursive: true);

    final bin = await _ensureBinary(apiBase, dir);
    final cfg = await _fetchConfig(subUrl, apiToken, dir, pref);

    _errBuf.clear();
    final proc = await Process.start(
      bin.path,
      ['run', '-c', cfg.path, '-D', dir.path, '--disable-color'],
      workingDirectory: dir.path,
    );
    _proc = proc;

    proc.stdout
        .transform(utf8.decoder)
        .transform(const LineSplitter())
        .listen(_onLog);
    proc.stderr
        .transform(utf8.decoder)
        .transform(const LineSplitter())
        .listen(_onLog);

    unawaited(proc.exitCode.then((code) {
      _stopPolling();
      if (code == 0) {
        _reports.add(const EngineReport(EngineStatus.down));
      } else {
        _reports.add(EngineReport(EngineStatus.error, message: _diagnose(code)));
      }
    }));

    await _waitUntilUp();
    _reports.add(const EngineReport(EngineStatus.up));
    _startPolling();
  }

  @override
  Future<void> stop() async {
    _stopPolling();
    final p = _proc;
    _proc = null;
    if (p == null) return;
    p.kill(ProcessSignal.sigterm);
    try {
      await p.exitCode.timeout(const Duration(seconds: 5));
    } on TimeoutException {
      p.kill(ProcessSignal.sigkill);
    }
    _reports.add(const EngineReport(EngineStatus.down));
  }

  @override
  void dispose() {
    _stopPolling();
    _proc?.kill(ProcessSignal.sigkill);
    _http.close();
    _reports.close();
    _traffic.close();
  }

  // ---- binary ---------------------------------------------------------

  static String get _binTarget {
    if (Platform.isWindows) return 'windows-amd64';
    if (Platform.isLinux) return 'linux-amd64';
    // macOS: detect Apple Silicon vs Intel
    try {
      final arch = Process.runSync('uname', ['-m']).stdout.toString().trim();
      return arch == 'arm64' ? 'darwin-arm64' : 'darwin-amd64';
    } catch (_) {
      return 'darwin-arm64';
    }
  }

  Future<File> _ensureBinary(String apiBase, Directory dir) async {
    final name = Platform.isWindows ? 'sing-box.exe' : 'sing-box';
    final file = File('${dir.path}${Platform.pathSeparator}$name');
    if (file.existsSync() && file.lengthSync() > 1024 * 1024) return file;

    final url = '$apiBase/bin/sing-box/$_binTarget';
    final res = await _http.get(Uri.parse(url));
    if (res.statusCode != 200) {
      throw StateError(
        'sing-box engine haijapatikana ($url → ${res.statusCode}). '
        'Pakia binary kwenye control-plane (storage/app/bin/).',
      );
    }
    await file.writeAsBytes(res.bodyBytes, flush: true);
    if (!Platform.isWindows) {
      await Process.run('chmod', ['+x', file.path]);
    }
    return file;
  }

  // ---- config -------------------------------------------------------

  Future<File> _fetchConfig(
      String subUrl, String token, Directory dir, ProtocolPref pref) async {
    final platform = Platform.isWindows
        ? 'windows'
        : Platform.isMacOS
            ? 'macos'
            : 'linux';
    final protocol = switch (pref) {
      ProtocolPref.vlessReality => 'reality',
      ProtocolPref.hysteria2 => 'hysteria2',
      ProtocolPref.auto => 'auto',
    };
    final sep = subUrl.contains('?') ? '&' : '?';
    final uri = Uri.parse(
        '$subUrl${sep}format=singbox&platform=$platform&protocol=$protocol');

    final res = await _http.get(uri, headers: {'Authorization': 'Bearer $token'});
    if (res.statusCode != 200) {
      throw StateError('Config haijapatikana (${res.statusCode})');
    }
    // sanity check it's valid JSON
    jsonDecode(res.body);

    final f = File('${dir.path}${Platform.pathSeparator}config.json');
    await f.writeAsString(res.body, flush: true);
    return f;
  }

  // ---- readiness + logs --------------------------------------------

  void _onLog(String line) {
    if (line.trim().isEmpty) return;
    _errBuf.add(line);
    if (_errBuf.length > 40) _errBuf.removeAt(0);

    final l = line.toLowerCase();
    if (l.contains('operation not permitted') ||
        l.contains('access is denied') ||
        l.contains('permission denied') && l.contains('tun')) {
      _reports.add(EngineReport(EngineStatus.error, message: _privilegeHint()));
    }
  }

  Future<void> _waitUntilUp() async {
    final deadline = DateTime.now().add(const Duration(seconds: 20));
    while (DateTime.now().isBefore(deadline)) {
      if (_proc == null) {
        throw StateError(_diagnose(-1));
      }
      try {
        final r = await _http
            .get(Uri.parse('$_clashApi/version'))
            .timeout(const Duration(seconds: 2));
        if (r.statusCode == 200) return;
      } catch (_) {/* not ready yet */}
      await Future<void>.delayed(const Duration(milliseconds: 600));
    }
    await stop();
    throw StateError(_diagnose(-2));
  }

  // ---- traffic polling -------------------------------------------

  void _startPolling() {
    _lastUp = 0;
    _lastDown = 0;
    _lastPoll = DateTime.now();
    _poll = Timer.periodic(const Duration(seconds: 1), (_) => _pollTraffic());
  }

  void _stopPolling() {
    _poll?.cancel();
    _poll = null;
  }

  Future<void> _pollTraffic() async {
    try {
      final r = await _http
          .get(Uri.parse('$_clashApi/connections'))
          .timeout(const Duration(seconds: 3));
      if (r.statusCode != 200) return;
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      final up = (j['uploadTotal'] as num?)?.toInt() ?? 0;
      final down = (j['downloadTotal'] as num?)?.toInt() ?? 0;

      final now = DateTime.now();
      final dt = now.difference(_lastPoll).inMilliseconds / 1000.0;
      final upBps = dt > 0 ? ((up - _lastUp) / dt).round().clamp(0, 1 << 34) : 0;
      final downBps = dt > 0 ? ((down - _lastDown) / dt).round().clamp(0, 1 << 34) : 0;

      _lastUp = up;
      _lastDown = down;
      _lastPoll = now;

      _traffic.add(EngineTraffic(
        upBytes: up,
        downBytes: down,
        upBps: upBps,
        downBps: downBps,
      ));
    } catch (_) {/* transient */}
  }

  // ---- diagnostics --------------------------------------------

  String _privilegeHint() {
    if (Platform.isWindows) {
      return 'Fungua Mbunie VPN kama Administrator (bonyeza kulia → Run as administrator).';
    }
    return 'Endesha kwa ruhusa ya root (sudo) — TUN inahitaji CAP_NET_ADMIN.';
  }

  String _diagnose(int code) {
    final tail = _errBuf.reversed.take(6).toList().reversed.join('\n');
    if (tail.toLowerCase().contains('permission') ||
        tail.toLowerCase().contains('denied') ||
        tail.toLowerCase().contains('not permitted')) {
      return _privilegeHint();
    }
    if (tail.isNotEmpty) return 'Engine imeshindwa:\n$tail';
    return 'Engine imeshindwa kuanza (code $code).';
  }
}
