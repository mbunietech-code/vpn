import 'package:flutter/material.dart';

import '../theme/mvpn_theme.dart';

/// Minimal area/line chart — DOWN as filled area, UP as line. No gridlines.
class ThroughputChart extends StatelessWidget {
  const ThroughputChart({super.key, required this.down, required this.up});

  final List<double> down;
  final List<double> up;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return SizedBox(
      height: 140,
      child: CustomPaint(
        painter: _ChartPainter(
          down: down,
          up: up,
          areaColor: c.brandAccent,
          lineColor: c.success,
          baseline: c.border,
        ),
        size: Size.infinite,
      ),
    );
  }
}

class _ChartPainter extends CustomPainter {
  _ChartPainter({
    required this.down,
    required this.up,
    required this.areaColor,
    required this.lineColor,
    required this.baseline,
  });

  final List<double> down;
  final List<double> up;
  final Color areaColor;
  final Color lineColor;
  final Color baseline;

  @override
  void paint(Canvas canvas, Size size) {
    final basePaint = Paint()
      ..color = baseline
      ..strokeWidth = 1;
    canvas.drawLine(
        Offset(0, size.height - 1), Offset(size.width, size.height - 1), basePaint);

    if (down.length < 2) return;

    final maxVal = [
      ...down,
      ...up,
      1.0,
    ].reduce((a, b) => a > b ? a : b);

    Path pathFor(List<double> data) {
      final path = Path();
      for (var i = 0; i < data.length; i++) {
        final x = size.width * i / (data.length - 1);
        final y = size.height - (data[i] / maxVal) * (size.height - 8);
        i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
      }
      return path;
    }

    final downPath = pathFor(down);
    final fill = Path.from(downPath)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(fill, Paint()..color = areaColor.withValues(alpha: 0.12));
    canvas.drawPath(
      downPath,
      Paint()
        ..color = areaColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round,
    );
    canvas.drawPath(
      pathFor(up),
      Paint()
        ..color = lineColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(covariant _ChartPainter old) =>
      old.down != down || old.up != up;
}
