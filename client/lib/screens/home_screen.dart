import 'package:flutter/material.dart';

import '../models.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';
import 'servers_screen.dart';
import 'session_stats_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context).vpn;
    final c = context.mvpn;

    final (label, color) = switch (vpn.status) {
      VpnStatus.connected => ('Connected', c.success),
      VpnStatus.connecting => ('Connecting…', c.brand),
      VpnStatus.reconnecting => ('Reconnecting…', c.warning),
      VpnStatus.error => ('Error', c.danger),
      VpnStatus.disconnected => ('Disconnected', c.stateIdle),
    };

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Icon(Icons.shield_rounded, color: c.brand, size: 22),
            const SizedBox(width: 8),
            const Text('Mbunie VPN'),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          const SizedBox(height: 12),
          Center(
            child: Column(
              children: [
                Text(label,
                    style: TextStyle(
                        fontSize: 26, fontWeight: FontWeight.w700, color: color)),
                const SizedBox(height: 6),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.verified_user_outlined,
                        size: 14, color: c.textSecondary),
                    const SizedBox(width: 4),
                    Text(vpn.ipObfuscated ? 'IP Obfuscated' : 'IP Exposed',
                        style: TextStyle(fontSize: 13, color: c.textSecondary)),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 28),
          _ConnectButton(status: vpn.status, onTap: vpn.toggle),
          const SizedBox(height: 20),
          if (vpn.status == VpnStatus.error && vpn.error != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: c.danger.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: c.danger.withValues(alpha: 0.3)),
              ),
              child: Text(vpn.error!,
                  style: TextStyle(fontSize: 13, color: c.danger)),
            ),
          if (!vpn.isRealTunnel)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: c.warning.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.info_outline, size: 14, color: c.warning),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Demo mode — kwenye platform hii tunnel halisi bado. Tumia toleo la desktop.',
                      style: TextStyle(fontSize: 12, color: c.textSecondary),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 12),
          _CurrentNodeCard(node: vpn.currentNode),
          const SizedBox(height: 16),
          _MiniStats(),
        ],
      ),
    );
  }
}

class _ConnectButton extends StatelessWidget {
  const _ConnectButton({required this.status, required this.onTap});
  final VpnStatus status;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final connected = status == VpnStatus.connected;
    final busy = status == VpnStatus.connecting || status == VpnStatus.reconnecting;
    final ring = connected
        ? c.brand
        : busy
            ? c.brandAccent
            : c.stateIdle;

    return Center(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 300),
          width: 168,
          height: 168,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: c.surfaceAlt,
            border: Border.all(color: ring, width: 3),
            boxShadow: connected
                ? [BoxShadow(color: c.brand.withValues(alpha: 0.25), blurRadius: 28)]
                : null,
          ),
          child: Center(
            child: busy
                ? SizedBox(
                    width: 40,
                    height: 40,
                    child: CircularProgressIndicator(strokeWidth: 3, color: ring),
                  )
                : Icon(Icons.power_settings_new_rounded,
                    size: 56, color: connected ? c.brand : c.stateIdle),
          ),
        ),
      ),
    );
  }
}

class _CurrentNodeCard extends StatelessWidget {
  const _CurrentNodeCard({required this.node});
  final VpnNode node;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return MvpnCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text('Current Node',
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: c.textSecondary)),
              const Spacer(),
              TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(builder: (_) => const ServersScreen()),
                ),
                child: const Text('Change'),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              CircleAvatar(
                radius: 16,
                backgroundColor: c.surfaceAlt,
                child: Icon(Icons.public, size: 18, color: c.brand),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${node.name}, ${node.code}',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: c.textPrimary)),
                  if (node.optimizedFor != null)
                    Text('Optimized for ${node.optimizedFor}',
                        style: TextStyle(fontSize: 12, color: c.textSecondary)),
                ],
              ),
              const Spacer(),
              Text(node.quality,
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: c.success)),
            ],
          ),
        ],
      ),
    );
  }
}

class _MiniStats extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context).vpn;
    final c = context.mvpn;
    final s = vpn.stats;
    final latency = vpn.currentNode.latencyMs;

    return MvpnCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text('Session Stats',
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: c.textSecondary)),
              const Spacer(),
              if (vpn.isConnected)
                TextButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                        builder: (_) => const SessionStatsScreen()),
                  ),
                  child: const Text('Details'),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                  child: StatTile(
                      label: 'Latency (RTT)',
                      value: vpn.isConnected ? '$latency ms' : '—')),
              Expanded(
                  child: StatTile(
                      label: 'Data Used',
                      value: vpn.isConnected
                          ? formatBytes(s.downloadedBytes + s.uploadedBytes)
                          : '—')),
            ],
          ),
        ],
      ),
    );
  }
}
