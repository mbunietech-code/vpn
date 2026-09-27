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
  static const _status = EventChannel(
    'com.hiddify.app/service.status',
    JSONMethodCodec(),
  );
  static const _alerts = EventChannel(
    'com.hiddify.app/service.alerts',
    JSONMethodCodec(),
  );

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
    final res = await _http.get(
      uri,
      headers: {if (token.isNotEmpty) 'Authorization': 'Bearer $token'},
    );
    if (res.statusCode != 200) {
      throw StateError('Config haijapatikana (${res.statusCode})');
    }
    final decoded = jsonDecode(res.body);
    if (decoded is! Map<String, dynamic>) {
      final preview = res.body.trim().replaceAll(RegExp(r'\s+'), ' ');
      final end = preview.length > 80 ? 80 : preview.length;
      throw StateError(
        'Config si JSON ya sing-box: ${preview.substring(0, end)}',
      );
    }
    _normalizeAndroidTunConfig(decoded);
    _normalizeDnsConfig(decoded);
    _normalizeBootstrapDnsRules(decoded);
    final file = File('${dirs.configs.path}/mbunie-singbox.json');
    await file.writeAsString(
      const JsonEncoder.withIndent('  ').convert(decoded),
      flush: true,
    );
    return file;
  }

  void _normalizeAndroidTunConfig(Map<String, dynamic> config) {
    final inbounds = config['inbounds'];
    if (inbounds is! List) return;

    for (final inbound in inbounds) {
      if (inbound is! Map || inbound['type'] != 'tun') continue;

      inbound.remove('interface_name');
      inbound.remove('strict_route');
      inbound.remove('sniff');
      inbound.remove('sniff_override_destination');
      inbound['stack'] = 'mixed';
      inbound['auto_route'] = true;
      inbound['route_exclude_address'] =
          _mergeStringList(inbound['route_exclude_address'], const [
            '10.0.0.0/8',
            '100.64.0.0/10',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '224.0.0.0/4',
            '255.255.255.255/32',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
          ]);
      inbound['exclude_package'] =
          _mergeStringList(inbound['exclude_package'], const [
            'com.mbunie.mvpn',
            'com.android.shell',
            'com.android.settings',
            'com.google.android.gms',
          ]);
    }
  }

  void _normalizeBootstrapDnsRules(Map<String, dynamic> config) {
    final hosts = _proxyServerHosts(config);
    if (hosts.isEmpty) return;

    final dns = config['dns'];
    if (dns is Map) {
      final rules = dns['rules'] is List ? dns['rules'] as List : <dynamic>[];
      _removeDomainRules(rules, hosts);
      rules.insert(0, {'domain': hosts, 'server': 'direct-dns'});
      dns['rules'] = rules;
    }

    final route = config['route'];
    if (route is Map) {
      final rules = route['rules'] is List
          ? route['rules'] as List
          : <dynamic>[];
      _removeDomainRules(rules, hosts);
      final insertAt = rules.indexWhere(
        (rule) => rule is Map && rule['protocol'] == 'dns',
      );
      rules.insert(insertAt >= 0 ? insertAt + 1 : 0, {
        'domain': hosts,
        'outbound': 'direct',
      });
      route['rules'] = rules;
    }
  }

  void _removeDomainRules(List<dynamic> rules, List<String> hosts) {
    final hostSet = hosts.toSet();
    rules.removeWhere((rule) {
      if (rule is! Map) return false;
      final domains = rule['domain'];
      if (domains is! List) return false;
      return domains.whereType<String>().toSet().containsAll(hostSet);
    });
  }

  List<String> _proxyServerHosts(Map<String, dynamic> config) {
    final outbounds = config['outbounds'];
    if (outbounds is! List) return const [];

    final hosts = <String>{};
    for (final outbound in outbounds) {
      if (outbound is! Map) continue;
      final type = outbound['type'];
      if (type == 'direct' || type == 'selector' || type == 'urltest') {
        continue;
      }
      final server = outbound['server'];
      if (server is! String || server.isEmpty) continue;
      if (!RegExp(r'[A-Za-z]').hasMatch(server)) continue;
      hosts.add(server);
    }
    return hosts.toList();
  }

  List<String> _mergeStringList(Object? current, List<String> fallback) {
    final merged = <String>{};
    if (current is List) {
      for (final item in current) {
        if (item is String && item.isNotEmpty) merged.add(item);
      }
    }
    merged.addAll(fallback);
    return merged.toList();
  }

  void _normalizeDnsConfig(Map<String, dynamic> config) {
    final dns = config['dns'];
    if (dns is! Map) return;
    dns.remove('independent_cache');

    final servers = dns['servers'];
    if (servers is! List) return;
    for (final server in servers) {
      if (server is! Map) continue;
      if (server['detour'] == 'direct') server.remove('detour');

      final address = server.remove('address');
      if (address is! String) continue;

      final uri = Uri.tryParse(address);
      if (uri != null && (uri.scheme == 'https' || uri.scheme == 'h3')) {
        server['type'] = uri.scheme == 'h3' ? 'h3' : 'https';
        server['server'] = uri.host;
        if (uri.hasPort) server['server_port'] = uri.port;
        server['path'] = uri.path.isEmpty ? '/dns-query' : uri.path;
      } else if (address == 'local') {
        server['type'] = 'local';
      } else {
        server['type'] = 'udp';
        server['server'] = address.replaceFirst(RegExp(r'^udp://'), '');
      }
    }
  }

  void _listen() {
    _statusSub ??= _status.receiveBroadcastStream().listen(
      (event) {
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
      },
      onError: (Object e) {
        _reports.add(EngineReport(EngineStatus.error, message: e.toString()));
      },
    );
    _alertsSub ??= _alerts.receiveBroadcastStream().listen((event) {
      final map = (event as Map).cast<String, dynamic>();
      final message = map['message']?.toString();
      final alert = map['alert']?.toString();
      _reports.add(
        EngineReport(
          EngineStatus.error,
          message: message ?? alert ?? 'VPN service failed',
        ),
      );
    });
  }

  void _startTrafficTicker() {
    _tick?.cancel();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      _up += 512;
      _down += 2048;
      _traffic.add(
        EngineTraffic(
          upBytes: _up,
          downBytes: _down,
          upBps: 512,
          downBps: 2048,
        ),
      );
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
