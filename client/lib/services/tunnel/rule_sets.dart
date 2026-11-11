import 'dart:io';

import 'package:http/http.dart' as http;

import '../relay.dart';

/// Turns the config's remote rule sets into local files the app downloads
/// itself, before sing-box starts.
///
/// sing-box stops with FATAL when a remote rule set cannot load at start-up.
/// It cannot verify the relay's self-signed certificate (only the app has its
/// pin), and a config fetched through the relay names the relay as the rule
/// set host, so every connect failed with "x509: certificate signed by
/// unknown authority" (seen 2026-11-10). Downloading here uses the pinned
/// client, keeps a copy for offline starts, and drops a rule set that cannot
/// be had at all: China traffic then goes through the tunnel, which still
/// works, instead of the engine refusing to start.
///
/// Returns notes for the admin diagnostics (empty when all went well).
Future<List<String>> localizeRuleSets(
  Map<String, dynamic> config,
  Directory dir,
  http.Client client,
) async {
  final route = config['route'];
  if (route is! Map) return const [];
  final sets = route['rule_set'];
  if (sets is! List) return const [];

  final rulesDir = Directory('${dir.path}${Platform.pathSeparator}rules')
    ..createSync(recursive: true);
  final notes = <String>[];
  final dropped = <String>{};
  final kept = <Object?>[];

  for (final rs in sets) {
    if (rs is! Map || rs['type'] != 'remote' || rs['url'] is! String) {
      kept.add(rs);
      continue;
    }
    final tag = rs['tag']?.toString() ?? 'rules';
    final safe = tag.replaceAll(RegExp(r'[^A-Za-z0-9_.-]'), '_');
    final format = rs['format']?.toString() ?? 'binary';
    final file = File(
      '${rulesDir.path}${Platform.pathSeparator}$safe.${format == 'source' ? 'json' : 'srs'}',
    );
    try {
      await _download(client, Uri.parse(rs['url'] as String), file);
    } catch (e) {
      if (!file.existsSync() || file.lengthSync() == 0) {
        dropped.add(tag);
        notes.add('rule-set $tag dropped (${rs['url']}: $e)');
        continue;
      }
      notes.add('rule-set $tag from cache (${rs['url']}: $e)');
    }
    kept.add({'type': 'local', 'tag': tag, 'format': format, 'path': file.path});
  }

  route['rule_set'] = kept;
  if (dropped.isNotEmpty) {
    _dropRulesUsing(route['rules'], dropped);
    final dns = config['dns'];
    if (dns is Map) _dropRulesUsing(dns['rules'], dropped);
  }
  return notes;
}

/// Tries the URL as given (Relay.get falls back from the main address to the
/// relay), then a relay URL on the main address.
Future<void> _download(http.Client client, Uri uri, File file) async {
  Object? lastErr;
  for (final u in [uri, ?Relay.primary(uri)]) {
    try {
      final res = await Relay.get(
        client,
        u,
        timeout: const Duration(seconds: 15),
      );
      if (res.statusCode != 200 || res.bodyBytes.isEmpty) {
        throw HttpException('HTTP ${res.statusCode}', uri: u);
      }
      await file.writeAsBytes(res.bodyBytes, flush: true);
      return;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr ?? StateError('no URL');
}

/// Removes the rule-set tags from rules; a rule left with none is removed
/// (it would otherwise match everything).
void _dropRulesUsing(Object? rules, Set<String> tags) {
  if (rules is! List) return;
  rules.removeWhere((r) {
    if (r is! Map) return false;
    final rs = r['rule_set'];
    if (rs is String) return tags.contains(rs);
    if (rs is! List) return false;
    rs.removeWhere(tags.contains);
    return rs.isEmpty;
  });
}
