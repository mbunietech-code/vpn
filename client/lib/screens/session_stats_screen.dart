import 'package:flutter/material.dart';

import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';
import '../widgets/throughput_chart.dart';

class SessionStatsScreen extends StatelessWidget {
  const SessionStatsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context).vpn;
    final c = context.mvpn;
    final s = vpn.stats;

    final downBps = s.downSeries.isNotEmpty
        ? (s.downSeries.last * 1e6 / 8).round()
        : 0;
    final upBps =
        s.upSeries.isNotEmpty ? (s.upSeries.last * 1e6 / 8).round() : 0;

    return Scaffold(
      appBar: AppBar(title: const Text('Takwimu za kikao')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
        children: [
          MvpnCard(
            gradient: c.brandGradient,
            borderColor: Colors.transparent,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.lock_rounded, size: 14, color: c.onBrand),
                    const SizedBox(width: 6),
                    Text('Muunganisho salama',
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w600,
                            color: c.onBrand.withValues(alpha: 0.9))),
                  ],
                ),
                const SizedBox(height: 14),
                Text('MUDA',
                    style: TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1,
                        color: c.onBrand.withValues(alpha: 0.7))),
                const SizedBox(height: 2),
                Text(formatDuration(s.duration),
                    style: TextStyle(
                        fontSize: 42,
                        fontWeight: FontWeight.w800,
                        height: 1.1,
                        color: c.onBrand,
                        fontFeatures: const [FontFeature.tabularFigures()])),
                const SizedBox(height: 6),
                Text('${vpn.currentNode.name} · ${vpn.currentNode.code}',
                    style: TextStyle(
                        fontSize: 13,
                        color: c.onBrand.withValues(alpha: 0.85))),
              ],
            ),
          ),
          const SizedBox(height: 14),
          MvpnCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text('Mwendokasi',
                        style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: c.textPrimary)),
                    const Spacer(),
                    _legend(c.brandAccent, formatSpeed(downBps), c),
                    const SizedBox(width: 14),
                    _legend(c.success, formatSpeed(upBps), c),
                  ],
                ),
                const SizedBox(height: 14),
                ThroughputChart(down: s.downSeries, up: s.upSeries),
              ],
            ),
          ),
          const SizedBox(height: 14),
          MvpnCard(
            child: Row(
              children: [
                Expanded(
                    child: StatTile(
                        label: 'Imepakuliwa',
                        icon: Icons.south_rounded,
                        accent: c.brandAccent,
                        value: formatBytes(s.downloadedBytes))),
                Expanded(
                    child: StatTile(
                        label: 'Imepakiwa',
                        icon: Icons.north_rounded,
                        accent: c.success,
                        value: formatBytes(s.uploadedBytes))),
              ],
            ),
          ),
          const SizedBox(height: 12),
          MvpnCard(
            child: Row(
              children: [
                Expanded(
                    child: StatTile(
                        label: 'Kilele',
                        value: '${s.peakMbps} Mbps')),
                Expanded(
                    child: StatTile(label: 'Protocol', value: s.protocol)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _legend(Color color, String label, MvpnColors c) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
              width: 7,
              height: 7,
              decoration:
                  BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 5),
          Text(label,
              style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  color: c.textSecondary)),
        ],
      );
}
