import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mvpn/services/tunnel/clash_traffic.dart';
import 'package:mvpn/services/tunnel/tunnel_engine.dart';

void main() {
  late int calls;
  late StreamController<EngineTraffic> out;
  late ClashTraffic poller;

  setUp(() {
    calls = 0;
    out = StreamController<EngineTraffic>.broadcast();
    ClashTraffic.visible.value = true;
    poller = ClashTraffic(
      MockClient((req) async {
        calls++;
        return http.Response(
          jsonEncode({'uploadTotal': 1000 * calls, 'downloadTotal': 5000 * calls}),
          200,
        );
      }),
      out,
    );
  });

  tearDown(() {
    poller.stop();
    out.close();
    ClashTraffic.visible.value = true;
  });

  test('polls while the tunnel is up and the app is visible', () async {
    final first = out.stream.first;
    poller.start();
    expect(poller.polling, isTrue);
    final t = await first;
    expect(t.downBytes, 5000);
    // The first sample after a (re)start only sets the baseline.
    expect(t.downBps, 0);
  });

  test('pauses in the background and resumes on screen', () async {
    poller.start();
    await Future<void>.delayed(const Duration(milliseconds: 50)); // first poll lands
    ClashTraffic.visible.value = false;
    expect(poller.polling, isFalse);
    final before = calls;
    await Future<void>.delayed(const Duration(milliseconds: 1200));
    expect(calls, before, reason: 'no requests while hidden');
    ClashTraffic.visible.value = true;
    expect(poller.polling, isTrue);
  });

  test('stops with the tunnel even when visible', () {
    poller.start();
    poller.stop();
    expect(poller.polling, isFalse);
    ClashTraffic.visible.value = false;
    ClashTraffic.visible.value = true;
    expect(poller.polling, isFalse);
  });
}
