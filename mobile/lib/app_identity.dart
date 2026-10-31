import 'package:flutter/material.dart';

export 'app_controls.dart';
export 'app_icons.dart';
export 'app_keypad.dart';
export 'app_navigation.dart';
export 'app_surfaces.dart';
export 'app_visuals.dart';

bool dismissAppKeyboard(BuildContext context) {
  if (MediaQuery.viewInsetsOf(context).bottom <= 0 &&
      View.of(context).viewInsets.bottom <= 0) return false;
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

  /// How many payments, worded as Arabic counts them.
  static String paymentsCount(int count) => switch (count) {
        0 => 'لا دفعات',
        1 => 'دفعة واحدة',
        2 => 'دفعتان',
        <= 10 => '$count دفعات',
        _ => '$count دفعة',
      };

  /// A date the server sent, day first: '2026-10-01 12:30' -> '01/10/2026 12:30'.
  /// Anything that does not start with a year-first date stays as it is.
  static String dayFirst(dynamic value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})((?: .*)?)$').firstMatch('$value');
    return match == null
        ? '$value'
        : '${match[3]}/${match[2]}/${match[1]}${match[4]}';
  }

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
