import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models.dart';
import '../config.dart';
import 'api_client.dart';
import 'app_updater.dart';
import 'sub_parser.dart';
import 'vpn_controller.dart';

enum AuthGate { loading, needsAuth, needsPlan, ready }

class AppState extends ChangeNotifier with WidgetsBindingObserver {
  AppState({ApiClient? api, VpnController? vpn})
      : api = api ?? ApiClient(),
        vpn = vpn ?? VpnController() {
    // Forward VPN state changes to app-wide listeners.
    this.vpn.addListener(notifyListeners);
    this.vpn.addListener(_onVpnChanged);
    updater.addListener(notifyListeners);
  }

  @override
  void dispose() {
    _beatTimer?.cancel();
    _updateTimer?.cancel();
    updater.removeListener(notifyListeners);
    updater.dispose();
    vpn.removeListener(_onVpnChanged);
    vpn.removeListener(notifyListeners);
    vpn.dispose();
    super.dispose();
  }

  // ---- device heartbeat (admin "who is online now") ---------------------

  String? _deviceId;
  Future<void> _idReady = Future.value();
  Timer? _beatTimer;
  bool? _lastBeatConnected;
  String? _lastReportedError;
  DateTime? _lastReportedAt;
  Map<String, String>? _deviceInfo;

  void _onVpnChanged() {
    // Diagnostics: report each new failure (same one at most every 2 min, so a
    // reconnect loop doesn't flood the server).
    final err = vpn.status == VpnStatus.error ? vpn.error : null;
    if (err != null &&
        (err != _lastReportedError ||
            _lastReportedAt == null ||
            DateTime.now().difference(_lastReportedAt!) > const Duration(minutes: 2))) {
      _lastReportedError = err;
      _lastReportedAt = DateTime.now();
      _beat(error: err);
    }

    final connected = vpn.isConnected;
    if (connected == _lastBeatConnected) return;
    _lastBeatConnected = connected;
    if (connected) unawaited(_askBatteryExemptionOnce());
    _beat();
    _beatTimer?.cancel();
    if (connected) {
      _beatTimer = Timer.periodic(const Duration(minutes: 2), (_) => _beat());
    }
  }

  Future<void> _beat({String? error}) async {
    final id = _deviceId;
    if (id == null || _token == null || subStatus != 'active') return;
    try {
      final info = await _loadDeviceInfo();
      await api.registerDevice(
        fingerprint: id,
        platform: Platform.operatingSystem,
        name: _deviceName(),
        connected: vpn.isConnected,
        protocol: vpn.protocol.name,
        appVersion: MvpnConfig.appVersion,
        error: error,
        model: info['model'],
        osVersion: info['os_version'],
      );
    } on ApiException catch (e) {
      // Signed out remotely (admin removed a device): leave instead of
      // retrying with dead keys.
      if (e.status == 401 || e.deviceRevoked) await _signedOut(e);
    } catch (_) {
      // Best effort: telemetry must never get in the way of the tunnel.
    }
  }

  /// Android: battery savers (Infinix/Tecno XOS, Xiaomi, Huawei...) stop the
  /// VPN in the background unless the app is exempt. Asked once, after the
  /// first connect. Not in the Play build (its policy limits this request).
  Future<void> _askBatteryExemptionOnce() async {
    if (!Platform.isAndroid || MvpnConfig.playStore) return;
    try {
      final prefs = await SharedPreferences.getInstance();
      if (prefs.getBool('mvpn_battery_asked') ?? false) return;
      const ch = MethodChannel('com.hiddify.app/platform');
      if (await ch.invokeMethod<bool>('is_ignoring_battery_optimizations') ?? true) return;
      await prefs.setBool('mvpn_battery_asked', true);
      await ch.invokeMethod<bool>('request_ignore_battery_optimizations');
    } catch (_) {}
  }

  Future<Map<String, String>> _loadDeviceInfo() async {
    if (_deviceInfo != null) return _deviceInfo!;
    var info = <String, String>{'os_version': Platform.operatingSystemVersion};
    if (Platform.isAndroid) {
      try {
        final r = await const MethodChannel('com.hiddify.app/method')
            .invokeMapMethod<String, String>('device_info');
        if (r != null) info = r;
      } catch (_) {}
    } else {
      try {
        info['model'] = Platform.localHostname;
      } catch (_) {}
    }
    return _deviceInfo = info;
  }

  Future<void> _useHardwareId(String legacy) async {
    final hw = await _hardwareId();
    if (hw == null) return;
    _deviceId = _hash(hw);
    ApiClient.deviceId = _deviceId;
    ApiClient.previousDeviceId = legacy;
  }

  /// ANDROID_ID on Android (survives reinstall), MachineGuid on Windows.
  Future<String?> _hardwareId() async {
    try {
      if (Platform.isAndroid) {
        final id = (await _loadDeviceInfo())['hardware_id'];
        return (id == null || id.isEmpty) ? null : 'android:$id';
      }
      if (Platform.isWindows) {
        final r = await Process.run('reg', [
          'query',
          r'HKLM\SOFTWARE\Microsoft\Cryptography',
          '/v',
          'MachineGuid',
        ]);
        final m = RegExp(r'MachineGuid\s+REG_SZ\s+(\S+)').firstMatch('${r.stdout}');
        return m == null ? null : 'windows:${m.group(1)}';
      }
    } catch (_) {}
    return null;
  }

  static String _hash(String hw) =>
      sha256.convert(utf8.encode('mvpn-device:$hw')).toString().substring(0, 32);

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
    // The random per-install id changed on every reinstall, so one phone
    // showed up as several devices. Prefer an id tied to the hardware and
    // keep the old one so the server can merge the existing row.
    var legacy = prefs.getString('mvpn_device_id');
    if (legacy == null) {
      legacy = _newDeviceId();
      await prefs.setString('mvpn_device_id', legacy);
    }
    _deviceId = legacy;
    ApiClient.deviceId = legacy;
    // Looked up in the background so start-up never waits on it (the update
    // check does: the server answers per platform by this id).
    _idReady = _useHardwareId(legacy);
    _reconnectAfterUpdate = prefs.getBool(AppUpdater.reconnectKey) ?? false;
    await prefs.remove(AppUpdater.reconnectKey);
    unawaited(checkForUpdate());
    // The desktop app can sit in the tray for days without a "resumed".
    _updateTimer = Timer.periodic(const Duration(hours: 6), (_) => checkForUpdate());
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

  /// Shown once on the sign-in screen after a remote sign-out.
  String? signOutNotice;

  Future<void> _signedOut(ApiException e) async {
    signOutNotice = e.deviceRevoked
        ? e.message
        : 'You were signed out. Please sign in again.';
    if (vpn.status != VpnStatus.disconnected) await vpn.disconnect();
    await logout();
  }

  // ---- connect straight after an online payment -----------------------

  bool _connectOnResume = false;

  /// Paid: bring the VPN up. The customer may still be in the browser, and
  /// Android only shows the VPN permission prompt to a foreground app, so
  /// wait for the app to come back if needed.
  void connectAfterPayment() {
    final fg = WidgetsBinding.instance.lifecycleState;
    if (fg == null || fg == AppLifecycleState.resumed) {
      unawaited(vpn.connect());
    } else {
      _connectOnResume = true;
    }
  }

  // ---- app updates -----------------------------------------------------

  /// Newer version on the server, and where to get it for this platform.
  String? updateVersion;
  String? updateUrl;

  /// This build is below the server's minimum: it must update.
  bool updateRequired = false;
  DateTime? _lastUpdateCheck;
  Timer? _updateTimer;

  final updater = AppUpdater();

  /// Set by the desktop shell: removes the tray icon before the app quits.
  Future<void> Function()? beforeExit;

  /// The installer restarted the app: bring the VPN back if it was on.
  bool _reconnectAfterUpdate = false;

  Future<void> checkForUpdate() async {
    // Play builds update through Google Play only (Play policy).
    if (MvpnConfig.playStore) return;
    final last = _lastUpdateCheck;
    if (last != null && DateTime.now().difference(last) < const Duration(hours: 6)) return;
    _lastUpdateCheck = DateTime.now();
    try {
      await _idReady;
      final r = await api.appVersion();
      final latest = r['latest'] as String?;
      final min = r['min_supported'] as String?;
      final url = (Platform.isAndroid
          ? r['android']
          : Platform.isWindows
          ? r['windows']
          : r['page']) as String?;
      updateVersion = latest != null && isNewerVersion(latest, MvpnConfig.appVersion) ? latest : null;
      updateUrl = url;
      updateRequired = min != null && isNewerVersion(min, MvpnConfig.appVersion);
      notifyListeners();
    } catch (_) {
      // Offline or older server: try again next time.
      return;
    }
    if (!AppUpdater.supported) return;
    final v = updateVersion, url = updateUrl;
    if (v == null || url == null) {
      unawaited(updater.cleanUp());
      return;
    }
    // Fetched ahead so "Update now" in the popup is quick; installing waits
    // for the tap, unless this version is no longer supported.
    await updater.download(v, url, auto: true);
    if (updateRequired && updater.ready != null && Platform.isWindows) {
      await installUpdate();
    }
  }

  /// The notice's button where [AppUpdater.supported]: download (now, on
  /// any network) and install.
  Future<void> startUpdate() async {
    final v = updateVersion, url = updateUrl;
    if (v == null || url == null) return;
    if (updater.ready == null || updater.version != v) await updater.download(v, url);
    await installUpdate();
  }

  Future<void> installUpdate() => updater.install(
    beforeQuit: () async {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(
        AppUpdater.reconnectKey,
        vpn.status == VpnStatus.connected || vpn.status == VpnStatus.reconnecting,
      );
      if (vpn.status != VpnStatus.disconnected) await vpn.disconnect();
      await beforeExit?.call();
    },
  );

  /// "1.0.13" > "1.0.9" (numeric, part by part).
  static bool isNewerVersion(String a, String b) {
    List<int> parts(String v) => v.split(RegExp(r'[.+-]')).map((p) => int.tryParse(p) ?? 0).toList();
    final x = parts(a), y = parts(b);
    for (var i = 0; i < 3; i++) {
      final xi = i < x.length ? x[i] : 0, yi = i < y.length ? y[i] : 0;
      if (xi != yi) return xi > yi;
    }
    return false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(checkForUpdate());
      updater.onResume();
    }
    if (state == AppLifecycleState.resumed && _connectOnResume) {
      _connectOnResume = false;
      if (subStatus == 'active') unawaited(vpn.connect());
    }
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
        vpn.maybeAutoConnect(force: _reconnectAfterUpdate);
        _reconnectAfterUpdate = false;
      } else {
        gate = AuthGate.needsPlan;
      }
    } on ApiException catch (e) {
      if (e.status == 401 || e.deviceRevoked) {
        await _signedOut(e);
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
