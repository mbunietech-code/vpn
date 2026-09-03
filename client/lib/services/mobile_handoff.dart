import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../l10n/app_text.dart';

/// On Android the account app does not carry traffic itself — the separately
/// installed **Mbunie VPN Engine** (a sing-box/Hiddify fork) does. This hands
/// the active subscription bundle to that app over the `mvpn://` deep link,
/// and, if the engine is not installed, routes the user to its download page.
abstract class MobileHandoff {
  static const _primedKey = 'mvpn_engine_primed';

  /// Returns true when the engine app accepted the deep link.
  static Future<bool> connect(BuildContext context, String? subUrl) async {
    final tr = context.tr;
    if (subUrl == null || subUrl.isEmpty) {
      _snack(context, tr.t('handoff.noSub'));
      return false;
    }

    final prefs = await SharedPreferences.getInstance();
    final primed = prefs.getBool(_primedKey) ?? false;

    // Once the engine has the profile, a bare mvpn://connect avoids a
    // subscription re-fetch. Fall back to a full import if it doesn't take.
    if (primed && await _launch(MvpnConfig.engineConnectLink)) {
      return true;
    }

    final launched = await _launch(MvpnConfig.engineImportLink(subUrl));
    if (launched) {
      await prefs.setBool(_primedKey, true);
    } else if (context.mounted) {
      await _promptInstall(context);
    }
    return launched;
  }

  static Future<bool> _launch(Uri uri) async {
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  static Future<void> _promptInstall(BuildContext context) async {
    final tr = context.tr;
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.t('handoff.needEngineTitle')),
        content: Text(tr.t('handoff.needEngineBody')),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(tr.t('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(tr.t('handoff.getEngine')),
          ),
        ],
      ),
    );
    if (go == true) {
      await launchUrl(
        Uri.parse(MvpnConfig.engineAndroidDownloadUrl),
        mode: LaunchMode.externalApplication,
      );
    }
  }

  static void _snack(BuildContext context, String msg) {
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(msg)));
  }
}
