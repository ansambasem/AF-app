import 'dart:async';
import 'dart:io';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'app_icons.dart';

export 'app_icons.dart';

bool dismissAppKeyboard(BuildContext context) {
  if (MediaQuery.viewInsetsOf(context).bottom <= 0) return false;
  FocusManager.instance.primaryFocus?.unfocus();
  return true;
}

/// A back gesture dismisses the keyboard before leaving a screen.
class AppKeyboardBackScope extends StatelessWidget {
  const AppKeyboardBackScope(
      {required this.child, this.canPop = true, this.onBack, super.key});

  final Widget child;
  final bool canPop;
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) {
    final keyboardVisible = MediaQuery.viewInsetsOf(context).bottom > 0;
    return PopScope(
        canPop: canPop && !keyboardVisible,
        onPopInvokedWithResult: (didPop, _) {
          if (didPop) return;
          if (keyboardVisible) {
            FocusManager.instance.primaryFocus?.unfocus();
            return;
          }
          onBack?.call();
        },
        child: child);
  }
}

/// Colours, type and numbers used everywhere. The palette follows the
/// phone's light or dark setting: [dark] is set once per frame by the app
/// root, so every colour here is a getter rather than a constant.
abstract final class AppIdentity {
  static bool dark = false;

  static Color _pick(int light, int night) => Color(dark ? night : light);

  static Color get background => _pick(0xFFF3F4F6, 0xFF0B1016);
  static Color get surface => _pick(0xFFFFFFFF, 0xFF131B23);
  static Color get raised => _pick(0xFFF7F8FA, 0xFF18212B);
  static Color get sunken => _pick(0xFFEEF0F3, 0xFF0F161D);
  static Color get line => _pick(0xFFE2E5E9, 0xFF27323D);
  static Color get lineSoft => _pick(0xFFECEEF1, 0xFF1E2832);
  static Color get ink => _pick(0xFF111820, 0xFFEEF2F6);
  static Color get ink2 => _pick(0xFF2C3540, 0xFFCFD6DE);
  static Color get muted => _pick(0xFF4C5460, 0xFFA3ADB8);
  static Color get faint => _pick(0xFF7A838E, 0xFF76828F);
  static Color get brand => _pick(0xFFA51D26, 0xFFF0646B);
  static Color get brand2 => _pick(0xFFC8323B, 0xFFFF7C82);
  static Color get brandTint => _pick(0x12A51D26, 0x1CF0646B);
  static Color get brandSoft => _pick(0xFFF9EEF0, 0x18F0646B);
  static Color get good => _pick(0xFF047857, 0xFF34C78F);
  static Color get goodTint => _pick(0x14047857, 0x1C34C78F);
  static Color get warning => _pick(0xFFB45309, 0xFFF3A34A);
  static Color get warningTint => _pick(0x14B45309, 0x1CF3A34A);
  static Color get bad => _pick(0xFFC81E1E, 0xFFFF6B70);
  static Color get badTint => _pick(0x12C81E1E, 0x1CFF6B70);
  static Color get info => _pick(0xFF1F5FD1, 0xFF6AA1FF);
  static Color get infoTint => _pick(0x121F5FD1, 0x1C6AA1FF);
  static Color get selected => _pick(0xFF111820, 0xFFEEF2F6);
  static Color get selectedInk => _pick(0xFFFFFFFF, 0xFF0B1016);
  static Color get keyFill => _pick(0xFFFFFFFF, 0xFF1C2630);
  static Color get keypad => _pick(0xFFE7E9ED, 0xFF0F161D);

  /// Text on the dark hero panels, which look the same in both modes.
  static const heroInk = Color(0xFFFFFFFF);
  static const heroFaint = Color(0xFFAEB6C1);
  static const heroGood = Color(0xFF6EE7B7);
  static const heroWarn = Color(0xFFFCD34D);
  static const heroBad = Color(0xFFFCA5A5);

  /// The plain dark gradient, for small tiles; big panels use [AppHero].
  static const hero = LinearGradient(
    begin: Alignment(-.42, -.9),
    end: Alignment(.42, .9),
    colors: [Color(0xFF262C34), Color(0xFF1C2127), Color(0xFF111418)],
    stops: [0, .55, 1],
  );
  static const action = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [Color(0xFFB8232C), Color(0xFF7D121B)],
  );

  static TextStyle body(double size,
          {FontWeight weight = FontWeight.normal, Color? color}) =>
      TextStyle(
          fontFamily: 'PlexArabic',
          fontSize: size,
          height: 1.4,
          fontWeight: weight,
          color: color ?? ink);
  static TextStyle heading(double size, {Color? color}) => TextStyle(
      fontFamily: 'ElMessiri',
      fontSize: size,
      height: 1.3,
      fontWeight: FontWeight.w700,
      color: color ?? ink);
  static TextStyle number(double size,
          {Color? color, FontWeight weight = FontWeight.w700}) =>
      TextStyle(
          fontFamily: 'Alexandria',
          fontFamilyFallback: const ['PlexArabic'],
          fontSize: size,
          height: 1.2,
          fontWeight: weight,
          color: color ?? ink);

  static List<BoxShadow> get shadow => dark
      ? const [
          BoxShadow(
              color: Color(0x66000000), blurRadius: 2, offset: Offset(0, 1)),
          BoxShadow(
              color: Color(0x55000000), blurRadius: 18, offset: Offset(0, 6)),
        ]
      : const [
          BoxShadow(
              color: Color(0x0A101828), blurRadius: 2, offset: Offset(0, 1)),
          BoxShadow(
              color: Color(0x0D101828), blurRadius: 18, offset: Offset(0, 6)),
        ];

  static BoxDecoration card({double radius = 22}) => BoxDecoration(
      color: surface,
      border: Border.all(color: lineSoft),
      borderRadius: BorderRadius.circular(radius),
      boxShadow: shadow);

  static ThemeData get theme {
    final scheme = ColorScheme.fromSeed(
        seedColor: const Color(0xFFA51D26),
        brightness: dark ? Brightness.dark : Brightness.light,
        primary: brand,
        surface: surface);
    OutlineInputBorder border(Color color) => OutlineInputBorder(
        borderRadius: BorderRadius.circular(15),
        borderSide: BorderSide(color: color, width: 1.5));
    return ThemeData(
      useMaterial3: true,
      brightness: dark ? Brightness.dark : Brightness.light,
      fontFamily: 'PlexArabic',
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      canvasColor: background,
      splashFactory: InkRipple.splashFactory,
      textTheme: const TextTheme(
        titleLarge:
            TextStyle(fontFamily: 'ElMessiri', fontWeight: FontWeight.w700),
      ),
      textSelectionTheme: TextSelectionThemeData(cursorColor: brand),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        hintStyle: body(15, color: faint),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: border(line),
        enabledBorder: border(line),
        disabledBorder: border(line),
        focusedBorder: border(brand),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: selected,
        contentTextStyle: body(14, weight: FontWeight.w600, color: selectedInk),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 96),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        modalBarrierColor: const Color(0x8005080B),
        shape: const RoundedRectangleBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      ),
      dialogTheme: DialogThemeData(
          backgroundColor: surface,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(24))),
    );
  }

  /// 1250.5 -> 1,250.50, as the money amounts are shown.
  static String grouped(dynamic value) {
    final text = (double.tryParse('$value') ?? 0).toStringAsFixed(2);
    final parts = text.split('.');
    final negative = parts.first.startsWith('-');
    final digits = negative ? parts.first.substring(1) : parts.first;
    final buffer = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(',');
      buffer.write(digits[i]);
    }
    return '${negative ? '-' : ''}$buffer.${parts.last}';
  }

  static String money(dynamic value) =>
      (double.tryParse('$value') ?? 0).toStringAsFixed(2);

  /// A meter reading without trailing zeros: 1250, not 1250.00.
  static String reading(dynamic value) {
    final number = double.tryParse('$value');
    if (number == null) return value == null ? '—' : '$value';
    return number == number.roundToDouble()
        ? number.toStringAsFixed(0)
        : number.toString();
  }

  /// How many readings, worded as Arabic counts them.
  static String readingsCount(int count) => switch (count) {
        0 => 'لا قراءات',
        1 => 'قراءة واحدة',
        2 => 'قراءتان',
        <= 10 => '$count قراءات',
        _ => '$count قراءة',
      };

  /// A week's start date as day/month, the way the field staff say it.
  static String shortDate(dynamic value) {
    final date = DateTime.tryParse('$value');
    return date == null
        ? '—'
        : '${'${date.day}'.padLeft(2, '0')}/${'${date.month}'.padLeft(2, '0')}';
  }
}

/// Re-runs every widget's build, so colours read from [AppIdentity] again
/// after the phone switches between light and dark.
void rebuildEverything() {
  void visit(Element element) {
    element.markNeedsBuild();
    element.visitChildren(visit);
  }

  WidgetsBinding.instance.rootElement?.visitChildren(visit);
}

/// Shows or hides [child] by sliding it open or closed.
class AppReveal extends StatelessWidget {
  const AppReveal({required this.visible, required this.child, super.key});
  final bool visible;
  final Widget child;
  @override
  Widget build(BuildContext context) => ClipRect(
        child: AnimatedSize(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
          alignment: Alignment.topCenter,
          child: visible ? child : const SizedBox(width: double.infinity),
        ),
      );
}

/// The dark panel behind the home summary, the payment balance and the
/// payment summary: a charcoal gradient with a red glow at the top and a
/// warm one at the bottom.
class AppHero extends StatelessWidget {
  const AppHero(
      {required this.child,
      this.padding = const EdgeInsets.all(18),
      this.radius = 26,
      this.shadow = true,
      super.key});
  final Widget child;
  final EdgeInsetsGeometry padding;
  final double radius;
  final bool shadow;
  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(radius),
            boxShadow: !shadow
                ? null
                : const [
                    BoxShadow(
                        color: Color(0xFF111820),
                        blurRadius: 36,
                        offset: Offset(0, 18),
                        spreadRadius: -22)
                  ]),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(radius),
          child: CustomPaint(
              painter: const _HeroPainter(),
              child: Padding(padding: padding, child: child)),
        ),
      );
}

class _HeroPainter extends CustomPainter {
  const _HeroPainter();

  ui.Shader _glow(
      Offset center, double rx, double ry, Color color, double end) {
    final matrix = Matrix4.identity()
      ..translateByDouble(center.dx, center.dy, 0, 1)
      ..scaleByDouble(rx, ry, 1, 1)
      ..translateByDouble(-center.dx, -center.dy, 0, 1);
    return ui.Gradient.radial(center, 1, [color, color.withValues(alpha: 0)],
        [0, end], TileMode.clamp, matrix.storage);
  }

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    // CSS linear-gradient(155deg, ...): the gradient line runs through the
    // centre at 155 degrees and is as long as the box's projection on it.
    const sinA = .42261826, cosA = -.90630779;
    final length = (size.width * sinA).abs() + (size.height * cosA).abs();
    final centre = rect.center;
    final direction = const Offset(sinA, -cosA);
    canvas.drawRect(
        rect,
        Paint()
          ..shader = ui.Gradient.linear(
              centre - direction * (length / 2),
              centre + direction * (length / 2),
              const [Color(0xFF262C34), Color(0xFF1C2127), Color(0xFF111418)],
              const [0, .55, 1]));
    canvas.drawRect(
        rect,
        Paint()
          ..shader = _glow(Offset(size.width * .9, 0), size.width * 1.2,
              size.height * .9, const Color(0x50A51D26), .55));
    canvas.drawRect(
        rect,
        Paint()
          ..shader = _glow(Offset(0, size.height * 1.1), size.width * .9,
              size.height * .8, const Color(0x2EB98A3E), .6));
  }

  @override
  bool shouldRepaint(_HeroPainter old) => false;
}

/// The previous reading, the new one and the consumption between them,
/// side by side so a wrong number stands out before it is saved.
class AppReadingCompare extends StatelessWidget {
  const AppReadingCompare(
      {required this.previous,
      required this.current,
      required this.consumption,
      this.currentHint = '—',
      this.currentLabel = 'الجديدة',
      this.alert,
      this.highlightCurrent = false,
      super.key});
  final String previous;
  final String? current;
  final String? consumption;
  final String currentHint;
  final String currentLabel;
  final Color? alert;
  final bool highlightCurrent;

  @override
  Widget build(BuildContext context) => IntrinsicHeight(
        child: Row(children: [
          Expanded(child: _cell('السابقة', previous)),
          _operator('←'),
          Expanded(
              child: _cell(currentLabel, current ?? currentHint,
                  muted: current == null, emphasised: highlightCurrent)),
          _operator('='),
          Expanded(child: _cell('الاستهلاك', consumption ?? '—')),
        ]),
      );

  Widget _operator(String text) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6),
        child:
            Text(text, style: AppIdentity.number(14, color: AppIdentity.faint)),
      );

  Widget _cell(String label, String value,
          {bool emphasised = false, bool muted = false}) =>
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        decoration: BoxDecoration(
            color: emphasised ? AppIdentity.surface : AppIdentity.raised,
            border: emphasised
                ? Border.all(color: alert ?? AppIdentity.brand, width: 1.5)
                : null,
            borderRadius: BorderRadius.circular(14)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label,
              maxLines: 1,
              style: AppIdentity.body(11,
                  weight: FontWeight.w600, color: AppIdentity.faint)),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text(value,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textDirection: TextDirection.ltr,
                style: muted
                    ? AppIdentity.body(14, color: AppIdentity.faint)
                    : AppIdentity.number(17, weight: FontWeight.w800)),
          ),
        ]),
      );
}

class AppPanel extends StatelessWidget {
  const AppPanel(
      {required this.child,
      this.padding = const EdgeInsets.all(14),
      super.key});
  final Widget child;
  final EdgeInsetsGeometry padding;
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        decoration: AppIdentity.card(),
        child: Padding(padding: padding, child: child),
      );
}

/// A card of rows, each separated from the next by a hairline.
class AppRows extends StatelessWidget {
  const AppRows({required this.children, super.key});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Container(
        decoration: AppIdentity.card(),
        clipBehavior: Clip.antiAlias,
        child: Material(
          color: Colors.transparent,
          child: Column(children: [
            for (var i = 0; i < children.length; i++) ...[
              if (i > 0)
                Divider(height: 1, thickness: 1, color: AppIdentity.lineSoft),
              children[i],
            ],
          ]),
        ),
      );
}

/// One row of a list: a leading tile, a title with a line under it, and
/// whatever sits at the far end.
class AppRow extends StatelessWidget {
  const AppRow(
      {required this.title,
      this.subtitle,
      this.leading,
      this.trailing,
      this.onTap,
      this.onLongPress,
      this.adaptiveTrailing = false,
      this.padding = const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      this.crossAxisAlignment = CrossAxisAlignment.center,
      super.key});
  final Widget title;
  final Widget? subtitle;
  final Widget? leading;
  final Widget? trailing;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final bool adaptiveTrailing;
  final EdgeInsetsGeometry padding;
  final CrossAxisAlignment crossAxisAlignment;
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        onLongPress: onLongPress,
        child: Padding(
          padding: padding,
          child: LayoutBuilder(builder: (context, constraints) {
            final textScale = MediaQuery.textScalerOf(context).scale(14) / 14;
            final stackTrailing = adaptiveTrailing &&
                trailing != null &&
                trailing is! AppIcon &&
                constraints.maxWidth < 280 * textScale;
            return Row(crossAxisAlignment: crossAxisAlignment, children: [
              if (leading != null) ...[leading!, const SizedBox(width: 12)],
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    title,
                    if (subtitle != null) subtitle!,
                    if (stackTrailing)
                      Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Align(
                              alignment: AlignmentDirectional.centerEnd,
                              child: trailing!)),
                  ])),
              if (trailing != null && !stackTrailing) ...[
                const SizedBox(width: 12),
                trailing!
              ],
            ]);
          }),
        ),
      );
}

/// A row's name, in bold, on one line.
class AppRowTitle extends StatelessWidget {
  const AppRowTitle(this.text, {this.size = 15, super.key});
  final String text;
  final double size;
  @override
  Widget build(BuildContext context) => Text(text,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: AppIdentity.body(size, weight: FontWeight.w700));
}

/// The grey line under a row's name.
class AppRowNote extends StatelessWidget {
  const AppRowNote(this.text, {this.color, super.key});
  final String text;
  final Color? color;
  @override
  Widget build(BuildContext context) => Text(text,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: AppIdentity.body(12.5, color: color ?? AppIdentity.faint));
}

/// The letter tile that stands in for a person's photo.
class AppAvatar extends StatelessWidget {
  const AppAvatar(this.name, {this.size = 44, super.key});
  final String name;
  final double size;
  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
            color: AppIdentity.sunken,
            borderRadius: BorderRadius.circular(size * .34)),
        child: Text(name.isEmpty ? '?' : name.characters.first,
            style: AppIdentity.number(15,
                weight: FontWeight.w800, color: AppIdentity.ink2)),
      );
}

/// A square tile holding an icon, in a tint.
class AppIconTile extends StatelessWidget {
  const AppIconTile(this.icon, this.color, this.tint,
      {this.size = 44, this.iconSize = 20, super.key});
  final String icon;
  final Color color;
  final Color tint;
  final double size;
  final double iconSize;
  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
            color: tint, borderRadius: BorderRadius.circular(size * .34)),
        child: AppIcon(icon, size: iconSize, color: color),
      );
}

enum AppTone { good, warning, bad, brand, muted }

class AppTag extends StatelessWidget {
  const AppTag(this.label, this.tone, {this.icon, super.key});
  final String label;
  final AppTone tone;
  final String? icon;
  @override
  Widget build(BuildContext context) {
    final (foreground, background) = switch (tone) {
      AppTone.good => (AppIdentity.good, AppIdentity.goodTint),
      AppTone.warning => (AppIdentity.warning, AppIdentity.warningTint),
      AppTone.bad => (AppIdentity.bad, AppIdentity.badTint),
      AppTone.brand => (AppIdentity.brand, AppIdentity.brandSoft),
      AppTone.muted => (AppIdentity.muted, AppIdentity.sunken),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
      decoration: BoxDecoration(
          color: background, borderRadius: BorderRadius.circular(99)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        if (icon != null) ...[
          AppIcon(icon!, size: 13, color: foreground),
          const SizedBox(width: 4),
        ],
        Flexible(
          child: Text(label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppIdentity.body(11.5,
                  weight: FontWeight.w700, color: foreground)),
        ),
      ]),
    );
  }
}

/// A section's title, with a small note at the far end.
class AppSection extends StatelessWidget {
  const AppSection(this.title, {this.note, this.margin, super.key});
  final String title;
  final String? note;
  final EdgeInsetsGeometry? margin;
  @override
  Widget build(BuildContext context) => Padding(
        padding: margin ?? const EdgeInsets.fromLTRB(2, 20, 2, 10),
        child: Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Expanded(child: Text(title, style: AppIdentity.heading(17))),
              if (note != null)
                Flexible(
                    child: Text(note!,
                        textAlign: TextAlign.end,
                        style:
                            AppIdentity.body(12.5, color: AppIdentity.faint))),
            ]),
      );
}

/// A field's title with its small hint at the far end.
class AppLabel extends StatelessWidget {
  const AppLabel(this.title, {this.hint, this.trailing, super.key});
  final String title;
  final String? hint;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(2, 16, 2, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
            Expanded(
                child: Text(title,
                    style: AppIdentity.body(14, weight: FontWeight.w700))),
            if (hint != null)
              Flexible(
                  child: Text(hint!,
                      textAlign: TextAlign.end,
                      style: AppIdentity.body(12, color: AppIdentity.faint))),
          ]),
          if (trailing != null)
            Padding(padding: const EdgeInsets.only(top: 6), child: trailing!),
        ]),
      );
}

class AppEmpty extends StatelessWidget {
  const AppEmpty(this.icon, this.title, this.subtitle,
      {this.good = false, super.key});
  final String icon;
  final String title;
  final String subtitle;
  final bool good;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 28),
        child: Column(children: [
          Container(
              width: 60,
              height: 60,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                  color: good ? AppIdentity.goodTint : AppIdentity.raised,
                  borderRadius: BorderRadius.circular(20)),
              child: AppIcon(icon,
                  size: 28,
                  color: good ? AppIdentity.good : AppIdentity.faint)),
          const SizedBox(height: 10),
          Text(title,
              textAlign: TextAlign.center,
              style: AppIdentity.body(16, weight: FontWeight.w700)),
          const SizedBox(height: 2),
          Text(subtitle,
              textAlign: TextAlign.center,
              style: AppIdentity.body(13, color: AppIdentity.faint)),
        ]),
      );
}

class AppHeader extends StatelessWidget {
  const AppHeader(
      {required this.title,
      this.subtitle,
      this.onBack,
      this.onSync,
      this.syncLabel = 'حالة الإرسال',
      this.online = true,
      this.syncing = false,
      this.pending = 0,
      super.key});
  final String title;
  final String? subtitle;
  final VoidCallback? onBack;
  final VoidCallback? onSync;
  final String syncLabel;
  final bool online;
  final bool syncing;
  final int pending;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
        child: Row(children: [
          if (onBack != null) ...[
            AppIconButton(icon: 'back', label: 'رجوع', onPressed: onBack!),
            const SizedBox(width: 10),
          ],
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text(title,
                    style: AppIdentity.heading(22),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                if (subtitle != null && subtitle!.isNotEmpty)
                  Text(subtitle!,
                      style: AppIdentity.body(12.5,
                          weight: FontWeight.w500, color: AppIdentity.faint),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
              ])),
          if (onSync != null) ...[
            const SizedBox(width: 10),
            AppIconButton(
                icon: syncing
                    ? 'sync'
                    : online
                        ? 'cloud'
                        : 'cloudoff',
                label: syncLabel,
                spinning: syncing,
                badge: pending,
                dot: online ? AppIdentity.good : AppIdentity.warning,
                onPressed: onSync!),
          ],
        ]),
      );
}

/// A rounded square button holding one icon, with an optional count badge
/// and status dot.
class AppIconButton extends StatelessWidget {
  const AppIconButton(
      {required this.icon,
      required this.label,
      required this.onPressed,
      this.spinning = false,
      this.badge = 0,
      this.dot,
      this.size = 44,
      this.iconSize = 20,
      this.flat = false,
      this.color,
      super.key});
  final String icon;
  final String label;
  final VoidCallback? onPressed;
  final bool spinning;
  final int badge;
  final Color? dot;
  final double size;
  final double iconSize;
  final bool flat;
  final Color? color;
  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        label: label,
        child: Tooltip(
          message: label,
          child: SizedBox(
            width: size,
            height: size,
            child: Stack(clipBehavior: Clip.none, children: [
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                      color: AppIdentity.surface,
                      border: Border.all(color: AppIdentity.lineSoft),
                      borderRadius: BorderRadius.circular(size * .34),
                      boxShadow: flat ? null : AppIdentity.shadow),
                  child: Material(
                    color: Colors.transparent,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(size * .34),
                      onTap: onPressed,
                      child: Center(
                          child: AppSpin(
                              spinning: spinning,
                              child: AppIcon(icon,
                                  size: iconSize,
                                  color: color ?? AppIdentity.ink2))),
                    ),
                  ),
                ),
              ),
              if (badge > 0)
                PositionedDirectional(
                    top: -5,
                    end: -5,
                    child: IgnorePointer(
                      child: Container(
                        constraints:
                            const BoxConstraints(minWidth: 20, minHeight: 20),
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                            color: AppIdentity.warning,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                                color: AppIdentity.background, width: 2)),
                        child: Text('$badge',
                            style: AppIdentity.number(11,
                                    weight: FontWeight.w800,
                                    color: Colors.white)
                                .copyWith(height: 1.1)),
                      ),
                    )),
              if (dot != null)
                PositionedDirectional(
                    bottom: 7,
                    end: 7,
                    child: IgnorePointer(
                      child: Container(
                          width: 9,
                          height: 9,
                          decoration: BoxDecoration(
                              color: dot,
                              shape: BoxShape.circle,
                              border: Border.all(
                                  color: AppIdentity.surface, width: 2))),
                    )),
            ]),
          ),
        ),
      );
}

/// Turns [child] round and round while [spinning].
class AppSpin extends StatefulWidget {
  const AppSpin({required this.spinning, required this.child, super.key});
  final bool spinning;
  final Widget child;
  @override
  State<AppSpin> createState() => _AppSpinState();
}

class _AppSpinState extends State<AppSpin> with SingleTickerProviderStateMixin {
  late final controller =
      AnimationController(vsync: this, duration: const Duration(seconds: 1));

  @override
  void initState() {
    super.initState();
    if (widget.spinning) controller.repeat();
  }

  @override
  void didUpdateWidget(AppSpin old) {
    super.didUpdateWidget(old);
    if (widget.spinning && !controller.isAnimating) {
      controller.repeat();
    } else if (!widget.spinning && controller.isAnimating) {
      controller.stop();
      controller.value = 0;
    }
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      RotationTransition(turns: controller, child: widget.child);
}

class AppAction extends StatelessWidget {
  const AppAction(
      {required this.label,
      required this.onPressed,
      this.primary = true,
      this.icon,
      this.busy = false,
      this.height = 54,
      super.key});
  final String label;
  final VoidCallback? onPressed;
  final bool primary;
  final String? icon;
  final bool busy;
  final double height;
  @override
  Widget build(BuildContext context) {
    final foreground = primary ? Colors.white : AppIdentity.ink;
    return Opacity(
      opacity: onPressed == null ? .5 : 1,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: primary ? null : AppIdentity.surface,
          gradient: primary ? AppIdentity.action : null,
          border:
              primary ? null : Border.all(color: AppIdentity.line, width: 1.5),
          borderRadius: BorderRadius.circular(height > 50 ? 18 : 14),
          boxShadow: primary
              ? const [
                  BoxShadow(
                      color: Color(0xFFA51D26),
                      blurRadius: 24,
                      offset: Offset(0, 12),
                      spreadRadius: -14)
                ]
              : null,
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(height > 50 ? 18 : 14),
            onTap: busy ? null : onPressed,
            child: ConstrainedBox(
              constraints:
                  BoxConstraints(minHeight: height, minWidth: double.infinity),
              child: Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                child:
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  if (busy)
                    SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: foreground))
                  else if (icon != null)
                    AppIcon(icon!, size: 20, color: foreground),
                  if (busy || icon != null) const SizedBox(width: 8),
                  Flexible(
                      child: Text(label,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppIdentity.body(height > 50 ? 15.5 : 14,
                              weight: FontWeight.w700, color: foreground))),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A short message in a tinted strip: grey for a tip, amber for a warning,
/// red for a failure, green for a success.
class AppNotice extends StatelessWidget {
  const AppNotice(this.text,
      {this.error = false,
      this.success = false,
      this.warning = false,
      this.icon,
      super.key});
  final String text;
  final bool error;
  final bool success;
  final bool warning;
  final String? icon;
  @override
  Widget build(BuildContext context) {
    final (color, background, glyph) = error
        ? (AppIdentity.bad, AppIdentity.badTint, 'err')
        : success
            ? (AppIdentity.good, AppIdentity.goodTint, 'check')
            : warning
                ? (AppIdentity.warning, AppIdentity.warningTint, 'info')
                : (AppIdentity.muted, AppIdentity.raised, 'info');
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
          color: background, borderRadius: BorderRadius.circular(15)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
            padding: const EdgeInsets.only(top: 2),
            child: AppIcon(icon ?? glyph, size: 17, color: color)),
        const SizedBox(width: 9),
        Expanded(
            child: Text(text,
                style: AppIdentity.body(13,
                    weight: FontWeight.w600, color: color))),
      ]),
    );
  }
}

/// A tinted strip that opens something: «2 readings waiting» and the like.
class AppStrip extends StatelessWidget {
  const AppStrip(
      {required this.icon,
      required this.text,
      required this.action,
      required this.tone,
      required this.onTap,
      super.key});
  final String icon;
  final String text;
  final String action;
  final AppTone tone;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    final (color, background) = switch (tone) {
      AppTone.warning => (AppIdentity.warning, AppIdentity.warningTint),
      AppTone.bad => (AppIdentity.bad, AppIdentity.badTint),
      _ => (AppIdentity.brand, AppIdentity.brandSoft),
    };
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Material(
        color: background,
        borderRadius: BorderRadius.circular(17),
        child: InkWell(
          borderRadius: BorderRadius.circular(17),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(children: [
              AppIcon(icon, color: color),
              const SizedBox(width: 10),
              Expanded(
                  child: Text(text,
                      style: AppIdentity.body(14,
                          weight: FontWeight.w600, color: color))),
              Text(action,
                  style: AppIdentity.body(13.5,
                      weight: FontWeight.w700, color: color)),
              const SizedBox(width: 10),
              AppIcon('chev', size: 18, color: color),
            ]),
          ),
        ),
      ),
    );
  }
}

class AppStat extends StatelessWidget {
  const AppStat(this.label, this.value, {this.color, super.key});
  final String label;
  final String value;
  final Color? color;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: AppIdentity.card(radius: 18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label,
              style: AppIdentity.body(12.5,
                  weight: FontWeight.w600, color: AppIdentity.faint)),
          Text(value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppIdentity.number(22,
                  weight: FontWeight.w800, color: color)),
        ]),
      );
}

/// One of the home screen's big buttons.
class AppServiceCard extends StatelessWidget {
  const AppServiceCard(
      {required this.title,
      required this.subtitle,
      required this.summary,
      required this.icon,
      required this.color,
      required this.tint,
      required this.onTap,
      super.key});
  final String title;
  final String subtitle;
  final String summary;
  final String icon;
  final Color color;
  final Color tint;
  final VoidCallback onTap;

  Widget summaryBadge() => Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
          color: AppIdentity.raised, borderRadius: BorderRadius.circular(10)),
      child: Text(summary,
          style: AppIdentity.number(12.5, color: AppIdentity.ink2)));

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Container(
          decoration: AppIdentity.card(),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: onTap,
              borderRadius: BorderRadius.circular(22),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: LayoutBuilder(builder: (context, constraints) {
                  final stacked = constraints.maxWidth <
                      420 * MediaQuery.textScalerOf(context).scale(14) / 14;
                  return Row(children: [
                    AppIconTile(icon, color, tint, size: 52, iconSize: 24),
                    const SizedBox(width: 14),
                    Expanded(
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                          Text(title,
                              style: AppIdentity.body(16,
                                  weight: FontWeight.w700)),
                          Text(subtitle,
                              style: AppIdentity.body(12.5,
                                  color: AppIdentity.faint)),
                          if (stacked) ...[
                            const SizedBox(height: 6),
                            summaryBadge(),
                          ],
                        ])),
                    if (!stacked) ...[
                      const SizedBox(width: 14),
                      summaryBadge(),
                    ],
                  ]);
                }),
              ),
            ),
          ),
        ),
      );
}

/// The two- or three-way switch with a thumb that slides to the choice.
class AppSegment extends StatelessWidget {
  const AppSegment(
      {required this.labels,
      required this.selected,
      required this.onChanged,
      super.key});
  final List<Widget Function(Color)> labels;
  final int selected;
  final ValueChanged<int> onChanged;
  @override
  Widget build(BuildContext context) => Container(
        height: 50 + (MediaQuery.textScalerOf(context).scale(14.5) - 14.5) * 2,
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
            color: AppIdentity.sunken, borderRadius: BorderRadius.circular(16)),
        child: Stack(children: [
          AnimatedAlign(
            duration: const Duration(milliseconds: 350),
            curve: const Cubic(.22, 1.2, .36, 1),
            alignment: AlignmentDirectional(
                labels.length == 1
                    ? 0
                    : -1 + 2 * selected / (labels.length - 1),
                0),
            child: FractionallySizedBox(
              widthFactor: 1 / labels.length,
              heightFactor: 1,
              child: DecoratedBox(
                  decoration: BoxDecoration(
                      color: AppIdentity.surface,
                      borderRadius: BorderRadius.circular(12),
                      boxShadow: AppIdentity.shadow)),
            ),
          ),
          Row(children: [
            for (var i = 0; i < labels.length; i++)
              Expanded(
                  child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () => onChanged(i),
                child: Center(
                    child: labels[i](
                        i == selected ? AppIdentity.ink : AppIdentity.muted)),
              )),
          ]),
        ]),
      );
}

class AppChip extends StatelessWidget {
  const AppChip(this.label,
      {required this.selected, required this.onTap, this.count, super.key});
  final String label;
  final bool selected;
  final VoidCallback onTap;
  final int? count;
  @override
  Widget build(BuildContext context) {
    final color = selected ? AppIdentity.selectedInk : AppIdentity.muted;
    return Material(
      color: selected ? AppIdentity.selected : AppIdentity.surface,
      shape: StadiumBorder(
          side: BorderSide(
              color: selected ? AppIdentity.selected : AppIdentity.line,
              width: 1.5)),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: onTap,
        child: Container(
          height: 36,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Flexible(
                child: Text(label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppIdentity.body(13,
                        weight: FontWeight.w700, color: color))),
            if (count != null) ...[
              const SizedBox(width: 6),
              Opacity(
                  opacity: .65,
                  child: Text('$count',
                      style: AppIdentity.number(11, color: color))),
            ],
          ]),
        ),
      ),
    );
  }
}

/// The search box used above every list.
class AppSearchField extends StatelessWidget {
  const AppSearchField(
      {required this.controller,
      required this.hint,
      required this.onChanged,
      required this.onClear,
      this.onSubmitted,
      this.maxLength,
      this.fieldKey,
      super.key});
  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onChanged;
  final VoidCallback onClear;
  final ValueChanged<String>? onSubmitted;
  final int? maxLength;
  final Key? fieldKey;
  @override
  Widget build(BuildContext context) => TextFieldTapRegion(
          child: Container(
        height: 52,
        padding: const EdgeInsets.symmetric(horizontal: 14),
        decoration: BoxDecoration(
            color: AppIdentity.surface,
            border: Border.all(color: AppIdentity.lineSoft),
            borderRadius: BorderRadius.circular(17),
            boxShadow: AppIdentity.shadow),
        child: Row(children: [
          AppIcon('search', color: AppIdentity.faint),
          const SizedBox(width: 8),
          Expanded(
            child: TextField(
              key: fieldKey,
              controller: controller,
              onChanged: onChanged,
              onTapOutside: (_) => FocusScope.of(context).unfocus(),
              onSubmitted: onSubmitted,
              maxLength: maxLength,
              textInputAction: TextInputAction.search,
              style: AppIdentity.body(15.5),
              decoration: InputDecoration(
                  counterText: '',
                  hintText: hint,
                  hintStyle: AppIdentity.body(15.5, color: AppIdentity.faint),
                  filled: false,
                  isDense: true,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  contentPadding: EdgeInsets.zero),
            ),
          ),
          if (controller.text.isNotEmpty)
            InkWell(
              borderRadius: BorderRadius.circular(9),
              onTap: onClear,
              child: Tooltip(
                message: 'مسح البحث',
                child: Container(
                    width: 28,
                    height: 28,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                        color: AppIdentity.sunken,
                        borderRadius: BorderRadius.circular(9)),
                    child: AppIcon('x', size: 15, color: AppIdentity.muted)),
              ),
            ),
        ]),
      ));
}

/// A blinking text cursor for the numbers typed on the on-screen keypad.
class AppCaret extends StatefulWidget {
  const AppCaret({this.height = 20, super.key});
  final double height;

  /// Off while tests run, so they can settle.
  static final bool blinking =
      !Platform.environment.containsKey('FLUTTER_TEST');

  @override
  State<AppCaret> createState() => _AppCaretState();
}

class _AppCaretState extends State<AppCaret> {
  Timer? timer;
  bool visible = true;

  @override
  void initState() {
    super.initState();
    if (AppCaret.blinking) {
      timer = Timer.periodic(const Duration(milliseconds: 500),
          (_) => setState(() => visible = !visible));
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Opacity(
        opacity: visible ? 1 : 0,
        child: Container(
            width: 2,
            height: widget.height,
            margin: const EdgeInsetsDirectional.only(start: 2),
            color: AppIdentity.brand),
      );
}

/// The number pad the readings and payments are typed on. A long press on
/// delete sends `clear`. Readings add a wide «next» key under the digits.
class AppKeypad extends StatelessWidget {
  const AppKeypad(
      {required this.onKey,
      this.withNext = false,
      this.enabled = true,
      this.onClose,
      this.nextLabel = 'التالي',
      this.keyPrefix = 'reading',
      this.adaptive = false,
      super.key});
  final ValueChanged<String> onKey;
  final bool withNext;
  final bool enabled;
  final VoidCallback? onClose;
  final String nextLabel;
  final String keyPrefix;
  final bool adaptive;

  static const _keys = [
    '1',
    '2',
    '3',
    '4',
    '5',
    '6',
    '7',
    '8',
    '9',
    '.',
    '0',
    'delete'
  ];

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    var downwardDrag = 0.0;
    // On a short phone the keys shrink so the list above keeps some room.
    final compact = MediaQuery.sizeOf(context).height < 700;
    return GestureDetector(
      onVerticalDragStart: onClose == null ? null : (_) => downwardDrag = 0,
      onVerticalDragUpdate: onClose == null
          ? null
          : (details) {
              downwardDrag += details.primaryDelta ?? 0;
            },
      onVerticalDragEnd: onClose == null
          ? null
          : (details) {
              if (downwardDrag > 36 || (details.primaryVelocity ?? 0) > 250)
                onClose!();
            },
      child: Container(
        padding: EdgeInsets.fromLTRB(
            10, 0, 10, 12 + (bottom > 12 ? bottom - 12 : 0)),
        decoration: BoxDecoration(
            color: AppIdentity.keypad,
            border: Border(top: BorderSide(color: AppIdentity.lineSoft))),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          InkWell(
            key: ValueKey('$keyPrefix-keypad-close'),
            onTap: onClose,
            child: adaptive
                ? ConstrainedBox(
                    constraints: BoxConstraints(
                        minHeight: compact ? 28 : 32,
                        minWidth: double.infinity),
                    child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              AppIcon('down', color: AppIdentity.muted),
                              const SizedBox(width: 4),
                              Flexible(
                                  child: Text('إخفاء لوحة الأرقام',
                                      textAlign: TextAlign.center,
                                      style: AppIdentity.body(12.5,
                                          weight: FontWeight.w600,
                                          color: AppIdentity.muted))),
                            ])),
                  )
                : SizedBox(
                    height: compact ? 28 : 32,
                    width: double.infinity,
                    child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          AppIcon('down', color: AppIdentity.muted),
                          const SizedBox(width: 4),
                          Text('إخفاء لوحة الأرقام',
                              style: AppIdentity.body(12.5,
                                  weight: FontWeight.w600,
                                  color: AppIdentity.muted)),
                        ])),
          ),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Column(children: [
              for (var row = 0; row < 4; row++)
                Padding(
                  padding: EdgeInsets.only(
                      bottom: row == 3 && !withNext ? 0 : (compact ? 5 : 7)),
                  child: Row(children: [
                    for (var column = 0; column < 3; column++) ...[
                      if (column > 0) const SizedBox(width: 7),
                      Expanded(
                          child:
                              _key(_keys[row * 3 + column], compact, context)),
                    ],
                  ]),
                ),
              if (withNext) _next(compact),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _key(String key, bool compact, BuildContext context) => SizedBox(
        height: adaptive
            ? math.max(compact ? 42 : 50,
                MediaQuery.textScalerOf(context).scale(23) * 1.2 + 12)
            : compact
                ? 42
                : 50,
        child: DecoratedBox(
          decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              boxShadow: const [
                BoxShadow(color: Color(0x14101828), offset: Offset(0, 1))
              ]),
          child: Material(
            color: AppIdentity.keyFill,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              key: ValueKey('$keyPrefix-key-$key'),
              borderRadius: BorderRadius.circular(14),
              onTap: enabled ? () => onKey(key) : null,
              onLongPress:
                  enabled && key == 'delete' ? () => onKey('clear') : null,
              child: Center(
                  child: key == 'delete'
                      ? AppIcon('del', size: 22, color: AppIdentity.muted)
                      : key == '.'
                          ? Padding(
                              padding: const EdgeInsets.only(bottom: 10),
                              child: Semantics(
                                  label: 'فاصلة عشرية',
                                  child: Text('.',
                                      style: AppIdentity.number(30)
                                          .copyWith(height: 1))))
                          : Text(key, style: AppIdentity.number(23))),
            ),
          ),
        ),
      );

  Widget _next(bool compact) => SizedBox(
        height: compact ? 42 : 48,
        width: double.infinity,
        child: DecoratedBox(
          decoration: BoxDecoration(
              gradient: AppIdentity.action,
              borderRadius: BorderRadius.circular(14),
              boxShadow: const [
                BoxShadow(color: Color(0x14101828), offset: Offset(0, 1))
              ]),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              key: ValueKey('$keyPrefix-key-next'),
              borderRadius: BorderRadius.circular(14),
              onTap: enabled ? () => onKey('next') : null,
              child: Directionality(
                textDirection: TextDirection.rtl,
                child:
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Text(nextLabel,
                      style: AppIdentity.body(16,
                          weight: FontWeight.w700, color: Colors.white)),
                  const SizedBox(width: 6),
                  const AppIcon('chev', size: 18, color: Colors.white),
                ]),
              ),
            ),
          ),
        ),
      );
}

/// The bar of tabs along the bottom.
class AppNavItem {
  const AppNavItem(this.id, this.label, this.icon, {this.badge = 0});
  final String id;
  final String label;
  final String icon;
  final int badge;
}

class AppNavBar extends StatelessWidget {
  const AppNavBar(
      {required this.items,
      required this.selected,
      required this.onSelect,
      super.key});
  final List<AppNavItem> items;
  final String selected;
  final ValueChanged<String> onSelect;
  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return ClipRect(
      child: BackdropFilter(
        filter: ui.ImageFilter.blur(sigmaX: 8, sigmaY: 8),
        child: Container(
          padding: EdgeInsets.fromLTRB(10, 8, 10, bottom > 24 ? bottom : 24),
          decoration: BoxDecoration(
              color: AppIdentity.surface.withValues(alpha: .92),
              border: Border(top: BorderSide(color: AppIdentity.lineSoft))),
          child: Row(children: [
            for (final item in items)
              Expanded(
                child: Semantics(
                  button: true,
                  selected: item.id == selected,
                  child: InkWell(
                    key: ValueKey('nav-${item.id}'),
                    borderRadius: BorderRadius.circular(16),
                    onTap: () => onSelect(item.id),
                    child: _tab(item, item.id == selected),
                  ),
                ),
              ),
          ]),
        ),
      ),
    );
  }

  Widget _tab(AppNavItem item, bool on) {
    final color = on ? AppIdentity.brand : AppIdentity.muted;
    return Stack(
        clipBehavior: Clip.none,
        alignment: Alignment.topCenter,
        children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              width: 58,
              height: 32,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                  color: on ? AppIdentity.brandTint : Colors.transparent,
                  borderRadius: BorderRadius.circular(16)),
              child: AppIcon(item.icon, color: color),
            ),
            const SizedBox(height: 3),
            Text(item.label,
                maxLines: 2,
                textAlign: TextAlign.center,
                style: AppIdentity.body(11.5,
                    weight: on ? FontWeight.w700 : FontWeight.w600,
                    color: color)),
          ]),
          if (item.badge > 0)
            Positioned(
                top: -3,
                left: null,
                child: Transform.translate(
                  offset: const Offset(15, 0),
                  child: Container(
                    constraints:
                        const BoxConstraints(minWidth: 18, minHeight: 18),
                    padding: const EdgeInsets.symmetric(horizontal: 5),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                        color: AppIdentity.warning,
                        borderRadius: BorderRadius.circular(9)),
                    child: Text('${item.badge}',
                        style: AppIdentity.number(10.5,
                                weight: FontWeight.w800, color: Colors.white)
                            .copyWith(height: 1.1)),
                  ),
                )),
        ]);
  }
}

/// A bottom sheet in the app's style: a grab handle, a title and a line
/// of explanation above [children].
Future<T?> showAppSheet<T>(BuildContext context,
    {required String title, String? message, required List<Widget> children}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: false,
    backgroundColor: AppIdentity.surface,
    shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
    builder: (context) => SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(18, 8, 18, 26),
        child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                  child: Container(
                      width: 42,
                      height: 5,
                      margin: const EdgeInsets.only(top: 4, bottom: 12),
                      decoration: BoxDecoration(
                          color: AppIdentity.line,
                          borderRadius: BorderRadius.circular(3)))),
              Text(title, style: AppIdentity.heading(20)),
              if (message != null)
                Padding(
                    padding: const EdgeInsets.only(top: 4, bottom: 12),
                    child: Text(message,
                        style:
                            AppIdentity.body(13.5, color: AppIdentity.muted)))
              else
                const SizedBox(height: 12),
              ...children,
            ]),
      ),
    ),
  );
}

/// A short confirmation that floats above the tabs and goes by itself.
void showAppToast(BuildContext context, String message,
    {String icon = 'check'}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      duration: const Duration(milliseconds: 2300),
      content: Row(children: [
        AppIcon(icon, size: 20, color: AppIdentity.selectedInk),
        const SizedBox(width: 8),
        Expanded(child: Text(message)),
      ]),
    ));
}

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

/// A switch with its caption, as «the subscriber himself» on the payment form.
class AppToggle extends StatelessWidget {
  const AppToggle(
      {required this.label,
      required this.value,
      required this.onChanged,
      super.key});
  final String label;
  final bool value;
  final ValueChanged<bool>? onChanged;
  @override
  Widget build(BuildContext context) => Semantics(
        toggled: value,
        label: label,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onChanged == null ? null : () => onChanged!(!value),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Flexible(
                child: Text(label,
                    style: AppIdentity.body(13,
                        weight: FontWeight.w600, color: AppIdentity.muted))),
            const SizedBox(width: 8),
            AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              width: 46,
              height: 28,
              padding: const EdgeInsets.all(3),
              alignment: value
                  ? AlignmentDirectional.centerEnd
                  : AlignmentDirectional.centerStart,
              decoration: BoxDecoration(
                  color: value ? AppIdentity.good : AppIdentity.line,
                  borderRadius: BorderRadius.circular(14)),
              child: Container(
                  width: 22,
                  height: 22,
                  decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                            color: Color(0x33000000),
                            blurRadius: 5,
                            offset: Offset(0, 2))
                      ])),
            ),
          ]),
        ),
      );
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
