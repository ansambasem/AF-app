/// The tab bar along the bottom, bottom sheets and toasts.
library;

import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'app_identity.dart';

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
