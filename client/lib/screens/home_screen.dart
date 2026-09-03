import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';
import '../widgets/connect_orb.dart';
import 'onboarding.dart';
import 'servers_screen.dart';
import 'session_stats_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    final vpn = state.vpn;
    final c = context.mvpn;
    final tr = context.tr;

    final (title, subtitle, tone) = switch (vpn.status) {
      VpnStatus.connected => (tr.t('home.connected'), tr.t('home.subConnected'), c.success),
      VpnStatus.connecting => (tr.t('home.connecting'), tr.t('home.subConnecting'), c.brand),
      VpnStatus.reconnecting => (tr.t('home.reconnecting'), tr.t('home.subReconnecting'), c.warning),
      VpnStatus.error => (tr.t('home.failed'), tr.t(vpn.error ?? 'home.subFailed'), c.danger),
      VpnStatus.disconnected => (tr.t('home.disconnected'), tr.t('home.subIdle'), c.stateIdle),
    };

    final daysLeft = state.expiresAt?.difference(DateTime.now()).inDays;

    return Scaffold(
      appBar: AppBar(
        titleSpacing: 20,
        title: Row(
          children: [
            Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                gradient: c.brandGradient,
                borderRadius: BorderRadius.circular(9),
              ),
              child: Icon(Icons.shield_rounded, color: c.onBrand, size: 17),
            ),
            const SizedBox(width: 10),
            const Text('Mbunie VPN'),
          ],
        ),
        actions: [
          if (state.planCode != null)
            Padding(
              padding: const EdgeInsets.only(right: 16),
              child: Center(
                child: MvpnBadge(
                  daysLeft != null && daysLeft >= 0
                      ? tr.p('home.daysLeft', daysLeft)
                      : state.subStatus,
                  color: (daysLeft ?? 1) <= 3 ? c.warning : c.brand,
                  icon: Icons.workspace_premium_outlined,
                ),
              ),
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
        children: [
          if (state.pendingInvoice != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: InlineNotice(
                text: state.pendingInvoice!['proof_uploaded'] == true
                    ? tr.t('home.pendingProof')
                    : tr.t('home.pendingNoProof'),
                tone: NoticeTone.warning,
                action: tr.t('common.check'),
                onAction: () => state.refreshSubscription(),
              ),
            ),
          if (state.subStatus == 'expired' || state.subStatus == 'suspended')
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: InlineNotice(
                text: state.subStatus == 'expired'
                    ? tr.t('home.subExpired')
                    : tr.t('home.subSuspended'),
                tone: NoticeTone.warning,
                action: tr.t('home.renew'),
                onAction: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(builder: (_) => const PlansScreen()),
                ),
              ),
            ),
          if (vpn.status == VpnStatus.error && vpn.error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: InlineNotice(text: tr.t(vpn.error!), tone: NoticeTone.danger),
            ),
          if (!vpn.isRealTunnel)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: InlineNotice(
                text: tr.t('home.demoNote'),
                tone: NoticeTone.info,
              ),
            ),
          const SizedBox(height: 8),
          Center(child: ConnectOrb(status: vpn.status, onTap: vpn.toggle)),
          const SizedBox(height: 22),
          Center(
            child: Column(
              children: [
                Text(title,
                    style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: c.textPrimary)),
                const SizedBox(height: 5),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      vpn.isConnected
                          ? Icons.lock_rounded
                          : Icons.lock_open_rounded,
                      size: 13,
                      color: tone,
                    ),
                    const SizedBox(width: 5),
                    Text(subtitle,
                        style:
                            TextStyle(fontSize: 12.8, color: c.textSecondary)),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 26),
          _NodeCard(node: vpn.currentNode),
          const SizedBox(height: 12),
          _LiveCard(),
        ],
      ),
    );
  }
}

class _NodeCard extends StatelessWidget {
  const _NodeCard({required this.node});
  final VpnNode node;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final tr = context.tr;
    return MvpnCard(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const ServersScreen()),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: c.surfaceAlt,
              borderRadius: BorderRadius.circular(13),
            ),
            child: Icon(Icons.public_rounded, color: c.brand, size: 22),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.t('home.currentServer'),
                    style: TextStyle(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.4,
                        color: c.textHint)),
                const SizedBox(height: 3),
                Text('${node.name} · ${node.code}',
                    style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: c.textPrimary)),
                if (node.optimizedFor != null)
                  Text(tr.p('home.optimizedFor', node.optimizedFor!),
                      style: TextStyle(fontSize: 12, color: c.textSecondary)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(tr.t('common.change'),
                  style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: c.brand)),
              const SizedBox(height: 6),
              Icon(Icons.chevron_right_rounded, color: c.textHint),
            ],
          ),
        ],
      ),
    );
  }
}

class _LiveCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context).vpn;
    final c = context.mvpn;
    final tr = context.tr;
    final s = vpn.stats;
    final on = vpn.isConnected;

    return MvpnCard(
      onTap: on
          ? () => Navigator.of(context).push(MaterialPageRoute<void>(
              builder: (_) => const SessionStatsScreen()))
          : null,
      child: Column(
        children: [
          Row(
            children: [
              Text(tr.t('home.session'),
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: c.textPrimary)),
              const Spacer(),
              if (on)
                Text(formatDuration(s.duration),
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: c.brand,
                        fontFeatures: const [FontFeature.tabularFigures()]))
              else
                Text('—', style: TextStyle(color: c.textHint)),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: tr.t('home.download'),
                  icon: Icons.south_rounded,
                  accent: c.brandAccent,
                  value: on ? formatBytes(s.downloadedBytes) : '—',
                ),
              ),
              Expanded(
                child: StatTile(
                  label: tr.t('home.upload'),
                  icon: Icons.north_rounded,
                  accent: c.success,
                  value: on ? formatBytes(s.uploadedBytes) : '—',
                ),
              ),
              Expanded(
                child: StatTile(
                  label: tr.t('home.protocol'),
                  value: on ? s.protocol : '—',
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
