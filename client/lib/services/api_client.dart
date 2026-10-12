import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../config.dart';

/// Client for the MVPN control plane.
///
/// Holds an ordered list of base URLs (primary + China fallbacks). On a
/// connection-level failure it advances to the next and retries once, so a
/// blocked domain does not brick onboarding (FR-CN-09).
class ApiClient {
  ApiClient({String? baseUrl, this.token, http.Client? httpClient})
      : _bases = baseUrl != null ? [baseUrl] : MvpnConfig.apiBases,
        _http = httpClient ?? http.Client();

  /// This install's id, sent as X-Mvpn-Device so an admin can block a
  /// device the customer doesn't recognise.
  static String? deviceId;

  /// The id this install used before ids were tied to the hardware.
  static String? previousDeviceId;

  static Map<String, String> get deviceHeaders => {
        'X-Mvpn-Device': ?deviceId,
        'X-Mvpn-Device-Prev': ?previousDeviceId,
      };

  final List<String> _bases;
  int _active = 0;
  String? token;
  final http.Client _http;

  String get baseUrl => _bases[_active];

  Future<Map<String, dynamic>> requestOtp(String identifier) =>
      _post('/api/auth/otp/request', {'identifier': identifier});

  Future<Map<String, dynamic>> verifyOtp(
          String identifier, String code, String deviceName) =>
      _post('/api/auth/otp/verify', {
        'identifier': identifier,
        'code': code,
        'device_name': deviceName,
      });

  /// "Sign in with MbunieHub": EduHub credentials, verified by the control plane.
  Future<Map<String, dynamic>> loginEduHub(
          String email, String password, String deviceName) =>
      _post('/api/auth/eduhub', {
        'email': email,
        'password': password,
        'device_name': deviceName,
      });

  Future<Map<String, dynamic>> me() => _get('/api/me');

  Future<Map<String, dynamic>> plans() => _get('/api/plans');

  /// Newest app version and where to download it (public).
  Future<Map<String, dynamic>> appVersion() => _get('/api/app/version');

  Future<Map<String, dynamic>> checkout({
    required String plan,
    required String provider,
    required String currency,
  }) =>
      _post('/api/checkout',
          {'plan': plan, 'provider': provider, 'currency': currency});

  Future<Map<String, dynamic>> subscription() => _get('/api/subscription');

  // ---- v1 manual payment ----------------------------------------------

  Future<Map<String, dynamic>> paymentMethods() => _get('/api/payment-methods');

  Future<Map<String, dynamic>> manualCheckout({
    required String plan,
    required String currency,
    int? methodId,
  }) =>
      _post('/api/checkout/manual', {
        'plan': plan,
        'currency': currency,
        'method_id': ?methodId,
      });

  Future<void> uploadProof({
    required int invoiceId,
    required File image,
    String? note,
  }) async {
    final req = http.MultipartRequest(
        'POST', Uri.parse('$baseUrl/api/invoices/$invoiceId/proof'))
      ..headers['Accept'] = 'application/json'
      ..headers['Authorization'] = 'Bearer $token'
      ..headers.addAll(deviceHeaders)
      ..files.add(await http.MultipartFile.fromPath('proof', image.path));
    if (note != null && note.isNotEmpty) req.fields['note'] = note;

    final res = await http.Response.fromStream(await _http.send(req));
    if (res.statusCode >= 400) {
      throw ApiException(res.statusCode,
          res.body.isEmpty ? {} : jsonDecode(res.body) as Map<String, dynamic>);
    }
  }

  Future<Map<String, dynamic>> registerDevice({
    required String fingerprint,
    required String platform,
    String? name,
    bool? connected,
    String? protocol,
    String? appVersion,
    String? error,
    String? model,
    String? osVersion,
  }) =>
      _post('/api/subscription/device', {
        'fingerprint': fingerprint,
        'previous_fingerprint': ?previousDeviceId,
        'platform': platform,
        'name': ?name,
        'connected': ?connected,
        'protocol': ?protocol,
        'app_version': ?appVersion,
        'error': ?error,
        'model': ?model,
        'os_version': ?osVersion,
      });

  /// LOCAL-ONLY: simulate a completed payment (control plane must be in `local`).
  Future<Map<String, dynamic>> devPay() => _post('/api/dev/pay', {});

  /// Raw subscription bundle from an absolute /sub/{token} URL.
  Future<String> fetchSubscription(String subUrl) async {
    final res = await _http.get(Uri.parse(subUrl), headers: _headers());
    if (res.statusCode >= 400) {
      throw ApiException(res.statusCode, {'body': res.body});
    }
    return res.body;
  }

  // ---- internals ------------------------------------------------------

  Map<String, String> _headers() => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        ...deviceHeaders,
      };

  Future<Map<String, dynamic>> _get(String path) => _send(
        (base) => _http
            .get(Uri.parse('$base$path'), headers: _headers())
            .timeout(const Duration(seconds: 12)),
      );

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) => _send(
        (base) => _http
            .post(Uri.parse('$base$path'),
                headers: _headers(), body: jsonEncode(body))
            .timeout(const Duration(seconds: 15)),
      );

  /// Runs [attempt] against the active base URL; on a transport failure
  /// (no route, DNS, TLS, timeout) advances to the next base and retries.
  Future<Map<String, dynamic>> _send(
      Future<http.Response> Function(String base) attempt) async {
    Object? lastErr;
    for (var i = 0; i < _bases.length; i++) {
      try {
        return _decode(await attempt(baseUrl));
      } on ApiException {
        rethrow; // a real HTTP response — don't fail over
      } on SocketException catch (e) {
        lastErr = e;
      } on TimeoutException catch (e) {
        lastErr = e;
      } on http.ClientException catch (e) {
        lastErr = e;
      } on HandshakeException catch (e) {
        lastErr = e;
      }
      _active = (_active + 1) % _bases.length;
    }
    throw ApiException(0, {'message': 'Server unreachable. Check your internet connection.', 'cause': '$lastErr'});
  }

  Map<String, dynamic> _decode(http.Response res) {
    final data = res.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(res.body) as Map<String, dynamic>;
    if (res.statusCode >= 400) {
      throw ApiException(res.statusCode, data);
    }
    return data;
  }
}

class ApiException implements Exception {
  ApiException(this.status, this.body);
  final int status;
  final Map<String, dynamic> body;

  /// An admin removed this device from the account.
  bool get deviceRevoked => status == 403 && body['error'] == 'device_revoked';

  String get message {
    if (body['message'] is String) return body['message'] as String;
    if (body['errors'] is Map) {
      final errs = (body['errors'] as Map).values.first;
      if (errs is List && errs.isNotEmpty) return errs.first.toString();
    }
    return 'Request failed ($status)';
  }

  @override
  String toString() => 'ApiException($status): $message';
}
