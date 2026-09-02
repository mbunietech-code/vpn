import 'dart:convert';

import 'package:flutter/foundation.dart';

/// Thin client for the MVPN control plane.
///
/// Not yet wired into the UI (the app currently runs on [VpnController]'s
/// simulated data). Ready for Phase 5 when the Hiddify-fork engine + real
/// auth/checkout land. Uses `HttpClient` from dart:io via a pluggable sender
/// so tests can stub it; kept dependency-free for now.
class ApiClient {
  ApiClient({required this.baseUrl, this.token, HttpSender? sender})
      : _send = sender ?? _defaultSender;

  final String baseUrl;
  String? token;
  final HttpSender _send;

  Future<Map<String, dynamic>> requestOtp(String identifier) =>
      _post('/api/auth/otp/request', {'identifier': identifier});

  Future<Map<String, dynamic>> verifyOtp(
          String identifier, String code, String deviceName) =>
      _post('/api/auth/otp/verify', {
        'identifier': identifier,
        'code': code,
        'device_name': deviceName,
      });

  Future<Map<String, dynamic>> plans() => _get('/api/plans');

  Future<Map<String, dynamic>> checkout(
          {required String plan, required String provider, required String currency}) =>
      _post('/api/checkout',
          {'plan': plan, 'provider': provider, 'currency': currency});

  Future<Map<String, dynamic>> subscription() => _get('/api/subscription');

  Future<Map<String, dynamic>> registerDevice(
          {required String fingerprint, required String platform, String? name}) =>
      _post('/api/subscription/device', {
        'fingerprint': fingerprint,
        'platform': platform,
        'name': ?name,
      });

  /// Fetches the raw subscription bundle from /sub/{token}.
  Future<String> fetchSubscription(String subUrl) async {
    final res = await _send('GET', subUrl, null, _headers());
    return res.body;
  }

  // ---- internals --------------------------------------------------------

  Map<String, String> _headers() => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> _get(String path) async {
    final res = await _send('GET', '$baseUrl$path', null, _headers());
    return _decode(res);
  }

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    final res =
        await _send('POST', '$baseUrl$path', jsonEncode(body), _headers());
    return _decode(res);
  }

  Map<String, dynamic> _decode(HttpResponseLite res) {
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
  @override
  String toString() => 'ApiException($status): $body';
}

@immutable
class HttpResponseLite {
  const HttpResponseLite(this.statusCode, this.body);
  final int statusCode;
  final String body;
}

typedef HttpSender = Future<HttpResponseLite> Function(
  String method,
  String url,
  String? body,
  Map<String, String> headers,
);

Future<HttpResponseLite> _defaultSender(
    String method, String url, String? body, Map<String, String> headers) async {
  // Deferred import of dart:io to keep this file usable in pure-Dart tests.
  throw UnimplementedError(
      'Wire a real HTTP sender (package:http) when auth/checkout are enabled.');
}
