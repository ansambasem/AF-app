import 'dart:math' as math;

import 'package:flutter/material.dart';

/// The app's line icons, drawn from the same 24-unit stroke paths the
/// design uses so they look identical on every phone.
class AppIcon extends StatelessWidget {
  const AppIcon(this.name,
      {this.size = 20, this.color, this.strokeWidth = 1.9, super.key});
  final String name;
  final double size;
  final Color? color;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    final paths = _paths(name);
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _IconPainter(
            paths,
            color ?? IconTheme.of(context).color ?? const Color(0xFF000000),
            strokeWidth),
      ),
    );
  }
}

class _IconPainter extends CustomPainter {
  _IconPainter(this.paths, this.color, this.strokeWidth);
  final List<Path> paths;
  final Color color;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.width / 24;
    canvas.scale(scale);
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    for (final path in paths) {
      canvas.drawPath(path, paint);
    }
  }

  @override
  bool shouldRepaint(_IconPainter old) =>
      old.color != color ||
      old.paths != paths ||
      old.strokeWidth != strokeWidth;
}

final _cache = <String, List<Path>>{};

List<Path> _paths(String name) => _cache.putIfAbsent(name, () {
      final data = _iconData[name];
      assert(data != null, 'Unknown icon $name');
      return [for (final d in data ?? const <String>[]) parseSvgPath(d)];
    });

/// Parses SVG path data (M L H V C S A Z, absolute and relative).
Path parseSvgPath(String d) {
  final path = Path();
  final tokens =
      RegExp(r'([MmLlHhVvCcSsAaZz])|(-?(?:\d+\.?\d*|\.\d+)(?:e-?\d+)?)')
          .allMatches(d)
          .map((m) => m.group(0)!)
          .toList();
  var i = 0;
  var command = 'M';
  var x = 0.0, y = 0.0, startX = 0.0, startY = 0.0;
  var lastCx = 0.0, lastCy = 0.0;
  var lastWasCubic = false;
  double next() => double.parse(tokens[i++]);
  bool isCommand(String t) => RegExp(r'^[A-Za-z]$').hasMatch(t);
  while (i < tokens.length) {
    if (isCommand(tokens[i])) command = tokens[i++];
    final relative = command == command.toLowerCase();
    switch (command.toUpperCase()) {
      case 'M':
        final nx = next() + (relative ? x : 0),
            ny = next() + (relative ? y : 0);
        path.moveTo(nx, ny);
        x = startX = nx;
        y = startY = ny;
        command = relative ? 'l' : 'L';
        lastWasCubic = false;
      case 'L':
        x = next() + (relative ? x : 0);
        y = next() + (relative ? y : 0);
        path.lineTo(x, y);
        lastWasCubic = false;
      case 'H':
        x = next() + (relative ? x : 0);
        path.lineTo(x, y);
        lastWasCubic = false;
      case 'V':
        y = next() + (relative ? y : 0);
        path.lineTo(x, y);
        lastWasCubic = false;
      case 'C':
        final c1x = next() + (relative ? x : 0),
            c1y = next() + (relative ? y : 0);
        final c2x = next() + (relative ? x : 0),
            c2y = next() + (relative ? y : 0);
        final ex = next() + (relative ? x : 0),
            ey = next() + (relative ? y : 0);
        path.cubicTo(c1x, c1y, c2x, c2y, ex, ey);
        lastCx = c2x;
        lastCy = c2y;
        x = ex;
        y = ey;
        lastWasCubic = true;
      case 'S':
        final c1x = lastWasCubic ? 2 * x - lastCx : x;
        final c1y = lastWasCubic ? 2 * y - lastCy : y;
        final c2x = next() + (relative ? x : 0),
            c2y = next() + (relative ? y : 0);
        final ex = next() + (relative ? x : 0),
            ey = next() + (relative ? y : 0);
        path.cubicTo(c1x, c1y, c2x, c2y, ex, ey);
        lastCx = c2x;
        lastCy = c2y;
        x = ex;
        y = ey;
        lastWasCubic = true;
      case 'A':
        final rx = next(), ry = next(), rotation = next();
        final large = next() != 0, sweep = next() != 0;
        final ex = next() + (relative ? x : 0),
            ey = next() + (relative ? y : 0);
        path.arcToPoint(Offset(ex, ey),
            radius: Radius.elliptical(rx, ry),
            rotation: rotation * math.pi / 180,
            largeArc: large,
            clockwise: sweep);
        x = ex;
        y = ey;
        lastWasCubic = false;
      case 'Z':
        path.close();
        x = startX;
        y = startY;
        lastWasCubic = false;
    }
  }
  return path;
}

const _iconData = <String, List<String>>{
  'home': ['M4 11l8-7 8 7v8a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
  'bolt': ['M13 2L4 14h7l-1 8 9-12h-7z'],
  'cash': [
    'M5 6h14a2.5 2.5 0 0 1 2.5 2.5v7a2.5 2.5 0 0 1 -2.5 2.5h-14a2.5 2.5 0 0 1 -2.5 -2.5v-7a2.5 2.5 0 0 1 2.5 -2.5z',
    'M9.4 12a2.6 2.6 0 1 0 5.2 0a2.6 2.6 0 1 0 -5.2 0z',
    'M6 9.5v5M18 9.5v5'
  ],
  'cloud': [
    'M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.75 4.75 0 0 1-.5 9.5z'
  ],
  'cloudoff': [
    'M3 3l18 18M8.5 6.2A6 6 0 0 1 18 8.5a4.75 4.75 0 0 1 2.6 8.3M17 18H7a4.5 4.5 0 0 1-1.6-8.7'
  ],
  'sync': [
    'M20 12a8 8 0 0 1-14 5.3M4 12a8 8 0 0 1 14-5.3',
    'M18 3v4h-4M6 21v-4h4'
  ],
  'user': ['M8 8a4 4 0 1 0 8 0a4 4 0 1 0 -8 0z', 'M4 21a8 8 0 0 1 16 0'],
  'phone': [
    'M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-2-2 2a15 15 0 0 1-7-7l2-2z'
  ],
  'calendar': [
    'M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z',
    'M7 3v4M17 3v4M3 10h18M7 14h2M15 14h2M7 17h2'
  ],
  'meter': [
    'M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
    'M7 7h10v6H7zM10 17h4M12 10l2-1'
  ],
  'breaker': ['M7 3h10v18H7zM10 7h4v6h-4zM12 6v3M10 17h4'],
  'tag': ['M3 4h8l10 10-7 7L3 10z', 'M7 7h.01'],
  'lock': [
    'M7 10.5h10a2.5 2.5 0 0 1 2.5 2.5v5a2.5 2.5 0 0 1 -2.5 2.5h-10a2.5 2.5 0 0 1 -2.5 -2.5v-5a2.5 2.5 0 0 1 2.5 -2.5z',
    'M8 10.5V7.5a4 4 0 0 1 8 0v3'
  ],
  'eye': [
    'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z',
    'M9 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0z'
  ],
  'eyeoff': [
    'M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2'
  ],
  'login': ['M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M9 16l-4-4 4-4M5 12h11'],
  'logout': ['M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l4 4-4 4M19 12H8'],
  'search': ['M4 11a7 7 0 1 0 14 0a7 7 0 1 0 -14 0z', 'M20 20l-3.5-3.5'],
  'x': ['M6 6l12 12M18 6L6 18'],
  'back': ['M9 6l6 6-6 6'],
  'chev': ['M15 6l-6 6 6 6'],
  'down': ['M6 9l6 6 6-6'],
  'check': ['M5 12.5l4.5 4.5L19 7.5'],
  'clock': ['M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0z', 'M12 7v5l3 2'],
  'err': ['M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0z', 'M12 7.5v5.5M12 16.5v.5'],
  'info': ['M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0z', 'M12 11v6M12 7.5v.5'],
  'edit': ['M4 20h4L19 9l-4-4L4 16z', 'M13.5 6.5l4 4'],
  'trash': ['M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13'],
  'up': ['M3 17l6-6 4 4 8-8M15 7h6v6'],
  'hist': ['M3 12a9 9 0 1 0 3-6.7L3 8', 'M3 3v5h5M12 7v5l3 2'],
  'message': ['M3 5h18v14H3z', 'M3 5l9 7 9-7'],
  'card': [
    'M5 5h14a2.5 2.5 0 0 1 2.5 2.5v9a2.5 2.5 0 0 1 -2.5 2.5h-14a2.5 2.5 0 0 1 -2.5 -2.5v-9a2.5 2.5 0 0 1 2.5 -2.5z',
    'M2.5 10h19M6 15h4'
  ],
  'bank': ['M3 9.5L12 4l9 5.5M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 20h18'],
  'scan': [
    'M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10'
  ],
  'img': [
    'M5.5 4h13a2.5 2.5 0 0 1 2.5 2.5v11a2.5 2.5 0 0 1 -2.5 2.5h-13a2.5 2.5 0 0 1 -2.5 -2.5v-11a2.5 2.5 0 0 1 2.5 -2.5z',
    'M7 10a2 2 0 1 0 4 0a2 2 0 1 0 -4 0z',
    'M21 16l-5-5-9 9'
  ],
  'plus': ['M12 5v14M5 12h14'],
  'keys': [
    'M6 3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12a3 3 0 0 1 3 -3z',
    'M8 8h.01M12 8h.01M16 8h.01M8 12h.01M12 12h.01M16 12h.01M8 16h8'
  ],
  'note': ['M4 5h11M4 10h11M4 15h7M15.5 19.5l5-5-2-2-5 5V20h2z'],
  'moon': ['M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z'],
  'spark': ['M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z'],
  'pin': [
    'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z',
    'M9.5 9a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0z'
  ],
  'del': [
    'M21 5H9l-6 7 6 7h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1z',
    'M17 9l-5 6M12 9l5 6'
  ],
  'arrow': ['M19 12H5M11 6l-6 6 6 6'],
  'rotate': ['M20 12a8 8 0 1 1-2.3-5.7L20 8', 'M20 3v5h-5'],
  'box': ['M12 3l8.5 4.5v9L12 21l-8.5-4.5v-9z', 'M3.5 7.5L12 12l8.5-4.5M12 12v9'],
  'scale': [
    'M12 4v16M8 20h8M5 7h14',
    'M5 7l-3 6a3 3 0 0 0 6 0zM19 7l-3 6a3 3 0 0 0 6 0z'
  ],
};
