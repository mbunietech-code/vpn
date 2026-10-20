import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/services/sub_parser.dart';

void main() {
  test('parses vless + hysteria2 links, collapsing per host', () {
    final bundle = base64.encode(utf8.encode([
      'vless://uuid-1@hk1.mbunievpn.com:443?type=tcp&security=reality&sni=www.microsoft.com&pbk=KEY&sid=ab#MVPN%20Hong%20Kong%201',
      'hysteria2://pw-1@hk1.mbunievpn.com:443?sni=www.microsoft.com&insecure=1#MVPN%20Hong%20Kong%201',
      'vless://uuid-2@fra1.mbunievpn.com:443?type=tcp&security=reality&sni=a.com&pbk=K2&sid=cd#MVPN%20Frankfurt%201',
    ].join('\n')));

    final nodes = SubParser.parse(bundle);

    // Each node plus its OpenVPN entry, right after it.
    expect(nodes.length, 4);
    final hk = nodes.firstWhere((n) => n.id == 'hk1.mbunievpn.com');
    expect(hk.name, 'Hong Kong 1');
    expect(hk.region, 'Asia-Pacific');
    expect(hk.code, 'Auto · Hysteria2 + REALITY');
    expect(hk.code, isNot(contains('www.microsoft.com')));
    expect(nodes.firstWhere((n) => n.id == 'fra1.mbunievpn.com').region, 'Europe');

    final hkIndex = nodes.indexOf(hk);
    final hkOvpn = nodes[hkIndex + 1];
    expect(hkOvpn.id, 'hk1.mbunievpn.com#openvpn');
    expect(hkOvpn.name, 'Hong Kong 1 · OpenVPN');
    expect(hkOvpn.isOpenVpn, isTrue);
    expect(hkOvpn.selectorTag, 'Hong Kong 1 · OpenVPN');
    expect(hk.isOpenVpn, isFalse);
    expect(hk.selectorTag, 'auto');
    expect(hkOvpn.region, hk.region);
  });

  test('an (IPv6) host is its own entry, without OpenVPN', () {
    final bundle = base64.encode(utf8.encode([
      'vless://u@n1.example.com:8443?sni=x&pbk=k&sid=1#MVPN%20Kuala%20Lumpur%201',
      'vless://u@n1v6.example.com:8443?sni=x&pbk=k&sid=1#MVPN%20Kuala%20Lumpur%201%20%28IPv6%29',
      'hysteria2://p@n1v6.example.com:8443?sni=x#MVPN%20Kuala%20Lumpur%201%20%28IPv6%29',
    ].join('\n')));

    final nodes = SubParser.parse(bundle);

    expect(nodes.map((n) => n.name), [
      'Kuala Lumpur 1',
      'Kuala Lumpur 1 · OpenVPN',
      'Kuala Lumpur 1 (IPv6)',
    ]);
    final v6 = nodes.last;
    expect(v6.isIpv6, isTrue);
    expect(v6.selectorTag, 'Kuala Lumpur 1 (IPv6)');
    expect(nodes.first.selectorTag, 'auto');
  });

  test('tolerates a plain-text (non-base64) body', () {
    const body =
        'vless://u@sg1.example.com:443?sni=x&pbk=k&sid=1#MVPN%20Singapore';
    final nodes = SubParser.parse(body);
    expect(nodes.map((n) => n.name), ['Singapore', 'Singapore · OpenVPN']);
  });
}
