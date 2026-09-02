import 'dart:convert';

import '../models.dart';

/// Parses the control-plane /sub/{token} bundle (base64 of newline-separated
/// vless:// and hysteria2:// share links) into [VpnNode]s.
///
/// One VpnNode per distinct host; VLESS and Hysteria2 links to the same host
/// collapse into a single selectable node (protocol is chosen at connect time).
class SubParser {
  static const _regionByCity = <String, String>{
    'hong kong': 'Asia-Pacific',
    'tokyo': 'Asia-Pacific',
    'singapore': 'Asia-Pacific',
    'seoul': 'Asia-Pacific',
    'osaka': 'Asia-Pacific',
    'frankfurt': 'Europe',
    'london': 'Europe',
    'amsterdam': 'Europe',
    'zurich': 'Europe',
    'paris': 'Europe',
    'new york': 'Americas',
    'toronto': 'Americas',
    'los angeles': 'Americas',
    'miami': 'Americas',
  };

  static List<VpnNode> parse(String body) {
    String text;
    try {
      text = utf8.decode(base64.decode(body.trim()));
    } catch (_) {
      text = body; // some servers return plain text
    }

    final seen = <String, VpnNode>{};
    for (final raw in const LineSplitter().convert(text)) {
      final line = raw.trim();
      if (line.isEmpty) continue;
      Uri uri;
      try {
        uri = Uri.parse(line);
      } catch (_) {
        continue;
      }
      if (uri.scheme != 'vless' && uri.scheme != 'hysteria2') continue;

      final host = uri.host;
      if (host.isEmpty) continue;
      final label = Uri.decodeComponent(uri.fragment).replaceFirst('MVPN ', '').trim();
      final name = label.isEmpty ? host : label;

      seen.putIfAbsent(
        host,
        () => VpnNode(
          id: host,
          name: name,
          code: uri.queryParameters['sni'] ?? host,
          region: _region(name),
          latencyMs: 0, // filled by a later ping pass
        ),
      );
    }

    final nodes = seen.values.toList()
      ..sort((a, b) => a.region.compareTo(b.region));
    return nodes;
  }

  static String _region(String name) {
    final n = name.toLowerCase();
    for (final entry in _regionByCity.entries) {
      if (n.contains(entry.key)) return entry.value;
    }
    return 'Servers';
  }
}
