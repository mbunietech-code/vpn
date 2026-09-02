import 'package:flutter/material.dart';

import '../models.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';

class ServersScreen extends StatelessWidget {
  const ServersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context).vpn;
    final c = context.mvpn;

    const order = ['Asia-Pacific', 'Europe', 'Americas', 'Servers'];
    final grouped = <String, List<VpnNode>>{};
    for (final n in vpn.nodes) {
      grouped.putIfAbsent(n.region, () => []).add(n);
    }
    final regions = grouped.keys.toList()
      ..sort((a, b) => order.indexOf(a).compareTo(order.indexOf(b)));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Chagua seva'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Row(
              children: [
                Text('Ping', style: TextStyle(fontSize: 13, color: c.textSecondary)),
                Switch(
                  value: vpn.pingOnOpen,
                  onChanged: (v) => vpn.update(() => vpn.pingOnOpen = v),
                ),
              ],
            ),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
        children: [
          MvpnCard(
            gradient: c.brandGradient,
            borderColor: Colors.transparent,
            onTap: () {
              final fastest = vpn.nodes.reduce((a, b) =>
                  (a.latencyKnown ? a.latencyMs : 9999) <=
                          (b.latencyKnown ? b.latencyMs : 9999)
                      ? a
                      : b);
              vpn.selectNode(fastest);
              Navigator.of(context).maybePop();
            },
            child: Row(
              children: [
                Icon(Icons.bolt_rounded, color: c.onBrand, size: 26),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Seva bora kiotomatiki',
                          style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.w800,
                              color: c.onBrand)),
                      Text('Unganisha kwenye seva ya haraka zaidi',
                          style: TextStyle(
                              fontSize: 12,
                              color: c.onBrand.withValues(alpha: 0.85))),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right_rounded,
                    color: c.onBrand.withValues(alpha: 0.9)),
              ],
            ),
          ),
          for (final region in regions) ...[
            SectionCaption(region),
            MvpnCard(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
              child: Column(
                children: [
                  for (var i = 0; i < grouped[region]!.length; i++) ...[
                    if (i > 0) Divider(color: c.border, height: 1, indent: 12, endIndent: 12),
                    _NodeRow(
                      node: grouped[region]![i],
                      selected: grouped[region]![i].id == vpn.currentNode.id,
                      onTap: () {
                        vpn.selectNode(grouped[region]![i]);
                        Navigator.of(context).maybePop();
                      },
                    ),
                  ],
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _NodeRow extends StatelessWidget {
  const _NodeRow(
      {required this.node, required this.selected, required this.onTap});
  final VpnNode node;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 13),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: selected ? c.brand.withValues(alpha: 0.12) : c.surfaceAlt,
                borderRadius: BorderRadius.circular(11),
              ),
              child: Icon(Icons.dns_rounded,
                  size: 17, color: selected ? c.brand : c.textSecondary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text(node.name,
                          style: TextStyle(
                              fontSize: 14.5,
                              fontWeight: FontWeight.w700,
                              color: c.textPrimary)),
                      if (node.optimizedFor != null) ...[
                        const SizedBox(width: 6),
                        MvpnBadge(node.optimizedFor!, color: c.brand),
                      ],
                    ],
                  ),
                  Text(node.code,
                      style: TextStyle(fontSize: 11.5, color: c.textHint)),
                ],
              ),
            ),
            if (node.latencyKnown)
              Text('${node.latencyMs} ms',
                  style: TextStyle(fontSize: 12.5, color: c.textSecondary)),
            const SizedBox(width: 8),
            SignalBars(node.bars, unknown: !node.latencyKnown),
            const SizedBox(width: 6),
            if (selected)
              Icon(Icons.check_circle_rounded, color: c.brand, size: 18)
            else
              const SizedBox(width: 18),
          ],
        ),
      ),
    );
  }
}
