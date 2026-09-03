import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models.dart';
import 'tunnel/simulated_engine.dart';
import 'tunnel/singbox_engine.dart';
import 'tunnel/tunnel_engine.dart';

/// Connection state + live stats, backed by a [TunnelEngine].
///
/// Desktop (Windows/Linux/macOS) uses the real [SingboxEngine]; everywhere
/// else falls back to [SimulatedEngine] until the mobile FFI engine lands.
class VpnController extends ChangeNotifier {
  VpnController({TunnelEngine? engine})
      : _engine = engine ??
            (SingboxEngine.supported ? SingboxEngine() : SimulatedEngine()) {
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

  // ---- state ----------------------------------------------------------
  VpnStatus _status = VpnStatus.disconnected;
  VpnStatus get status => _status;

  String? _error;
  String? get error => _error;

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
      final pr = p.getString('pref_protocol');
      if (pr != null) {
        protocol = ProtocolPref.values.firstWhere((e) => e.name == pr,
            orElse: () => ProtocolPref.auto);
      }
      notifyListeners();
    } catch (_) {/* defaults */}
  }

  Future<void> _persist() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setBool('pref_killswitch', killSwitch);
      await p.setBool('pref_autoconnect', autoConnect);
      await p.setBool('pref_autoreconnect', autoReconnect);
      await p.setBool('pref_ping', pingOnOpen);
      await p.setString('pref_protocol', protocol.name);
    } catch (_) {}
  }

  /// Called by AppState when a subscription becomes active.
  void maybeAutoConnect() {
    if (autoConnect && _status == VpnStatus.disconnected) {
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
    if (_status == VpnStatus.connecting || _status == VpnStatus.connected) return;
    _userWantsConnected = true;
    _reconnectTimer?.cancel();

    if (_engine.isReal && (_subUrl == null || _apiToken == null)) {
      _error = 'vpn.noSub';
      _set(VpnStatus.error);
      return;
    }

    _error = null;
    _set(_reconnectAttempts > 0 ? VpnStatus.reconnecting : VpnStatus.connecting);
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
    await _engine.stop();
    _connectedAt = null;
    _stats = SessionStats.empty;
    _down.clear();
    _up.clear();
    _peakMbps = 0;
    _set(VpnStatus.disconnected);
  }

  void _scheduleReconnect() {
    if (!autoReconnect || !_userWantsConnected || _reconnectAttempts >= 5) return;
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
    if (!_nodes.any((n) => n.id == _currentNode.id)) {
      _currentNode = _nodes.first;
    }
    notifyListeners();
  }

  void selectNode(VpnNode node) {
    _currentNode = node;
    notifyListeners();
    // With one node + urltest auto-selection there is nothing to switch.
    // Multi-node selector switching via the Clash API is a later addition.
  }

  // ---- engine event handling -------------------------------------

  void _onReport(EngineReport r) {
    switch (r.status) {
      case EngineStatus.starting:
        _set(VpnStatus.connecting);
      case EngineStatus.up:
        _connectedAt ??= DateTime.now();
        _reconnectAttempts = 0;
        _set(VpnStatus.connected);
      case EngineStatus.down:
        _connectedAt = null;
        _stats = SessionStats.empty;
        if (_userWantsConnected && autoReconnect) {
          _set(VpnStatus.reconnecting);
          _scheduleReconnect();
        } else if (_status != VpnStatus.error) {
          _set(VpnStatus.disconnected);
        }
      case EngineStatus.error:
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
      protocol: _engine.isReal
          ? (protocol == ProtocolPref.auto ? 'Auto' : protocol.label)
          : 'Demo',
      downSeries: List.of(_down),
      upSeries: List.of(_up),
    );
    notifyListeners();
  }

  void _set(VpnStatus s) {
    _status = s;
    notifyListeners();
  }

  @override
  void dispose() {
    _reconnectTimer?.cancel();
    _repSub.cancel();
    _trafSub.cancel();
    _engine.dispose();
    super.dispose();
  }

  List<VpnNode> _seedNodes() => const [
        VpnNode(
            id: 'tk1',
            name: 'Tokyo',
            code: 'JP-TK-01',
            region: 'Asia-Pacific',
            latencyMs: 0,
            optimizedFor: 'China'),
      ];
}
