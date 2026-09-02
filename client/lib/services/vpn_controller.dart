import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../models.dart';

/// Drives connection state + live stats.
///
/// TODO: back this with the real sing-box `libbox` FFI engine (Hiddify-Next
/// fork). For now it simulates so the UI is fully exercised.
class VpnController extends ChangeNotifier {
  VpnController() {
    _nodes = _seedNodes();
    _currentNode = _nodes.firstWhere((n) => n.optimizedFor != null,
        orElse: () => _nodes.first);
  }

  VpnStatus _status = VpnStatus.disconnected;
  VpnStatus get status => _status;

  late List<VpnNode> _nodes;
  List<VpnNode> get nodes => List.unmodifiable(_nodes);

  late VpnNode _currentNode;
  VpnNode get currentNode => _currentNode;

  bool killSwitch = true;
  bool autoConnect = false;
  bool autoReconnect = true;
  bool pingOnOpen = true;
  ProtocolPref protocol = ProtocolPref.auto;
  bool ipObfuscated = true;

  final String peerId = 'mbunie_peer_9d5f7a8b8c';
  final String appVersion = 'v1.0.0';

  SessionStats _stats = SessionStats.empty;
  SessionStats get stats => _stats;

  Timer? _tick;
  DateTime? _connectedAt;
  final _rng = Random();
  final List<double> _down = [];
  final List<double> _up = [];
  int _peakMbps = 0;
  double _downBytes = 0, _upBytes = 0;

  bool get isConnected => _status == VpnStatus.connected;

  /// Mutate a simple preference and rebuild listeners.
  void update(void Function() mutate) {
    mutate();
    notifyListeners();
  }

  Future<void> toggle() =>
      isConnected || _status == VpnStatus.connecting ? disconnect() : connect();

  Future<void> connect() async {
    if (_status == VpnStatus.connecting || _status == VpnStatus.connected) return;
    _set(VpnStatus.connecting);
    await Future<void>.delayed(const Duration(milliseconds: 1400));
    _connectedAt = DateTime.now();
    _down.clear();
    _up.clear();
    _peakMbps = 0;
    _downBytes = 0;
    _upBytes = 0;
    _set(VpnStatus.connected);
    _tick = Timer.periodic(const Duration(seconds: 1), (_) => _pump());
  }

  Future<void> disconnect() async {
    _tick?.cancel();
    _tick = null;
    _connectedAt = null;
    _stats = SessionStats.empty;
    _set(VpnStatus.disconnected);
  }

  /// Replace the node list from a freshly parsed subscription.
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
    if (isConnected) {
      // brief re-handshake
      _set(VpnStatus.reconnecting);
      Future<void>.delayed(const Duration(milliseconds: 900), () {
        if (_connectedAt != null) _set(VpnStatus.connected);
      });
    }
  }

  void _pump() {
    final downMbps = 40 + _rng.nextDouble() * 380;
    final upMbps = 10 + _rng.nextDouble() * 90;
    _peakMbps = max(_peakMbps, downMbps.round());
    _down.add(downMbps);
    _up.add(upMbps);
    if (_down.length > 40) _down.removeAt(0);
    if (_up.length > 40) _up.removeAt(0);
    _downBytes += downMbps * 1000000 / 8;
    _upBytes += upMbps * 1000000 / 8;

    _stats = SessionStats(
      duration: DateTime.now().difference(_connectedAt!),
      downloadedBytes: _downBytes.round(),
      uploadedBytes: _upBytes.round(),
      peakMbps: _peakMbps,
      protocol: protocol == ProtocolPref.auto ? 'VLESS-REALITY' : protocol.label,
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
    _tick?.cancel();
    super.dispose();
  }

  List<VpnNode> _seedNodes() => const [
        VpnNode(id: 'zrh', name: 'Zurich', code: 'CH-01', region: 'Europe', latencyMs: 38, optimizedFor: 'Privacy'),
        VpnNode(id: 'nyc', name: 'New York', code: 'US-EAST-01', region: 'Americas', latencyMs: 24),
        VpnNode(id: 'yyz', name: 'Toronto', code: 'CA-CEN-02', region: 'Americas', latencyMs: 45),
        VpnNode(id: 'fra', name: 'Frankfurt', code: 'EU-CEN-01', region: 'Europe', latencyMs: 115),
        VpnNode(id: 'lon', name: 'London', code: 'EU-WEST-03', region: 'Europe', latencyMs: 122),
        VpnNode(id: 'hkg', name: 'Hong Kong', code: 'AP-HK-01', region: 'Asia-Pacific', latencyMs: 68, optimizedFor: 'China'),
        VpnNode(id: 'sin', name: 'Singapore', code: 'AP-SEA-01', region: 'Asia-Pacific', latencyMs: 245),
      ];
}
