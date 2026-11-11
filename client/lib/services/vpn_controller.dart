import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../models.dart';
import 'friendly_error.dart';
import 'tunnel/android_hiddify_engine.dart';
import 'tunnel/simulated_engine.dart';
import 'tunnel/singbox_engine.dart';
import 'tunnel/tunnel_engine.dart';

/// Connection state + live stats, backed by a [TunnelEngine].
///
/// Android uses the embedded Hiddify mobile core; desktop uses the
/// standalone sing-box process; unsupported platforms fall back to demo mode.
class VpnController extends ChangeNotifier {
  VpnController({TunnelEngine? engine})
    : _engine =
          engine ??
          (Platform.isAndroid
              ? AndroidHiddifyEngine()
              : SingboxEngine.supported
              ? SingboxEngine()
              : SimulatedEngine()) {
    _nodes = _seedNodes();
    _currentNode = _nodes.first;
    _repSub = _engine.reports.listen(_onReport);
    _trafSub = _engine.traffic.listen(_onTraffic);
  }

  final TunnelEngine _engine;
  late final StreamSubscription<EngineReport> _repSub;
  late final StreamSubscription<EngineTraffic> _trafSub;

  bool get isRealTunnel => _engine.isReal;

  // ---- connection target (set by AppState once subscription is active) ----
  String? _subUrl;
  String? _apiToken;
  String? _apiBase;

  void setConnection({
    required String subUrl,
    required String apiToken,
    required String apiBase,
  }) {
    _subUrl = subUrl;
    _apiToken = apiToken;
    _apiBase = apiBase;
  }

  /// The active subscription bundle URL, once a subscription is live.
  String? get subUrl => _subUrl;

  // ---- state ----------------------------------------------------------
  VpnStatus _status = VpnStatus.disconnected;
  VpnStatus get status => _status;

  /// The full failure text: sent to Admin > Devices, never shown as is.
  String? _error;
  String? get error => _error;

  /// What the customer sees for [error]: a short `vpn.err.*` message key.
  String? get errorKey => _error == null ? null : friendlyErrorKey(_error);

  /// Connected, but nothing gets through the tunnel (no internet on this
  /// network, or the server is unreachable). Checked every 30 s while up.
  bool _noInternet = false;
  bool get noInternet => _noInternet && _status == VpnStatus.connected;
  String? _probeDetail;

  /// Admin detail for [noInternet].
  String? get noInternetDetail => noInternet ? _probeDetail : null;
  Timer? _probeTimer;
  int _probeFails = 0;

  late List<VpnNode> _nodes;
  List<VpnNode> get nodes => List.unmodifiable(_nodes);

  late VpnNode _currentNode;
  VpnNode get currentNode => _currentNode;

  bool killSwitch = true;
  bool autoConnect = false;
  bool autoReconnect = true;
  bool pingOnOpen = true;
  ProtocolPref protocol = ProtocolPref.auto;
  bool get ipObfuscated => _status == VpnStatus.connected;

  static const _protocolKey = 'pref_protocol_v2';
  static const _serverKey = 'pref_server_v1';
  String? _savedServerId; // restored once the subscription's nodes load

  bool _userWantsConnected = false;
  int _reconnectAttempts = 0;
  Timer? _reconnectTimer;

  /// Load persisted preferences. Call once at startup.
  Future<void> loadPrefs() async {
    try {
      final p = await SharedPreferences.getInstance();
      killSwitch = p.getBool('pref_killswitch') ?? killSwitch;
      autoConnect = p.getBool('pref_autoconnect') ?? autoConnect;
      autoReconnect = p.getBool('pref_autoreconnect') ?? autoReconnect;
      pingOnOpen = p.getBool('pref_ping') ?? pingOnOpen;
      // v2: the old key stored the OpenVPN default even for users who never
      // picked a protocol; starting fresh puts everyone on Auto once.
      final pr = p.getString(_protocolKey);
      if (pr != null) {
        protocol = ProtocolPref.values.firstWhere(
          (e) => e.name == pr,
          orElse: () => ProtocolPref.auto,
        );
      }
      _savedServerId = p.getString(_serverKey);
      notifyListeners();
    } catch (_) {
      /* defaults */
    }
  }

  Future<void> _persist() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setBool('pref_killswitch', killSwitch);
      await p.setBool('pref_autoconnect', autoConnect);
      await p.setBool('pref_autoreconnect', autoReconnect);
      await p.setBool('pref_ping', pingOnOpen);
      await p.setString(_protocolKey, protocol.name);
    } catch (_) {}
  }

  /// Called by AppState when a subscription becomes active. [force]: the
  /// VPN was on before the app restarted to update.
  void maybeAutoConnect({bool force = false}) {
    if ((autoConnect || force) && _status == VpnStatus.disconnected) {
      connect();
    }
  }

  final String peerId = 'mbunie';
  final String appVersion = 'v1.0.0';

  SessionStats _stats = SessionStats.empty;
  SessionStats get stats => _stats;

  DateTime? _connectedAt;
  final List<double> _down = [];
  final List<double> _up = [];
  int _peakMbps = 0;

  bool get isConnected => _status == VpnStatus.connected;

  void update(void Function() mutate) {
    mutate();
    notifyListeners();
    _persist();
  }

  // ---- actions ------------------------------------------------------

  Future<void> toggle() {
    if (_status == VpnStatus.connected ||
        _status == VpnStatus.connecting ||
        _status == VpnStatus.reconnecting) {
      return disconnect();
    }
    return connect();
  }

  Future<void> connect() async {
    if (_status == VpnStatus.connecting || _status == VpnStatus.connected) {
      return;
    }
    _userWantsConnected = true;
    _reconnectTimer?.cancel();

    if (_engine.isReal && (_subUrl == null || _apiToken == null)) {
      _error = 'vpn.noSub';
      _set(VpnStatus.error);
      return;
    }

    _error = null;
    _set(
      _reconnectAttempts > 0 ? VpnStatus.reconnecting : VpnStatus.connecting,
    );
    try {
      await _engine.start(
        subUrl: _subUrl ?? '',
        apiToken: _apiToken ?? '',
        apiBase: _apiBase ?? '',
        pref: protocol,
      );
    } catch (e) {
      _error = e is StateError ? e.message : e.toString();
      _set(VpnStatus.error);
      _scheduleReconnect();
    }
  }

  Future<void> disconnect() async {
    _userWantsConnected = false;
    _reconnectAttempts = 0;
    _reconnectTimer?.cancel();
    _stopProbe();
    await _engine.stop();
    _connectedAt = null;
    _stats = SessionStats.empty;
    _down.clear();
    _up.clear();
    _peakMbps = 0;
    _set(VpnStatus.disconnected);
  }

  void _scheduleReconnect() {
    if (!autoReconnect || !_userWantsConnected || _reconnectAttempts >= 5) {
      return;
    }
    _reconnectAttempts++;
    final delay = Duration(seconds: 2 * _reconnectAttempts);
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(delay, () {
      if (_userWantsConnected) connect();
    });
  }

  void setNodes(List<VpnNode> nodes) {
    if (nodes.isEmpty) return;
    _nodes = nodes;
    final saved = _nodes.where((n) => n.id == _savedServerId);
    if (saved.isNotEmpty) {
      _currentNode = saved.first;
    } else if (!_nodes.any((n) => n.id == _currentNode.id)) {
      _currentNode = _nodes.first;
    }
    notifyListeners();
  }

  /// Picks a server from the list. Switches the running tunnel in place
  /// (Clash API selector) when connected; otherwise it applies on connect.
  /// False when the running config does not offer it.
  Future<bool> selectNode(VpnNode node) async {
    final previous = _currentNode;
    _currentNode = node;
    _savedServerId = node.id;
    notifyListeners();
    unawaited(_persistServer(node.id));

    // An explicit protocol (Settings) builds a config without the selector
    // choices; OpenVPN as a server needs the Auto config.
    if (node.isOpenVpn && protocol != ProtocolPref.auto) {
      protocol = ProtocolPref.auto;
      unawaited(_persist());
      if (isConnected) {
        await disconnect();
        await connect(); // the choice is applied once it is up
      }
      return true;
    }
    if (!isConnected) return true;

    final ok = await _applyServer();
    if (!ok) {
      _currentNode = previous;
      _savedServerId = previous.id;
      unawaited(_persistServer(previous.id));
      notifyListeners();
    }
    return ok;
  }

  Future<bool> _applyServer() async {
    if (!_engine.isReal) return true;
    // Explicit-protocol configs have no OpenVPN (selectNode moves an OpenVPN
    // pick to Auto first); they do carry "auto" and the IPv6 groups.
    if (protocol != ProtocolPref.auto && _currentNode.isOpenVpn) return true;
    return _engine.selectServer(_currentNode.selectorTag);
  }

  Future<void> _persistServer(String id) async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setString(_serverKey, id);
    } catch (_) {}
  }

  // ---- engine event handling -------------------------------------

  void _onReport(EngineReport r) {
    switch (r.status) {
      case EngineStatus.starting:
        _set(VpnStatus.connecting);
      case EngineStatus.up:
        _connectedAt ??= DateTime.now();
        _reconnectAttempts = 0;
        // Also when the tunnel was already up (started before the app
        // opened, from the Quick Settings tile, or after a reboot/update).
        _userWantsConnected = true;
        _set(VpnStatus.connected);
        _startProbe();
        // Re-apply the picked server: a new engine starts on "auto" (or on
        // what its cache remembers).
        unawaited(_applyServer());
      case EngineStatus.down:
        _stopProbe();
        _connectedAt = null;
        _stats = SessionStats.empty;
        if (r.external) {
          _userWantsConnected = false;
          _reconnectTimer?.cancel();
          _reconnectAttempts = 0;
        }
        if (_userWantsConnected && autoReconnect) {
          _set(VpnStatus.reconnecting);
          _scheduleReconnect();
        } else if (_status != VpnStatus.error) {
          _set(VpnStatus.disconnected);
        }
      case EngineStatus.error:
        _stopProbe();
        _error = r.message ?? 'vpn.failed';
        _set(VpnStatus.error);
        _scheduleReconnect();
    }
  }

  void _onTraffic(EngineTraffic t) {
    if (_connectedAt == null) return;
    final downMbps = t.downBps * 8 / 1e6;
    final upMbps = t.upBps * 8 / 1e6;
    _peakMbps = downMbps.round() > _peakMbps ? downMbps.round() : _peakMbps;
    _down.add(downMbps);
    _up.add(upMbps);
    if (_down.length > 40) _down.removeAt(0);
    if (_up.length > 40) _up.removeAt(0);

    _stats = SessionStats(
      duration: DateTime.now().difference(_connectedAt!),
      downloadedBytes: t.downBytes,
      uploadedBytes: t.upBytes,
      peakMbps: _peakMbps,
      protocol: !_engine.isReal
          ? 'Demo'
          : protocol != ProtocolPref.auto
          ? protocol.label
          : _currentNode.isOpenVpn
          ? 'OpenVPN'
          : 'Auto',
      downSeries: List.of(_down),
      upSeries: List.of(_up),
    );
    notifyListeners();
  }

  // ---- "connected, but no internet" probe ----------------------------

  void _startProbe() {
    _stopProbe();
    if (!_engine.isReal) return;
    _probeTimer = Timer(const Duration(seconds: 4), _probe);
  }

  void _stopProbe() {
    _probeTimer?.cancel();
    _probeTimer = null;
    _probeFails = 0;
    if (_noInternet) {
      _noInternet = false;
      _probeDetail = null;
      notifyListeners();
    }
  }

  /// One request through the tunnel via the engine's Clash API (the same
  /// test URL the server's url-test groups use).
  Future<void> _probe() async {
    if (_status != VpnStatus.connected) return;
    String? fail;
    try {
      final res = await http
          .get(
            Uri.parse(
              'http://127.0.0.1:9095/proxies/proxy/delay?timeout=6000'
              '&url=${Uri.encodeQueryComponent('https://www.apple.com/library/test/success.html')}',
            ),
          )
          .timeout(const Duration(seconds: 9));
      if (res.statusCode != 200) fail = 'HTTP ${res.statusCode} ${res.body}';
    } catch (e) {
      fail = '$e';
    }
    if (_status != VpnStatus.connected) return;
    if (fail == null) {
      _probeFails = 0;
      if (_noInternet) {
        _noInternet = false;
        _probeDetail = null;
        notifyListeners();
      }
    } else if (++_probeFails >= 2 && !_noInternet) {
      // Two in a row: one slow answer is not "no internet".
      _noInternet = true;
      _probeDetail =
          'Connected, but nothing gets through the tunnel '
          '(server ${_currentNode.name}): $fail';
      notifyListeners();
    }
    _probeTimer = Timer(
      Duration(seconds: fail == null || _noInternet ? 30 : 5),
      _probe,
    );
  }

  void _set(VpnStatus s) {
    _status = s;
    notifyListeners();
  }

  @override
  void dispose() {
    _reconnectTimer?.cancel();
    _probeTimer?.cancel();
    _repSub.cancel();
    _trafSub.cancel();
    _engine.dispose();
    super.dispose();
  }

  // Shown until the account's server list loads; matches the live server.
  List<VpnNode> _seedNodes() => const [
    VpnNode(
      id: 'kl1',
      name: 'Kuala Lumpur 1',
      code: 'MY-KL-01',
      region: 'Asia-Pacific',
      latencyMs: 0,
      optimizedFor: 'China',
    ),
  ];
}
