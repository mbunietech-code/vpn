import 'dart:convert';

import 'package:http/http.dart' as http;

/// Client for the MVPN control plane.
class ApiClient {
  ApiClient({required this.baseUrl, this.token, http.Client? httpClient})
      : _http = httpClient ?? http.Client();

  String baseUrl;
  String? token;
  final http.Client _http;

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

  Future<Map<String, dynamic>> _get(String path) async {
    final res = await _http.get(Uri.parse('$baseUrl$path'), headers: _headers());
    return _decode(res);
  }

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    final res = await _http.post(
      Uri.parse('$baseUrl$path'),
      headers: _headers(),
      body: jsonEncode(body),
    );
    return _decode(res);
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
