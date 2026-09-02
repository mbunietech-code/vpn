import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../models.dart';
import '../theme/mvpn_theme.dart';

/// The hero connect control. Idle = calm ring; connecting = sweeping arc;
/// connected = glowing filled orb with a slow breathing pulse.
class ConnectOrb extends StatefulWidget {
  const ConnectOrb({super.key, required this.status, required this.onTap});

  final VpnStatus status;
  final VoidCallback onTap;

  @override
  State<ConnectOrb> createState() => _ConnectOrbState();
}

class _ConnectOrbState extends State<ConnectOrb> with TickerProviderStateMixin {
  late final AnimationController _spin =
      AnimationController(vsync: this, duration: const Duration(seconds: 2))
        ..repeat();
  late final AnimationController _pulse = AnimationController(
      vsync: this, duration: const Duration(milliseconds: 2600))
    ..repeat(reverse: true);

  @override
  void dispose() {
    _spin.dispose();
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final s = widget.status;
    final connected = s == VpnStatus.connected;
    final busy = s == VpnStatus.connecting || s == VpnStatus.reconnecting;
    final error = s == VpnStatus.error;

    final ringColor = connected
        ? c.brand
        : busy
            ? c.brandAccent
            : error
                ? c.danger
                : c.stateIdle;

    return Semantics(
      button: true,
      label: connected ? 'Disconnect' : 'Connect',
      child: GestureDetector(
        onTap: widget.onTap,
        child: AnimatedBuilder(
          animation: Listenable.merge([_spin, _pulse]),
          builder: (context, _) {
            final pulse = connected ? 0.5 + _pulse.value * 0.5 : 0.0;
            return SizedBox(
              width: 232,
              height: 232,
              child: Stack(
                alignment: Alignment.center,
                children: [
                  // outer glow
                  if (connected)
                    Container(
                      width: 200 + pulse * 24,
                      height: 200 + pulse * 24,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(colors: [
                          c.brand.withValues(alpha: 0.22 * pulse),
                          c.brand.withValues(alpha: 0.0),
                        ]),
                      ),
                    ),
                  // track
                  Container(
                    width: 188,
                    height: 188,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: c.surface,
                      border: Border.all(color: c.border, width: 10),
                      boxShadow: [
                        BoxShadow(
                            color: c.scrim,
                            blurRadius: 30,
                            offset: const Offset(0, 16)),
                      ],
                    ),
                  ),
                  // progress arc
                  SizedBox(
                    width: 188,
                    height: 188,
                    child: CustomPaint(
                      painter: _ArcPainter(
                        color: ringColor,
                        sweep: connected
                            ? math.pi * 2
                            : busy
                                ? math.pi * 0.6
                                : 0,
                        rotation: busy ? _spin.value * math.pi * 2 : -math.pi / 2,
                      ),
                    ),
                  ),
                  // core
                  Container(
                    width: 150,
                    height: 150,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: connected
                          ? c.brandGradient
                          : LinearGradient(colors: [c.surfaceAlt, c.surfaceAlt]),
                    ),
                    child: Center(
                      child: busy
                          ? SizedBox(
                              width: 34,
                              height: 34,
                              child: CircularProgressIndicator(
                                  strokeWidth: 3, color: ringColor),
                            )
                          : Icon(
                              Icons.power_settings_new_rounded,
                              size: 58,
                              color: connected
                                  ? c.onBrand
                                  : error
                                      ? c.danger
                                      : c.stateIdle,
                            ),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

class _ArcPainter extends CustomPainter {
  _ArcPainter({required this.color, required this.sweep, required this.rotation});
  final Color color;
  final double sweep;
  final double rotation;

  @override
  void paint(Canvas canvas, Size size) {
    if (sweep <= 0) return;
    final rect = Rect.fromCircle(
        center: size.center(Offset.zero), radius: size.width / 2 - 5);
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 10
      ..strokeCap = StrokeCap.round
      ..shader = SweepGradient(
        colors: [color.withValues(alpha: 0.15), color],
        startAngle: 0,
        endAngle: sweep,
        transform: GradientRotation(rotation),
      ).createShader(rect);
    canvas.drawArc(rect, rotation, sweep, false, paint);
  }

  @override
  bool shouldRepaint(covariant _ArcPainter old) =>
      old.sweep != sweep || old.rotation != rotation || old.color != color;
}
