/// Progress rings, dashed lines and boxes, the success screen and facts.
library;

import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'app_identity.dart';

/// A ring that fills clockwise from the top as [progress] goes from 0 to 1,
/// with the percentage in its middle. Drawn for the dark hero panels.
class AppRing extends StatelessWidget {
  const AppRing(this.progress, {this.size = 66, super.key});
  final double progress;
  final double size;
  @override
  Widget build(BuildContext context) => SizedBox(
        width: size,
        height: size,
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: progress.clamp(0, 1).toDouble()),
          duration: const Duration(milliseconds: 600),
          curve: Curves.easeOutCubic,
          builder: (context, value, _) => CustomPaint(
            painter: _RingPainter(value),
            child: Center(
                child: Text('${(progress * 100).round()}%',
                    style: AppIdentity.number(14,
                        weight: FontWeight.w800, color: Colors.white))),
          ),
        ),
      );
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.value);
  final double value;
  @override
  void paint(Canvas canvas, Size size) {
    final radius = size.width * 27 / 66;
    final rect =
        Rect.fromCircle(center: size.center(Offset.zero), radius: radius);
    final stroke = size.width * 6 / 66;
    canvas.drawCircle(
        rect.center,
        radius,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke
          ..color = const Color(0x1FFFFFFF));
    if (value <= 0) return;
    canvas.drawArc(
        rect,
        -math.pi / 2,
        2 * math.pi * value,
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeCap = StrokeCap.round
          ..strokeWidth = stroke
          ..color = AppIdentity.heroGood);
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.value != value;
}

/// When the roster was last downloaded, in words.
String updatedText(String? updatedAt) {
  final updated = DateTime.tryParse(updatedAt ?? '');
  if (updated == null) return '—';
  final minutes = DateTime.now().difference(updated).inMinutes;
  if (minutes < 1) return 'الآن';
  if (minutes < 60) return 'قبل $minutes د';
  if (minutes < 24 * 60) return 'قبل ${minutes ~/ 60} س';
  return AppIdentity.shortDate(updatedAt);
}

/// A dashed hairline, as on a paper receipt.
class AppDashedLine extends StatelessWidget {
  const AppDashedLine({super.key});
  @override
  Widget build(BuildContext context) => SizedBox(
      height: 1,
      width: double.infinity,
      child: CustomPaint(painter: _DashPainter(AppIdentity.line)));
}

class _DashPainter extends CustomPainter {
  _DashPainter(this.color);
  final Color color;
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1;
    for (var x = 0.0; x < size.width; x += 6) {
      canvas.drawLine(
          Offset(x, .5), Offset(math.min(x + 3, size.width), .5), paint);
    }
  }

  @override
  bool shouldRepaint(_DashPainter old) => old.color != color;
}

/// The page shown after something is saved: a green tick, a title, a line
/// of explanation, a receipt of facts and the buttons that go on from here.
class AppSuccess extends StatelessWidget {
  const AppSuccess(
      {required this.title,
      required this.message,
      required this.facts,
      required this.actions,
      super.key});
  final String title;
  final String message;

  /// Label and value pairs; a value may be any widget.
  final List<(String, Widget)> facts;
  final List<Widget> actions;
  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.fromLTRB(20, 36, 20, 20),
        children: [
          Center(
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: .5, end: 1),
              duration: const Duration(milliseconds: 500),
              curve: const Cubic(.22, 1.2, .36, 1),
              builder: (context, scale, child) =>
                  Transform.scale(scale: scale, child: child),
              child: Container(
                  width: 88,
                  height: 88,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                      color: AppIdentity.goodTint,
                      borderRadius: BorderRadius.circular(30)),
                  child: AppIcon('check',
                      size: 42, strokeWidth: 2.4, color: AppIdentity.good)),
            ),
          ),
          const SizedBox(height: 14),
          Text(title,
              textAlign: TextAlign.center, style: AppIdentity.heading(25)),
          Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 18),
              child: Text(message,
                  textAlign: TextAlign.center,
                  style: AppIdentity.body(14, color: AppIdentity.muted))),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            decoration: AppIdentity.card(),
            child: Column(children: [
              for (var i = 0; i < facts.length; i++) ...[
                if (i > 0) const AppDashedLine(),
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 11),
                  child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(facts[i].$1,
                            style:
                                AppIdentity.body(14, color: AppIdentity.faint)),
                        const SizedBox(width: 12),
                        Expanded(
                            child: Align(
                                alignment: AlignmentDirectional.centerEnd,
                                child: facts[i].$2)),
                      ]),
                ),
              ],
            ]),
          ),
          const SizedBox(height: 20),
          for (var i = 0; i < actions.length; i++) ...[
            if (i > 0) const SizedBox(height: 10),
            actions[i],
          ],
        ],
      );
}

/// A bold fact in a receipt.
class AppFact extends StatelessWidget {
  const AppFact(this.text, {this.number = false, this.color, super.key});
  final String text;
  final bool number;
  final Color? color;
  @override
  Widget build(BuildContext context) => Text(text,
      textAlign: TextAlign.end,
      style: number
          ? AppIdentity.number(14, color: color)
          : AppIdentity.body(14, weight: FontWeight.w700, color: color));
}

/// A box with a dashed outline, for «add a photo» style buttons.
class AppDashedBox extends StatelessWidget {
  const AppDashedBox(
      {required this.child, this.radius = 14, this.color, super.key});
  final Widget child;
  final double radius;
  final Color? color;
  @override
  Widget build(BuildContext context) => CustomPaint(
      painter: _DashedBoxPainter(color ?? AppIdentity.line, radius),
      child: child);
}

class _DashedBoxPainter extends CustomPainter {
  _DashedBoxPainter(this.color, this.radius);
  final Color color;
  final double radius;
  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..addRRect(RRect.fromRectAndRadius(
          Rect.fromLTWH(.75, .75, size.width - 1.5, size.height - 1.5),
          Radius.circular(radius)));
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    for (final metric in path.computeMetrics()) {
      for (var d = 0.0; d < metric.length; d += 7) {
        canvas.drawPath(
            metric.extractPath(d, math.min(d + 4, metric.length)), paint);
      }
    }
  }

  @override
  bool shouldRepaint(_DashedBoxPainter old) =>
      old.color != color || old.radius != radius;
}
