import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mvpn/services/friendly_error.dart';
import 'package:mvpn/services/tunnel/rule_sets.dart';

// Through JSON, like a real config (untyped lists and maps).
Map<String, dynamic> _config() => jsonDecode(jsonEncode({
  'dns': {
    'rules': [
      {'domain': ['n1.mbuniehub.com'], 'server': 'direct-dns'},
      {'rule_set': ['geosite-cn'], 'server': 'direct-dns'},
    ],
  },
  'route': {
    'rules': [
      {'ip_is_private': true, 'outbound': 'direct'},
      {'rule_set': ['geosite-cn', 'geoip-cn'], 'outbound': 'direct'},
    ],
    'rule_set': [
      for (final tag in ['geosite-cn', 'geoip-cn'])
        {
          'type': 'remote',
          'tag': tag,
          'format': 'binary',
          'url': 'https://187.52.115.154:2096/rules/$tag.srs',
          'download_detour': 'direct',
        },
    ],
  },
})) as Map<String, dynamic>;

void main() {
  late Directory dir;
  setUp(() => dir = Directory.systemTemp.createTempSync('mvpn-rules'));
  tearDown(() => dir.deleteSync(recursive: true));

  group('localizeRuleSets', () {
    test('China rule sets become local files; China rules unchanged', () async {
      final asked = <String>[];
      final client = MockClient((req) async {
        asked.add(req.url.toString());
        return http.Response.bytes([1, 2, 3], 200);
      });
      final cfg = _config();
      final notes = await localizeRuleSets(cfg, dir, client);

      expect(notes, isEmpty);
      final sets = cfg['route']['rule_set'] as List;
      expect(sets, hasLength(2));
      for (final s in sets) {
        expect(s['type'], 'local');
        expect(File(s['path'] as String).readAsBytesSync(), [1, 2, 3]);
      }
      expect((cfg['route']['rules'] as List).last, {
        'rule_set': ['geosite-cn', 'geoip-cn'],
        'outbound': 'direct',
      });
      expect((cfg['dns']['rules'] as List).last['rule_set'], ['geosite-cn']);
      expect(asked.first, contains('187.52.115.154:2096'));
    });

    test('relay fails: tries the main address', () async {
      final client = MockClient((req) async {
        if (req.url.host == '187.52.115.154') {
          throw const SocketException('relay down');
        }
        return http.Response.bytes([9], 200);
      });
      final cfg = _config();
      final notes = await localizeRuleSets(cfg, dir, client);
      expect(notes, isEmpty);
      expect((cfg['route']['rule_set'] as List).first['type'], 'local');
    });

    test('offline: uses the cached copy', () async {
      final ok = MockClient((_) async => http.Response.bytes([7], 200));
      await localizeRuleSets(_config(), dir, ok);

      final offline = MockClient((_) async => throw const SocketException('x'));
      final cfg = _config();
      final notes = await localizeRuleSets(cfg, dir, offline);
      expect(notes.join(), contains('from cache'));
      expect((cfg['route']['rules'] as List).last['rule_set'], [
        'geosite-cn',
        'geoip-cn',
      ]);
    });

    test('nothing at all: drops them instead of a FATAL start', () async {
      final offline = MockClient((_) async => throw const SocketException('x'));
      final cfg = _config();
      final notes = await localizeRuleSets(cfg, dir, offline);
      expect(notes.join(), contains('dropped'));
      expect(cfg['route']['rule_set'], isEmpty);
      expect(cfg['route']['rules'], [
        {'ip_is_private': true, 'outbound': 'direct'},
      ]);
      expect(cfg['dns']['rules'], hasLength(1));
    });
  });

  group('friendlyErrorKey', () {
    test('engine output is never shown to the customer', () {
      expect(
        friendlyErrorKey(
          'VPN engine failed (code 1):\nFATAL[0001] start service: x509: '
          'certificate signed by unknown authority',
        ),
        'vpn.err.generic',
      );
    });
    test('network problems read as no internet', () {
      expect(
        friendlyErrorKey(
          'Could not reach the server to get the VPN config. Check your internet',
        ),
        'vpn.err.offline',
      );
      expect(friendlyErrorKey('SocketException: Failed host lookup'), 'vpn.err.offline');
    });
    test('actionable cases keep their own message', () {
      expect(
        friendlyErrorKey('Another VPN is on (Cloudflare WARP). Turn it off'),
        'vpn.err.otherVpn',
      );
      expect(
        friendlyErrorKey('Run Mbunie VPN as Administrator (right-click …)'),
        'vpn.err.admin',
      );
      expect(
        friendlyErrorKey('This device was removed from the account.'),
        'vpn.err.deviceRemoved',
      );
      expect(friendlyErrorKey('vpn.noSub'), 'vpn.noSub');
    });
  });
}
