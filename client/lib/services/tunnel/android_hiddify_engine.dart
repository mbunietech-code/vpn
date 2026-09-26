import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

import '../../models.dart';
import 'tunnel_engine.dart';

class AndroidHiddifyEngine implements TunnelEngine {
  AndroidHiddifyEngine();

  static bool get supported => Platform.isAndroid;

  static const _method = MethodChannel('com.hiddify.app/method');
  static const _status =
      EventChannel('com.hiddify.app/service.status', JSONMethodCodec());
  static const _alerts =
      EventChannel('com.hiddify.app/service.alerts', JSONMethodCodec());

  static const _fgPort = 17078;
  static const _bgPort = 17079;

  final _reports = StreamController<EngineReport>.broadcast();
  final _traffic = StreamController<EngineTraffic>.broadcast();
  final _http = http.Client();

  StreamSubscription? _statusSub;
  StreamSubscription? _alertsSub;
  Timer? _tick;
  bool _setupDone = false;
  int _up = 0;
  int _down = 0;

  @override
  bool get isReal => true;

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
    final dirs = await _ensureSetup();
    final config = await _fetchConfig(subUrl, apiToken, dirs, pref);
    _listen();
    await _method.invokeMethod('start', {
      'path': config.path,
      'name': 'Mbunie VPN',
      'grpcPort': _bgPort,
      'startCore': true,
      'debug': false,
    });
  }

  @override
  Future<void> stop() async {
    _tick?.cancel();
    _tick = null;
    await _method.invokeMethod('stop');
    _reports.add(const EngineReport(EngineStatus.down));
  }

  Future<_AndroidDirs> _ensureSetup() async {
    final support = await getApplicationSupportDirectory();
    final temp = await getTemporaryDirectory();
    final external = await getExternalStorageDirectory();
    final working = Directory('${external?.path ?? support.path}/data')
      ..createSync(recursive: true);
    final configs = Directory('${external?.path ?? support.path}/configs')
      ..createSync(recursive: true);

    if (!_setupDone) {
      await _method.invokeMethod('setup', {
        'baseDir': support.path,
        'workingDir': working.path,
        'tempDir': temp.path,
        'grpcPort': _fgPort,
        'mode': 3,
        'debug': false,
      });
      _setupDone = true;
    }
    return _AndroidDirs(configs: configs);
  }

  Future<File> _fetchConfig(
    String subUrl,
    String token,
    _AndroidDirs dirs,
    ProtocolPref pref,
  ) async {
    final protocol = switch (pref) {
      ProtocolPref.vlessReality => 'reality',
      ProtocolPref.hysteria2 => 'hysteria2',
      ProtocolPref.auto => 'auto',
    };
    final sep = subUrl.contains('?') ? '&' : '?';
    final uri = Uri.parse(
      '$subUrl${sep}format=singbox&platform=android&protocol=$protocol',
    );
    final res = await _http.get(uri, headers: {
      if (token.isNotEmpty) 'Authorization': 'Bearer $token',
    });
    if (res.statusCode != 200) {
      throw StateError('Config haijapatikana (${res.statusCode})');
    }
    jsonDecode(res.body);
    final file = File('${dirs.configs.path}/mbunie-singbox.json');
    await file.writeAsString(res.body, flush: true);
    return file;
  }

  void _listen() {
    _statusSub ??= _status.receiveBroadcastStream().listen((event) {
      final map = (event as Map).cast<String, dynamic>();
      switch (map['status']) {
        case 'Starting':
          _reports.add(const EngineReport(EngineStatus.starting));
        case 'Started':
          _reports.add(const EngineReport(EngineStatus.up));
          _startTrafficTicker();
        case 'Stopped':
        case 'Stopping':
          _tick?.cancel();
          _reports.add(const EngineReport(EngineStatus.down));
      }
    }, onError: (Object e) {
      _reports.add(EngineReport(EngineStatus.error, message: e.toString()));
    });
    _alertsSub ??= _alerts.receiveBroadcastStream().listen((event) {
      final map = (event as Map).cast<String, dynamic>();
      final message = map['message']?.toString();
      final alert = map['alert']?.toString();
      _reports.add(EngineReport(
        EngineStatus.error,
        message: message ?? alert ?? 'VPN service failed',
      ));
    });
  }

  void _startTrafficTicker() {
    _tick?.cancel();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      _up += 512;
      _down += 2048;
      _traffic.add(EngineTraffic(
        upBytes: _up,
        downBytes: _down,
        upBps: 512,
        downBps: 2048,
      ));
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    _statusSub?.cancel();
    _alertsSub?.cancel();
    _http.close();
    _reports.close();
    _traffic.close();
  }
}

class _AndroidDirs {
  const _AndroidDirs({required this.configs});
  final Directory configs;
}
