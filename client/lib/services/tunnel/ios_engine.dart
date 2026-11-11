import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;

import '../../models.dart';
import '../api_client.dart';
import '../relay.dart';
import 'clash_traffic.dart';
import 'rule_sets.dart';
import 'tunnel_engine.dart';

/// iPhone: sing-box (libbox) runs in the Packet Tunnel extension
/// (ios/PacketTunnel). The app fetches the config, makes its rule sets local
/// and writes both into the App Group container, where the extension can
/// read them; ios/Runner/VpnBridge.swift starts and stops the tunnel.
class IosEngine implements TunnelEngine {
  /// Listens straight away: the tunnel outlives the app, so a reopened app
  /// learns from the first status event that it is still up.
  IosEngine() {
    _listen();
  }

  static bool get supported => Platform.isIOS;

  static const _method = MethodChannel('com.mbunie.mvpn/vpn');
  static const _status = EventChannel('com.mbunie.mvpn/vpn.status');

  final _reports = StreamController<EngineReport>.broadcast();
  final _traffic = StreamController<EngineTraffic>.broadcast();
  final _http = Relay.client();

  late final _poller = ClashTraffic(_http, _traffic);

  StreamSubscription? _statusSub;
  Timer? _watchdog;
  bool _started = false;

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
    final group = await _method.invokeMethod<String>('appGroupPath');
    if (group == null) {
      throw StateError('App Group container is missing. Please reinstall Mbunie VPN.');
    }
    final config = await _fetchConfig(subUrl, apiToken, Directory(group), pref);
    _started = false;
    try {
      await _method.invokeMethod('start', {'configPath': config.path});
    } on PlatformException catch (e) {
      // Declining "Add VPN Configurations" lands here.
      throw StateError('iPhone VPN request failed: ${e.message ?? e.code}');
    }

    // Never spin forever: if the tunnel hasn't come up, surface it.
    _watchdog?.cancel();
    _watchdog = Timer(const Duration(seconds: 45), () async {
      if (_started) return;
      _reports.add(
        const EngineReport(
          EngineStatus.error,
          message:
              'VPN did not start within 45 seconds. '
              'Make sure you allowed the iPhone VPN request, then try again.',
        ),
      );
      try {
        await _method.invokeMethod('stop');
      } catch (_) {}
    });
  }

  @override
  Future<bool> selectServer(String tag) => clashSelectServer(tag);

  @override
  Future<void> stop() async {
    _watchdog?.cancel();
    _stopPolling();
    await _method.invokeMethod('stop');
    _reports.add(const EngineReport(EngineStatus.down));
  }

  Future<File> _fetchConfig(
    String subUrl,
    String token,
    Directory group,
    ProtocolPref pref,
  ) async {
    final protocol = switch (pref) {
      ProtocolPref.vlessReality => 'reality',
      ProtocolPref.hysteria2 => 'hysteria2',
      ProtocolPref.openVpn => 'openvpn',
      ProtocolPref.auto => 'auto',
    };
    final sep = subUrl.contains('?') ? '&' : '?';
    final uri = Uri.parse(
      '$subUrl${sep}format=singbox&platform=ios&protocol=$protocol',
    );
    // The extension also reloads from config.json (PacketTunnelProvider).
    final file = File('${group.path}/config.json');
    // The last config that worked for this protocol, for when the server
    // can't be reached (Cloudflare is often blocked in China).
    final last = File('${group.path}/last-$protocol.json');
    final http.Response res;
    try {
      res = await Relay.get(
        _http,
        uri,
        headers: {
          if (token.isNotEmpty) 'Authorization': 'Bearer $token',
          ...ApiClient.deviceHeaders,
        },
        timeout: const Duration(seconds: 25),
      );
    } catch (e) {
      if (!isUnreachable(e)) rethrow;
      if (last.existsSync()) {
        await last.copy(file.path);
        return file;
      }
      throw StateError(
        'Could not download the VPN config '
        '(${uri.host} is unreachable from this network).',
      );
    }
    if (res.statusCode != 200) {
      if (res.statusCode == 403 && res.body.contains('device_revoked')) {
        throw StateError('This device was removed from the account. Contact support@mbuniehub.com.');
      }
      throw StateError('Could not get the VPN config (HTTP ${res.statusCode})');
    }
    // UTF-8 explicitly: res.body falls back to latin1 and mangles "·" in tags.
    final decoded = jsonDecode(utf8.decode(res.bodyBytes));
    if (decoded is! Map<String, dynamic>) {
      throw StateError('VPN config is not valid sing-box JSON');
    }
    // China rule sets: the app fetches them (the core can't trust the
    // relay). They must be in the App Group, or the extension can't read them.
    await localizeRuleSets(decoded, group, _http);
    final json = const JsonEncoder.withIndent('  ').convert(decoded);
    await file.writeAsString(json, flush: true);
    await last.writeAsString(json, flush: true);
    return file;
  }

  void _listen() {
    _statusSub ??= _status.receiveBroadcastStream().listen(
      (event) {
        final map = (event as Map).cast<String, dynamic>();
        switch (map['status']) {
          case 'connecting':
            _reports.add(const EngineReport(EngineStatus.starting));
          case 'up':
            _started = true;
            _watchdog?.cancel();
            _reports.add(const EngineReport(EngineStatus.up));
            _startPolling();
          case 'down':
            _stopPolling();
            _reports.add(
              EngineReport(
                EngineStatus.down,
                external: map['external'] == true,
              ),
            );
          case 'error':
            _watchdog?.cancel();
            _stopPolling();
            // Unchanged: friendly_error.dart decides what the customer sees.
            _reports.add(
              EngineReport(
                EngineStatus.error,
                message: map['message']?.toString() ?? 'VPN service failed',
              ),
            );
        }
      },
      onError: (Object e) {
        _reports.add(EngineReport(EngineStatus.error, message: e.toString()));
      },
    );
  }

  // ---- traffic, from the extension's Clash API (while on screen) -------

  void _startPolling() => _poller.start();

  void _stopPolling() => _poller.stop();

  @override
  void dispose() {
    _watchdog?.cancel();
    _stopPolling();
    _statusSub?.cancel();
    _http.close();
    _reports.close();
    _traffic.close();
  }
}
