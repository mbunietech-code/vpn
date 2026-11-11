/// Maps a connection failure to a short message key for the customer.
///
/// The full text (engine log lines, HTTP codes, URLs) is what goes to
/// Admin > Devices as the device's last error; customers only see one calm
/// line they can act on ("No internet", "Try again"). Keys live in
/// l10n/app_text.dart under `vpn.err.*`.
String friendlyErrorKey(String? raw) {
  if (raw == null || raw.isEmpty) return 'vpn.err.generic';
  // Already a message key (e.g. vpn.noSub).
  if (RegExp(r'^[a-z]+\.[A-Za-z.]+$').hasMatch(raw)) return raw;

  final s = raw.toLowerCase();
  bool any(List<String> words) => words.any(s.contains);

  if (any(['removed from the account', 'device_revoked'])) {
    return 'vpn.err.deviceRemoved';
  }
  if (s.contains('another vpn is on')) return 'vpn.err.otherVpn';
  if (s.contains('as administrator')) return 'vpn.err.admin';
  if (any(['mac password', 'enter your mac password'])) {
    return 'vpn.err.macPassword';
  }
  if (any(['android vpn request', 'did not start within'])) {
    return 'vpn.err.permission';
  }
  if (any(['reinstall'])) return 'vpn.err.reinstall';
  if (any([
    'no_active_subscription',
    'http 401',
    'http 402',
    'http 403',
  ])) {
    return 'vpn.err.account';
  }
  if (any([
    'could not reach',
    'unreachable',
    'socketexception',
    'failed host lookup',
    'no such host',
    'no address associated',
    'timeoutexception',
    'timed out',
    'connection refused',
    'connection reset',
    'connection closed',
    'clientexception',
    'handshakeexception',
    'no route',
    'network is down',
    'no internet',
  ])) {
    return 'vpn.err.offline';
  }
  return 'vpn.err.generic';
}
