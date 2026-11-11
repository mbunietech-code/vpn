import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../l10n/app_text.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';

/// The official sing-box app for iPhone/iPad (SagerNet; listed on
/// sing-box.sagernet.org/clients/apple). Not in the mainland China store.
const singboxAppStore = 'https://apps.apple.com/app/sing-box-mt/id6785326793';

/// `<sub link>?format=singbox&platform=ios&protocol=auto`: the subscriber's
/// sing-box profile for iPhone (mobile TUN, gvisor, Hysteria2 first).
Uri iphoneProfileUri(String subUrl) {
  final uri = Uri.parse(subUrl);
  return uri.replace(
    queryParameters: {
      ...uri.queryParameters,
      'format': 'singbox',
      'platform': 'ios',
      'protocol': 'auto',
    },
  );
}

/// sing-box's one-tap import link: the iPhone camera opens sing-box with the
/// profile filled in (sing-box.sagernet.org/clients/general).
String singboxImportLink(String subUrl) =>
    'sing-box://import-remote-profile'
    '?url=${Uri.encodeComponent(iphoneProfileUri(subUrl).toString())}'
    '#${Uri.encodeComponent('Mbunie VPN')}';

/// Settings › Use on iPhone: until the Mbunie VPN iPhone app is out,
/// customers use the official sing-box app with their own profile. Shown on
/// Android, Windows and macOS; the QR is scanned with the iPhone camera.
class IphoneScreen extends StatelessWidget {
  const IphoneScreen({super.key, required this.subUrl});

  final String subUrl;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final tr = context.tr;
    final import = singboxImportLink(subUrl);

    Widget step(int n, String text) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 11,
            backgroundColor: c.brand.withValues(alpha: 0.12),
            child: Text(
              '$n',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: c.brand),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: TextStyle(fontSize: 14, height: 1.35, color: c.textPrimary)),
          ),
        ],
      ),
    );

    return Scaffold(
      appBar: AppBar(title: Text(tr.t('iphone.title'))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          MvpnCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                step(1, tr.t('iphone.step1')),
                Padding(
                  padding: const EdgeInsets.only(left: 32, bottom: 4),
                  child: TextButton.icon(
                    onPressed: () => launchUrl(
                      Uri.parse(singboxAppStore),
                      mode: LaunchMode.externalApplication,
                    ),
                    icon: const Icon(Icons.open_in_new_rounded, size: 16),
                    label: Text(tr.t('iphone.appStore')),
                  ),
                ),
                step(2, tr.t('iphone.step2')),
                const SizedBox(height: 10),
                Center(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      // Always dark on white: cameras read it in dark mode too.
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: c.border),
                    ),
                    child: QrImageView(
                      data: import,
                      size: 220,
                      backgroundColor: Colors.white,
                      errorCorrectionLevel: QrErrorCorrectLevel.M,
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                step(3, tr.t('iphone.step3')),
              ],
            ),
          ),
          const SizedBox(height: 12),
          InlineNotice(text: tr.t('iphone.private'), tone: NoticeTone.info),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () {
              Clipboard.setData(ClipboardData(text: iphoneProfileUri(subUrl).toString()));
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text(tr.t('iphone.copied'))),
              );
            },
            icon: const Icon(Icons.copy_rounded, size: 18),
            label: Text(tr.t('iphone.copy')),
          ),
          const SizedBox(height: 6),
          Text(
            tr.t('iphone.manual'),
            style: TextStyle(fontSize: 12, height: 1.35, color: c.textSecondary),
          ),
        ],
      ),
    );
  }
}
