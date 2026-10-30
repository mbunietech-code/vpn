import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

import '../config.dart';

/// Downloads and installs a newer app version without a trip to the browser.
///
/// The installer is fetched in the background (Android: on Wi-Fi only) and
/// installed when the user taps "Update now" in the popup or the notice.
/// Windows: the Inno Setup installer runs silently, the app quits for it and
/// the installer starts it again (`--after-update`), reconnecting if the VPN
/// was on. Android (sideloaded APK): opens the system installer, which always
/// needs one tap on Install. macOS and the Play build keep the "Update" link.
class AppUpdater extends ChangeNotifier {
  static const _method = MethodChannel('com.hiddify.app/method');

  /// Set before quitting for an update: the VPN was on, bring it back.
  static const reconnectKey = 'mvpn_reconnect_after_update';

  static bool get supported =>
      !MvpnConfig.playStore && (Platform.isWindows || Platform.isAndroid);

  /// The version being downloaded or ready to install.
  String? version;

  /// 0..1 while downloading, null otherwise.
  double? progress;

  /// The downloaded installer.
  File? ready;

  /// The last download or install failed (text key).
  String? error;

  /// Windows: the installer is starting and the app is about to quit.
  bool installing = false;

  bool _busy = false;
  bool _installWhenBack = false; // Android: back from "Install unknown apps"

  bool get downloading => progress != null;

  /// Downloads the installer of [newVersion]. [auto]: the app found the
  /// update itself, so on Android it waits for Wi-Fi (the APK is large).
  Future<void> download(String newVersion, String url, {bool auto = false}) async {
    if (_busy || (version == newVersion && ready != null)) return;
    final uri = Uri.tryParse(url);
    if (uri == null || !_trusted(uri)) return;
    if (auto && Platform.isAndroid && !await _unmetered()) return;

    _busy = true;
    version = newVersion;
    ready = null;
    error = null;
    progress = 0;
    notifyListeners();
    final client = http.Client();
    try {
      final dir = await _dir();
      final name = Platform.isWindows
          ? 'Mbunie-VPN-Setup-$newVersion.exe'
          : 'Mbunie-VPN-$newVersion.apk';
      final file = File('${dir.path}${Platform.pathSeparator}$name');
      if (!file.existsSync()) {
        // Older downloads are of no use any more.
        await _clear(dir);
        final res = await client
            .send(http.Request('GET', uri))
            .timeout(const Duration(seconds: 30));
        if (res.statusCode != 200) {
          throw HttpException('HTTP ${res.statusCode}', uri: uri);
        }
        final total = res.contentLength ?? 0;
        final part = File('${file.path}.part');
        final sink = part.openWrite();
        var got = 0, shown = -1;
        try {
          await for (final chunk in res.stream.timeout(const Duration(seconds: 60))) {
            sink.add(chunk);
            got += chunk.length;
            final pct = total > 0 ? got * 100 ~/ total : 0;
            if (pct != shown) {
              shown = pct;
              progress = total > 0 ? got / total : 0;
              notifyListeners();
            }
          }
        } finally {
          await sink.close();
        }
        // A cut connection must never reach the installer.
        if ((total > 0 && got != total) || got < 1024 * 1024) {
          await part.delete();
          throw HttpException('incomplete download ($got of $total bytes)', uri: uri);
        }
        await part.rename(file.path);
      }
      ready = file;
    } catch (_) {
      error = 'update.failed';
    } finally {
      progress = null;
      _busy = false;
      client.close();
      notifyListeners();
    }
  }

  /// Windows: runs the installer silently and quits the app ([beforeQuit]
  /// stops the VPN first). Android: opens the system installer.
  Future<void> install({required Future<void> Function() beforeQuit}) async {
    final file = ready;
    if (file == null || installing || !file.existsSync()) return;
    error = null;
    if (Platform.isWindows) {
      installing = true;
      notifyListeners();
      try {
        await beforeQuit();
        await Process.start(
          file.path,
          ['/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/SP-'],
          mode: ProcessStartMode.detached,
        );
      } catch (_) {
        installing = false;
        error = 'update.failed';
        notifyListeners();
        return;
      }
      exit(0);
    }
    if (Platform.isAndroid) {
      try {
        final r = await _method.invokeMethod<String>('install_apk', {'path': file.path});
        _installWhenBack = r == 'needs_permission';
      } catch (_) {
        error = 'update.failed';
        notifyListeners();
      }
    }
  }

  /// Back in the app: retry an install that needed "Install unknown apps".
  void onResume() {
    if (_installWhenBack) {
      _installWhenBack = false;
      unawaited(install(beforeQuit: () async {}));
    }
  }

  /// Up to date: drop old installers (the APK is ~190 MB).
  Future<void> cleanUp() async {
    if (_busy || ready != null) return;
    try {
      await _clear(await _dir());
    } catch (_) {}
  }

  Future<Directory> _dir() async {
    final tmp = await getTemporaryDirectory();
    return Directory('${tmp.path}${Platform.pathSeparator}updates')..createSync(recursive: true);
  }

  static Future<void> _clear(Directory dir) async {
    if (!dir.existsSync()) return;
    for (final f in dir.listSync()) {
      try {
        f.deleteSync(recursive: true);
      } catch (_) {}
    }
  }

  /// Only installers from our own control plane, over HTTPS.
  static bool _trusted(Uri uri) {
    if (uri.scheme != 'https') return false;
    final hosts = MvpnConfig.apiBases.map((b) => Uri.tryParse(b)?.host).toSet();
    return hosts.contains(uri.host);
  }

  static Future<bool> _unmetered() async {
    try {
      return await _method.invokeMethod<bool>('is_unmetered') ?? false;
    } catch (_) {
      return false;
    }
  }
}
