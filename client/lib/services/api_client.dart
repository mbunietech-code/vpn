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

  Future<Map<String, dynamic>> me() => _get('/api/me');

  Future<Map<String, dynamic>> plans() => _get('/api/plans');

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
  }) =>
      _post('/api/subscription/device', {
        'fingerprint': fingerprint,
        'platform': platform,
        'name': ?name,
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
    throw ApiException(0, {'message': 'Server haipatikani. Angalia mtandao.', 'cause': '$lastErr'});
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
