import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../l10n/app_text.dart';
import '../models.dart';
import '../services/app_state.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';
import 'onboarding.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    final vpn = state.vpn;
    final c = context.mvpn;
    final tr = context.tr;

    return Scaffold(
      appBar: AppBar(title: Text(tr.t('settings.title'))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 28),
        children: [
          SectionCaption(tr.t('settings.account')),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  leading: _icon(c, Icons.person_rounded),
                  title: state.identifier ?? tr.t('settings.user'),
                  subtitle: state.planCode != null
                      ? '${state.planCode} · ${_expiry(state, tr)}'
                      : tr.t('settings.noPlan'),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  leading: _icon(c, Icons.workspace_premium_rounded),
                  title: tr.t('settings.planAndPay'),
                  trailing: Icon(Icons.chevron_right_rounded, color: c.textHint),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                      builder: (_) => const PlansScreen())),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  leading: _icon(c, Icons.translate_rounded),
                  title: tr.t('settings.language'),
                  trailing: Text(
                    state.localeOverride == null
                        ? '${AppText.names[tr.code]} (auto)'
                        : AppText.names[tr.code]!,
                    style: TextStyle(fontSize: 13, color: c.textSecondary),
                  ),
                  onTap: () => _pickLanguage(context, state),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  leading: _icon(c, Icons.logout_rounded, danger: true),
                  title: tr.t('settings.logout'),
                  onTap: () => _confirmLogout(context, state, tr),
                ),
              ],
            ),
          ),
          SectionCaption(tr.t('settings.connection')),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: tr.t('settings.killSwitch'),
                  subtitle: tr.t('settings.killSwitchSub'),
                  trailing: Switch(
                    value: vpn.killSwitch,
                    onChanged: (v) => vpn.update(() => vpn.killSwitch = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.autoConnect'),
                  subtitle: tr.t('settings.autoConnectSub'),
                  trailing: Switch(
                    value: vpn.autoConnect,
                    onChanged: (v) => vpn.update(() => vpn.autoConnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.autoReconnect'),
                  subtitle: tr.t('settings.autoReconnectSub'),
                  trailing: Switch(
                    value: vpn.autoReconnect,
                    onChanged: (v) => vpn.update(() => vpn.autoReconnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.protocol'),
                  subtitle: switch (vpn.protocol) {
                    ProtocolPref.auto => tr.t('settings.protoAuto'),
                    ProtocolPref.vlessReality => tr.t('settings.protoReality'),
                    ProtocolPref.hysteria2 => tr.t('settings.protoHy2'),
                  },
                  trailing: DropdownButton<ProtocolPref>(
                    value: vpn.protocol,
                    underline: const SizedBox.shrink(),
                    borderRadius: BorderRadius.circular(12),
                    items: [
                      for (final p in ProtocolPref.values)
                        DropdownMenuItem(value: p, child: Text(p.label)),
                    ],
                    onChanged: (p) =>
                        p == null ? null : vpn.update(() => vpn.protocol = p),
                  ),
                ),
              ],
            ),
          ),
          SectionCaption(tr.t('settings.about')),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: tr.t('settings.version'),
                  trailing: Text(MvpnConfig.appVersion,
                      style: TextStyle(fontSize: 13, color: c.textSecondary)),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.copyPeer'),
                  subtitle: vpn.peerId,
                  trailing: Icon(Icons.copy_rounded, size: 17, color: c.textHint),
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: vpn.peerId));
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(tr.t('settings.peerCopied'))),
                    );
                  },
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.tos'),
                  trailing: Icon(Icons.open_in_new_rounded,
                      size: 15, color: c.textHint),
                  onTap: () => _open('${MvpnConfig.apiBase}/legal/terms'),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: tr.t('settings.privacy'),
                  trailing: Icon(Icons.open_in_new_rounded,
                      size: 15, color: c.textHint),
                  onTap: () => _open('${MvpnConfig.apiBase}/legal/privacy'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          Center(
            child: Text('Mbunie Tech · Mbunie VPN',
                style: TextStyle(fontSize: 11, color: c.textHint)),
          ),
        ],
      ),
    );
  }

  Widget _icon(MvpnColors c, IconData i, {bool danger = false}) => Container(
        width: 34,
        height: 34,
        decoration: BoxDecoration(
          color: (danger ? c.danger : c.brand).withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Icon(i, size: 17, color: danger ? c.danger : c.brand),
      );

  String _expiry(AppState state, AppText tr) {
    final d = state.expiresAt;
    if (d == null) return state.subStatus;
    final days = d.difference(DateTime.now()).inDays;
    return days >= 0 ? tr.p('settings.daysRemain', days) : tr.t('settings.expired');
  }

  Future<void> _open(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  void _pickLanguage(BuildContext context, AppState state) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.smartphone_rounded),
              title: Text('${ctx.tt('settings.language')} — auto'),
              trailing: state.localeOverride == null
                  ? const Icon(Icons.check_rounded)
                  : null,
              onTap: () {
                state.setLocale(null);
                Navigator.pop(ctx);
              },
            ),
            for (final code in AppText.supported)
              ListTile(
                title: Text(AppText.names[code]!),
                trailing: state.localeOverride == code
                    ? const Icon(Icons.check_rounded)
                    : null,
                onTap: () {
                  state.setLocale(code);
                  Navigator.pop(ctx);
                },
              ),
          ],
        ),
      ),
    );
  }

  void _confirmLogout(BuildContext context, AppState state, AppText tr) {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.t('settings.logout')),
        content: Text(tr.t('settings.logoutConfirm')),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(tr.t('common.cancel'))),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              state.logout();
            },
            child: Text(tr.t('settings.logout')),
          ),
        ],
      ),
    );
  }
}
