import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models.dart';
import '../config.dart';
import 'api_client.dart';
import 'sub_parser.dart';
import 'vpn_controller.dart';

enum AuthGate { loading, needsAuth, needsPlan, ready }

class AppState extends ChangeNotifier {
  AppState({ApiClient? api, VpnController? vpn})
      : api = api ?? ApiClient(),
        vpn = vpn ?? VpnController() {
    // Forward VPN state changes to app-wide listeners.
    this.vpn.addListener(notifyListeners);
    this.vpn.addListener(_onVpnChanged);
  }

  @override
  void dispose() {
    _beatTimer?.cancel();
    vpn.removeListener(_onVpnChanged);
    vpn.removeListener(notifyListeners);
    vpn.dispose();
    super.dispose();
  }

  // ---- device heartbeat (admin "who is online now") ---------------------

  String? _deviceId;
  Timer? _beatTimer;
  bool? _lastBeatConnected;

  void _onVpnChanged() {
    final connected = vpn.isConnected;
    if (connected == _lastBeatConnected) return;
    _lastBeatConnected = connected;
    _beat();
    _beatTimer?.cancel();
    if (connected) {
      _beatTimer = Timer.periodic(const Duration(minutes: 2), (_) => _beat());
    }
  }

  Future<void> _beat() async {
    final id = _deviceId;
    if (id == null || _token == null || subStatus != 'active') return;
    try {
      await api.registerDevice(
        fingerprint: id,
        platform: Platform.operatingSystem,
        name: _deviceName(),
        connected: vpn.isConnected,
        protocol: vpn.protocol.name,
        appVersion: MvpnConfig.appVersion,
      );
    } catch (_) {
      // Best effort: telemetry must never get in the way of the tunnel.
    }
  }

  static String _newDeviceId() {
    final r = Random.secure();
    return List.generate(16, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
  }

  final ApiClient api;
  final VpnController vpn;

  AuthGate gate = AuthGate.loading;
  String? identifier;
  String? _token;

  /// Manual language override ('en' | 'sw' | 'zh'); null = follow device.
  String? localeOverride;

  Future<void> setLocale(String? code) async {
    localeOverride = code;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    if (code == null) {
      await prefs.remove('mvpn_locale');
    } else {
      await prefs.setString('mvpn_locale', code);
    }
  }

  // subscription
  String subStatus = 'none'; // none | pending | active | expired | suspended
  String? planCode;
  DateTime? expiresAt;
  bool awaitingPayment = false;
  Map<String, dynamic>? pendingInvoice; // {invoice_id, status, proof_uploaded, ...}

  List<Plan> plans = const [];
  List<String> currencies = const ['usd', 'cny'];
  List<PayMethod> payMethods = const [];

  Future<void> bootstrap() async {
    await vpn.loadPrefs();
    final prefs = await SharedPreferences.getInstance();
    localeOverride = prefs.getString('mvpn_locale');
    _deviceId = prefs.getString('mvpn_device_id');
    if (_deviceId == null) {
      _deviceId = _newDeviceId();
      await prefs.setString('mvpn_device_id', _deviceId!);
    }
    _token = prefs.getString('mvpn_token');
    identifier = prefs.getString('mvpn_identifier');
    api.token = _token;

    if (_token == null) {
      gate = AuthGate.needsAuth;
      notifyListeners();
      return;
    }
    await refreshSubscription();
  }

  // ---- auth ---------------------------------------------------------------

  Future<void> requestOtp(String id) async {
    identifier = id.trim();
    await api.requestOtp(identifier!);
  }

  /// Returns a debug code when the control plane runs in local mode.
  Future<String?> requestOtpReturningDebug(String id) async {
    identifier = id.trim();
    final r = await api.requestOtp(identifier!);
    return r['debug_code'] as String?;
  }

  Future<void> loginWithEduHub(String email, String password) async {
    identifier = email.trim();
    final r = await api.loginEduHub(identifier!, password, _deviceName());
    await _storeSession(r['token'] as String);
  }

  Future<void> verifyOtp(String code) async {
    final r = await api.verifyOtp(identifier!, code, _deviceName());
    await _storeSession(r['token'] as String);
  }

  Future<void> _storeSession(String token) async {
    _token = token;
    api.token = _token;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('mvpn_token', _token!);
    await prefs.setString('mvpn_identifier', identifier!);
    await refreshSubscription();
  }

  Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('mvpn_token');
    _token = null;
    api.token = null;
    gate = AuthGate.needsAuth;
    subStatus = 'none';
    notifyListeners();
  }

  // ---- plans + checkout -------------------------------------------------

  Future<void> loadPlans() async {
    final r = await api.plans();
    currencies = (r['currencies'] as List).cast<String>();
    plans = (r['plans'] as List).map((p) {
      final price = p['price'] as Map<String, dynamic>;
      return Plan(
        code: p['code'] as String,
        name: p['name'] as String,
        days: p['days'] as int,
        maxDevices: p['max_devices'] as int,
        priceDisplay: (price['display'] as Map).map(
          (k, v) => MapEntry(k as String, v as String),
        ),
      );
    }).toList();
    notifyListeners();
  }

  /// Starts checkout, returns the hosted pay URL.
  Future<String> startCheckout(String planCode, String provider, String currency) async {
    final r = await api.checkout(plan: planCode, provider: provider, currency: currency);
    awaitingPayment = true;
    notifyListeners();
    return r['pay_url'] as String;
  }

  // ---- v1 manual payment ---------------------------------------------

  /// Instant hosted-checkout providers: [{provider, label, currency}]
  List<Map<String, dynamic>> instantProviders = const [];

  Future<void> loadPayMethods() async {
    final r = await api.paymentMethods();
    payMethods = (r['methods'] as List)
        .map((m) => PayMethod.fromJson(m as Map<String, dynamic>))
        .toList();
    instantProviders = ((r['instant'] as List?) ?? const [])
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
    notifyListeners();
  }

  /// Creates a pending_review invoice; returns its id.
  Future<int> startManualCheckout(String planCode, String currency, int? methodId) async {
    final r = await api.manualCheckout(
        plan: planCode, currency: currency, methodId: methodId);
    awaitingPayment = true;
    notifyListeners();
    return r['invoice_id'] as int;
  }

  Future<void> submitProof(int invoiceId, File file, {String? note}) async {
    await api.uploadProof(invoiceId: invoiceId, image: file, note: note);
    await refreshSubscription();
  }

  /// LOCAL demo shortcut.
  Future<void> devCompletePayment() async {
    await api.devPay();
    await refreshSubscription();
  }

  // ---- subscription ----------------------------------------------------

  Future<void> refreshSubscription() async {
    try {
      final r = await api.subscription();
      subStatus = r['status'] as String? ?? 'none';
      planCode = r['plan'] as String?;
      awaitingPayment = r['awaiting_payment'] == true;
      pendingInvoice = r['pending_invoice'] as Map<String, dynamic>?;
      expiresAt = r['expires_at'] != null
          ? DateTime.tryParse(r['expires_at'] as String)
          : null;

      if (subStatus == 'active' && r['sub_url'] is String) {
        final subUrl = r['sub_url'] as String;
        vpn.setConnection(
          subUrl: subUrl,
          apiToken: _token ?? '',
          apiBase: api.baseUrl,
        );
        await _loadNodesFrom(subUrl);
        gate = AuthGate.ready;
        unawaited(_beat());
        vpn.maybeAutoConnect();
      } else {
        gate = AuthGate.needsPlan;
      }
    } on ApiException catch (e) {
      if (e.status == 401) {
        await logout();
        return;
      }
      rethrow;
    }
    notifyListeners();
  }

  Future<void> _loadNodesFrom(String subUrl) async {
    try {
      final body = await api.fetchSubscription(subUrl);
      final nodes = SubParser.parse(body);
      if (nodes.isNotEmpty) vpn.setNodes(nodes);
    } catch (_) {
      // keep whatever nodes we have
    }
  }

  String _deviceName() => '${defaultTargetPlatform.name} device';
}
