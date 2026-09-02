import 'package:flutter/material.dart';

import '../models.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';

class ServersScreen extends StatelessWidget {
  const ServersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final vpn = MvpnScope.of(context);
    final c = context.mvpn;

    const regionOrder = ['Americas', 'Europe', 'Asia-Pacific'];
    final grouped = <String, List<VpnNode>>{};
    for (final n in vpn.nodes) {
      grouped.putIfAbsent(n.region, () => []).add(n);
    }
    final regions = grouped.keys.toList()
      ..sort((a, b) => regionOrder.indexOf(a).compareTo(regionOrder.indexOf(b)));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Select Node'),
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
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          _OptimalCard(onTap: () {
            final fastest = vpn.nodes.reduce((a, b) => a.latencyMs <= b.latencyMs ? a : b);
            vpn.selectNode(fastest);
            Navigator.of(context).maybePop();
          }),
          for (final region in regions) ...[
            SectionCaption(region),
            MvpnCard(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Column(
                children: [
                  for (final node in grouped[region]!)
                    _NodeRow(
                      node: node,
                      selected: node.id == vpn.currentNode.id,
                      onTap: () {
                        vpn.selectNode(node);
                        Navigator.of(context).maybePop();
                      },
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _OptimalCard extends StatelessWidget {
  const _OptimalCard({required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: c.surfaceAlt,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: c.border),
        ),
        child: Row(
          children: [
            Icon(Icons.bolt_rounded, color: c.brand),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Optimal Server',
                    style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: c.textPrimary)),
                Text('Auto-connect to fastest node',
                    style: TextStyle(fontSize: 12, color: c.textSecondary)),
              ],
            ),
            const Spacer(),
            Icon(Icons.chevron_right, color: c.textHint),
          ],
        ),
      ),
    );
  }
}

class _NodeRow extends StatelessWidget {
  const _NodeRow({required this.node, required this.selected, required this.onTap});
  final VpnNode node;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return InkWell(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          border: Border(
            left: BorderSide(
              color: selected ? c.brandAccent : Colors.transparent,
              width: 3,
            ),
          ),
        ),
        padding: const EdgeInsets.fromLTRB(9, 14, 0, 14),
        child: Row(
          children: [
            CircleAvatar(
              radius: 15,
              backgroundColor: c.surfaceAlt,
              child: Icon(Icons.dns_outlined, size: 16, color: c.textSecondary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(node.name,
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w500,
                          color: c.textPrimary)),
                  Text(node.code,
                      style: TextStyle(fontSize: 12, color: c.textHint)),
                ],
              ),
            ),
            Text('${node.latencyMs}ms',
                style: TextStyle(fontSize: 13, color: c.textSecondary)),
            const SizedBox(width: 8),
            SignalBars(node.bars),
          ],
        ),
      ),
    );
  }
}
