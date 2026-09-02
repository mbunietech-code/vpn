import 'package:flutter/material.dart';

import '../theme/mvpn_theme.dart';

class SectionCaption extends StatelessWidget {
  const SectionCaption(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 22, 4, 10),
      child: Row(
        children: [
          Text(
            text.toUpperCase(),
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.1,
              color: context.mvpn.textHint,
            ),
          ),
          const Spacer(),
          ?trailing,
        ],
      ),
    );
  }
}

class MvpnCard extends StatelessWidget {
  const MvpnCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.onTap,
    this.gradient,
    this.borderColor,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Gradient? gradient;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final content = Container(
      padding: padding,
      decoration: BoxDecoration(
        color: gradient == null ? c.surface : null,
        gradient: gradient,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: borderColor ?? c.border),
        boxShadow: [
          BoxShadow(
            color: c.scrim,
            blurRadius: 24,
            spreadRadius: -6,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: child,
    );

    if (onTap == null) return content;
    return Material(
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: content,
      ),
    );
  }
}

class SettingRow extends StatelessWidget {
  const SettingRow({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
    this.leading,
    this.onTap,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final Widget? leading;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 13),
        child: Row(
          children: [
            if (leading != null) ...[leading!, const SizedBox(width: 12)],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: c.textPrimary)),
                  if (subtitle != null) ...[
                    const SizedBox(height: 3),
                    Text(subtitle!,
                        style: TextStyle(
                            fontSize: 12.5, height: 1.35, color: c.textSecondary)),
                  ],
                ],
              ),
            ),
            if (trailing != null) ...[const SizedBox(width: 12), trailing!],
          ],
        ),
      ),
    );
  }
}

class SignalBars extends StatelessWidget {
  const SignalBars(this.bars, {super.key, this.unknown = false});
  final int bars;
  final bool unknown;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    if (unknown) {
      return Icon(Icons.wifi_find_outlined, size: 15, color: c.textHint);
    }
    final color = bars >= 3
        ? c.success
        : bars == 2
            ? c.warning
            : c.textHint;
    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: List.generate(4, (i) {
        return Container(
          margin: const EdgeInsets.only(left: 2.5),
          width: 3.5,
          height: 5.0 + i * 3.5,
          decoration: BoxDecoration(
            color: i < bars ? color : c.border,
            borderRadius: BorderRadius.circular(2),
          ),
        );
      }),
    );
  }
}

class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.accent,
  });
  final String label;
  final String value;
  final IconData? icon;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            if (icon != null) ...[
              Icon(icon, size: 13, color: accent ?? c.textHint),
              const SizedBox(width: 4),
            ],
            Text(label.toUpperCase(),
                style: TextStyle(
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.6,
                    color: c.textHint)),
          ],
        ),
        const SizedBox(height: 5),
        Text(value,
            style: TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.w800,
                color: c.textPrimary,
                fontFeatures: const [FontFeature.tabularFigures()])),
      ],
    );
  }
}

/// Coloured pill (status chip).
class MvpnBadge extends StatelessWidget {
  const MvpnBadge(this.text, {super.key, required this.color, this.icon});
  final String text;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: color),
            const SizedBox(width: 4),
          ],
          Text(text,
              style: TextStyle(
                  fontSize: 11.5, fontWeight: FontWeight.w700, color: color)),
        ],
      ),
    );
  }
}

class InlineNotice extends StatelessWidget {
  const InlineNotice({
    super.key,
    required this.text,
    required this.tone,
    this.action,
    this.onAction,
  });
  final String text;
  final NoticeTone tone;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final (color, icon) = switch (tone) {
      NoticeTone.info => (c.brand, Icons.info_outline_rounded),
      NoticeTone.warning => (c.warning, Icons.warning_amber_rounded),
      NoticeTone.danger => (c.danger, Icons.error_outline_rounded),
      NoticeTone.success => (c.success, Icons.check_circle_outline_rounded),
    };
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.25)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text,
                style: TextStyle(
                    fontSize: 12.8, height: 1.35, color: c.textPrimary)),
          ),
          if (action != null)
            TextButton(
              onPressed: onAction,
              style: TextButton.styleFrom(
                  foregroundColor: color,
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  minimumSize: const Size(0, 32)),
              child: Text(action!),
            ),
        ],
      ),
    );
  }
}

enum NoticeTone { info, warning, danger, success }

class BtnSpinner extends StatelessWidget {
  const BtnSpinner({super.key, this.color});
  final Color? color;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 20,
        width: 20,
        child: CircularProgressIndicator(
            strokeWidth: 2.4, color: color ?? Colors.white),
      );
}

String formatBytes(int bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var v = bytes.toDouble();
  var i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return '${v.toStringAsFixed(v >= 100 || i == 0 ? 0 : 1)} ${units[i]}';
}

String formatSpeed(int bytesPerSec) {
  final mbps = bytesPerSec * 8 / 1e6;
  if (mbps >= 1) return '${mbps.toStringAsFixed(1)} Mbps';
  final kbps = bytesPerSec * 8 / 1e3;
  return '${kbps.toStringAsFixed(0)} Kbps';
}

String formatDuration(Duration d) {
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(d.inHours)}:${two(d.inMinutes % 60)}:${two(d.inSeconds % 60)}';
}
