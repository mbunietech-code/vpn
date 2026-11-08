import 'dart:async';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:http/http.dart' as http;
import 'package:http/io_client.dart';

/// Second address of the control plane, on the Kuala Lumpur node, for
/// networks where vpn.mbuniehub.com (behind Cloudflare) is blocked — China,
/// mostly. It forwards every request to vpn.mbuniehub.com unchanged.
///
/// It has no domain, so its certificate is self-signed and pinned here
/// (SHA-256 of the DER certificate, /etc/mvpn/relay/relay.crt on the node).
class Relay {
  static const host = '187.52.115.154';
  static const port = 2096;
  static const base = 'https://$host:$port';
  static const _primaryHost = 'vpn.mbuniehub.com';
  static const _pin =
      'b15832e5e8b81291e631dfcd7b85de71d28f3dd7d9b96173a0a111567b886997';

  /// An HTTP client that also trusts the relay (only the pinned certificate,
  /// only on the relay's address); every other host is checked as usual.
  static http.Client client() => IOClient(
    HttpClient()
      ..badCertificateCallback = (cert, h, p) =>
          h == host && p == port && sha256.convert(cert.der).toString() == _pin,
  );

  /// The same URL through the relay, or null when [uri] isn't on the
  /// control plane.
  static Uri? alternate(Uri uri) => uri.host == _primaryHost
      ? uri.replace(scheme: 'https', host: host, port: port)
      : null;

  /// GET [uri]; if the control plane can't be reached at all, try once more
  /// through the relay.
  static Future<http.Response> get(
    http.Client client,
    Uri uri, {
    Map<String, String>? headers,
    Duration timeout = const Duration(seconds: 20),
  }) async {
    try {
      return await client.get(uri, headers: headers).timeout(timeout);
    } catch (e) {
      final alt = alternate(uri);
      if (alt == null || !_unreachable(e)) rethrow;
      return client.get(alt, headers: headers).timeout(timeout);
    }
  }

  static bool _unreachable(Object e) =>
      e is SocketException ||
      e is HandshakeException ||
      e is http.ClientException ||
      e is TimeoutException;
}
