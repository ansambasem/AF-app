/// The app's buttons, notices, strips, segments, chips, search box and switches.
library;

import 'package:flutter/material.dart';

import 'app_identity.dart';

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
      this.autofocus = false,
      super.key});
  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onChanged;
  final VoidCallback onClear;
  final ValueChanged<String>? onSubmitted;
  final int? maxLength;
  final Key? fieldKey;
  final bool autofocus;
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
              autofocus: autofocus,
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
