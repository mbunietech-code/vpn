import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../models.dart';
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

    return Scaffold(
      appBar: AppBar(title: const Text('Mipangilio')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 28),
        children: [
          const SectionCaption('Akaunti'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  leading: _icon(c, Icons.person_rounded),
                  title: state.identifier ?? 'Mtumiaji',
                  subtitle: state.planCode != null
                      ? 'Kifurushi: ${state.planCode} · ${_expiry(state)}'
                      : 'Hakuna kifurushi hai',
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  leading: _icon(c, Icons.workspace_premium_rounded),
                  title: 'Kifurushi & malipo',
                  trailing: Icon(Icons.chevron_right_rounded, color: c.textHint),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                      builder: (_) => const PlansScreen())),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  leading: _icon(c, Icons.logout_rounded, danger: true),
                  title: 'Toka',
                  onTap: () => _confirmLogout(context, state),
                ),
              ],
            ),
          ),
          const SectionCaption('Muunganisho'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: 'Kill-switch',
                  subtitle: 'Zuia intaneti yote endapo VPN itakatika',
                  trailing: Switch(
                    value: vpn.killSwitch,
                    onChanged: (v) => vpn.update(() => vpn.killSwitch = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Unganisha app ikianzishwa',
                  subtitle: 'Jiunganishe kiotomatiki kwenye seva ya mwisho',
                  trailing: Switch(
                    value: vpn.autoConnect,
                    onChanged: (v) => vpn.update(() => vpn.autoConnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Unganisha upya kiotomatiki',
                  subtitle: 'Baada ya kubadilika kwa mtandao au kukatika',
                  trailing: Switch(
                    value: vpn.autoReconnect,
                    onChanged: (v) => vpn.update(() => vpn.autoReconnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Protocol',
                  subtitle: switch (vpn.protocol) {
                    ProtocolPref.auto => 'Auto — huchagua ya haraka',
                    ProtocolPref.vlessReality => 'Ficha kama HTTPS (TCP)',
                    ProtocolPref.hysteria2 => 'Kasi ya juu (QUIC/UDP)',
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
          const SectionCaption('Kuhusu'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: 'Toleo',
                  trailing: Text(MvpnConfig.appVersion,
                      style: TextStyle(fontSize: 13, color: c.textSecondary)),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Nakili Peer ID',
                  subtitle: vpn.peerId,
                  trailing: Icon(Icons.copy_rounded, size: 17, color: c.textHint),
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: vpn.peerId));
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Peer ID imenakiliwa')),
                    );
                  },
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Masharti ya Huduma',
                  trailing: Icon(Icons.open_in_new_rounded,
                      size: 15, color: c.textHint),
                  onTap: () => _open('${MvpnConfig.apiBase}/legal/terms'),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Sera ya Faragha',
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

  String _expiry(dynamic state) {
    final d = state.expiresAt as DateTime?;
    if (d == null) return state.subStatus as String;
    final days = d.difference(DateTime.now()).inDays;
    return days >= 0 ? 'siku $days zimebaki' : 'imeisha';
  }

  Future<void> _open(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  void _confirmLogout(BuildContext context, dynamic state) {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Toka?'),
        content: const Text('Utahitaji kuingia tena kwa msimbo.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx), child: const Text('Ghairi')),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              state.logout();
            },
            child: const Text('Toka'),
          ),
        ],
      ),
    );
  }
}
