import 'package:flutter_test/flutter_test.dart';
import 'package:mvpn/services/app_state.dart';

void main() {
  test('versions compare by number, not as text', () {
    expect(AppState.isNewerVersion('1.0.13', '1.0.9'), isTrue);
    expect(AppState.isNewerVersion('1.0.12', '1.0.12'), isFalse);
    expect(AppState.isNewerVersion('1.0.9', '1.0.10'), isFalse);
    expect(AppState.isNewerVersion('1.1.0', '1.0.99'), isTrue);
    expect(AppState.isNewerVersion('2.0.0+5', '1.9.9'), isTrue);
  });
}
