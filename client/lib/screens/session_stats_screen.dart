import 'package:flutter/material.dart';

import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';
import '../widgets/throughput_chart.dart';

class SessionStatsScreen extends StatelessWidget {
  const SessionStatsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context);
    final c = context.mvpn;
    final s = vpn.stats;

    return Scaffold(
      appBar: AppBar(title: const Text('Session Stats')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Text('${vpn.currentNode.name}, ${vpn.currentNode.code}',
              style: TextStyle(
                  fontSize: 18, fontWeight: FontWeight.w700, color: c.textPrimary)),
          const SizedBox(height: 4),
          Row(
            children: [
              Icon(Icons.lock_outline, size: 14, color: c.success),
              const SizedBox(width: 4),
              Text('Secure Connection Active',
                  style: TextStyle(fontSize: 13, color: c.textSecondary)),
            ],
          ),
          const SizedBox(height: 24),
          Text('DURATION',
              style: TextStyle(
                  fontSize: 11, fontWeight: FontWeight.w600, color: c.textHint)),
          Text(formatDuration(s.duration),
              style: TextStyle(
                  fontSize: 40,
                  fontWeight: FontWeight.w700,
                  color: c.brand,
                  fontFeatures: const [FontFeature.tabularFigures()])),
          const SizedBox(height: 20),
          MvpnCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text('Throughput',
                        style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            color: c.textSecondary)),
                    const Spacer(),
                    _legendDot(c.brandAccent, 'DOWN', c),
                    const SizedBox(width: 12),
                    _legendDot(c.success, 'UP', c),
                  ],
                ),
                const SizedBox(height: 12),
                ThroughputChart(down: s.downSeries, up: s.upSeries),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                  child: StatTile(
                      label: 'Downloaded',
                      value: formatBytes(s.downloadedBytes))),
              Expanded(
                  child: StatTile(
                      label: 'Uploaded', value: formatBytes(s.uploadedBytes))),
            ],
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                  child: StatTile(
                      label: 'Peak', value: '${s.peakMbps} Mbps')),
              Expanded(
                  child: StatTile(label: 'Protocol', value: s.protocol)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _legendDot(Color color, String label, MvpnColors c) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 4),
        Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: c.textHint)),
      ],
    );
  }
}
