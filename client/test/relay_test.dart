import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/config.dart';
import 'package:mvpn/services/relay.dart';

void main() {
  test('control-plane URLs map onto the relay, path and query kept', () {
    final alt = Relay.alternate(
      Uri.parse('https://vpn.mbuniehub.com/sub/abc?format=singbox&platform=windows'),
    );
    expect(
      alt.toString(),
      'https://187.52.115.154:2096/sub/abc?format=singbox&platform=windows',
    );
  });

  test('other hosts are left alone', () {
    expect(Relay.alternate(Uri.parse('https://example.com/x')), isNull);
  });

  test('the relay is tried right after the main address', () {
    final bases = MvpnConfig.apiBases;
    expect(bases[0], 'https://vpn.mbuniehub.com');
    expect(bases[1], Relay.base);
  });
}
