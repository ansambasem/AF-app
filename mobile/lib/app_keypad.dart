/// The on-screen number pad readings and payments are typed on.
library;

import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'app_identity.dart';

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
