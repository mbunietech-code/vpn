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

    expect(nodes.length, 2);
    final hk = nodes.firstWhere((n) => n.id == 'hk1.mbunievpn.com');
    expect(hk.name, 'Hong Kong 1');
    expect(hk.region, 'Asia-Pacific');
    expect(nodes.firstWhere((n) => n.id == 'fra1.mbunievpn.com').region, 'Europe');
  });

  test('tolerates a plain-text (non-base64) body', () {
    const body =
        'vless://u@sg1.example.com:443?sni=x&pbk=k&sid=1#MVPN%20Singapore';
    final nodes = SubParser.parse(body);
    expect(nodes.single.name, 'Singapore');
  });
}
