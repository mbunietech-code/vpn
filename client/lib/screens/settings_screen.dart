import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../models.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context);
    final c = context.mvpn;

    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          const SectionCaption('Connection'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: 'Kill-switch',
                  subtitle: 'Block internet traffic if VPN connection drops',
                  trailing: Switch(
                    value: vpn.killSwitch,
                    onChanged: (v) => vpn.update(() => vpn.killSwitch = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Auto-connect on launch',
                  subtitle: 'Automatically connect to the last used server',
                  trailing: Switch(
                    value: vpn.autoConnect,
                    onChanged: (v) => vpn.update(() => vpn.autoConnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Auto-reconnect',
                  subtitle: 'Reconnect on network change or drop',
                  trailing: Switch(
                    value: vpn.autoReconnect,
                    onChanged: (v) => vpn.update(() => vpn.autoReconnect = v),
                  ),
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Protocol Preference',
                  trailing: DropdownButton<ProtocolPref>(
                    value: vpn.protocol,
                    underline: const SizedBox.shrink(),
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
          const SectionCaption('Account & Peer'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(
                  title: 'Current Peer ID',
                  subtitle: vpn.peerId,
                  trailing: IconButton(
                    icon: Icon(Icons.copy_rounded, size: 18, color: c.textSecondary),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: vpn.peerId));
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Peer ID copied')),
                      );
                    },
                  ),
                ),
                Divider(color: c.border, height: 1),
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Import Configuration',
                                style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w500,
                                    color: c.textPrimary)),
                            const SizedBox(height: 8),
                            Row(
                              children: [
                                OutlinedButton.icon(
                                  onPressed: () {},
                                  icon: const Icon(Icons.qr_code_scanner, size: 16),
                                  label: const Text('Scan QR'),
                                ),
                                const SizedBox(width: 8),
                                OutlinedButton.icon(
                                  onPressed: () {},
                                  icon: const Icon(Icons.link, size: 16),
                                  label: const Text('Paste Link'),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SectionCaption('About'),
          MvpnCard(
            child: Column(
              children: [
                SettingRow(title: 'Version', trailing: Text(vpn.appVersion,
                    style: TextStyle(fontSize: 13, color: c.textSecondary))),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Terms of Service',
                  trailing: Icon(Icons.chevron_right, color: c.textHint),
                  onTap: () {},
                ),
                Divider(color: c.border, height: 1),
                SettingRow(
                  title: 'Privacy Policy',
                  trailing: Icon(Icons.chevron_right, color: c.textHint),
                  onTap: () {},
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
