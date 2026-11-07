import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';

/// Box type, its tab label, icon and full name, in the website's order.
const _boxTypes = [
  ('cash', 'نقدي', 'cash', 'الصندوق النقدي'),
  ('bank_transfer', 'حوالات بنكية', 'bank', 'صندوق الحوالات البنكية'),
  ('clearing', 'مقاصة', 'scale', 'صندوق المقاصات'),
];

/// The tab labels of [types] in the website's order: «نقدي · مقاصة».
String cashBoxTypesSummary(Set<String> types) => _boxTypes
    .where((type) => types.contains(type.$1))
    .map((type) => type.$2)
    .join(' · ');

/// Currency code, symbol and name, in the website's order.
const _currencies = [
  ('ILS', '₪', 'شيكل'),
  ('USD', '\$', 'دولار'),
  ('JOD', 'JD', 'دينار'),
];

/// The collector's open boxes, one tab per box type, with every payment in
/// them. Only the branch head closes a box, so the page only shows them.
class CashBoxesPage extends StatefulWidget {
  const CashBoxesPage({required this.api, required this.types, super.key});
  final ApiClient api;

  /// The box types the collector's permissions take payments into. A box of
  /// another type still shows while it is open with payments in it.
  final Set<String> types;

  @override
  State<CashBoxesPage> createState() => _CashBoxesPageState();
}

class _CashBoxesPageState extends State<CashBoxesPage> {
  int requestId = 0;
  String? selectedType;
  bool tabChosen = false;
  bool busy = true;
  String? error;
  List<Map<String, dynamic>> boxes = [];

  List<Map<String, dynamic>> boxesOf(String type) =>
      boxes.where((box) => box['type'] == type).toList();

  List<Map<String, dynamic>> entriesOf(Map<String, dynamic> box) =>
      (box['payments'] as List? ?? [])
          .map((entry) => Map<String, dynamic>.from(entry as Map))
          .toList();

  int countOf(String type) =>
      boxesOf(type).fold(0, (sum, box) => sum + entriesOf(box).length);

  List<(String, String, String, String)> get shownTypes => _boxTypes
      .where((type) => widget.types.contains(type.$1) || countOf(type.$1) > 0)
      .toList();

  @override
  void initState() {
    super.initState();
    unawaited(load());
  }

  Future<void> load() async {
    final request = ++requestId;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.cashBoxes();
      if (!mounted || request != requestId) return;
      setState(() {
        boxes = (result['boxes'] as List? ?? [])
            .map((box) => Map<String, dynamic>.from(box as Map))
            .where((box) => box['status'] == 'open')
            .toList();
        if (!tabChosen) {
          tabChosen = true;
          selectedType =
              shownTypes.where((type) => countOf(type.$1) > 0).firstOrNull?.$1;
        }
      });
    } on ApiException catch (exception) {
      if (!mounted || request != requestId) return;
      setState(() {
        error = exception.statusCode == 403
            ? 'ليس لديك صلاحية عرض الصناديق. اطلب من الإدارة صلاحية تسجيل الدفعات.'
            : exception.statusCode == 401
                ? 'انتهت جلسة الدخول. ارجع وسجّل الدخول مجددًا.'
                : exception.message;
        if (exception.statusCode == 401 || exception.statusCode == 403) {
          boxes = [];
        }
      });
    } finally {
      if (mounted && request == requestId) setState(() => busy = false);
    }
  }

  (Color, Color) toneOf(String type) => switch (type) {
        'cash' => (AppIdentity.good, AppIdentity.goodTint),
        'bank_transfer' => (AppIdentity.info, AppIdentity.infoTint),
        _ => (AppIdentity.brand, AppIdentity.brandTint),
      };

  /// 2026-10-20T08:05:00Z -> 20/10 10:05, in the phone's time.
  String dateTime(dynamic value) {
    final date = DateTime.tryParse('$value')?.toLocal();
    if (date == null) return '—';
    String two(int number) => '$number'.padLeft(2, '0');
    return '${two(date.day)}/${two(date.month)} ${two(date.hour)}:${two(date.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final types = shownTypes;
    final (type, label, _, name) =
        types.where((option) => option.$1 == selectedType).firstOrNull ??
            types.firstOrNull ??
            _boxTypes.first;
    final open = boxesOf(type);
    return AppKeyboardBackScope(
        child: Scaffold(
            body: SafeArea(
                child: Column(children: [
      AppHeader(
          title: 'صندوقي',
          subtitle: types.length == 1
              ? '$name المفتوح'
              : 'صناديقك المفتوحة حسب النوع',
          onBack: () => Navigator.pop(context)),
      Expanded(
          child: RefreshIndicator(
              color: AppIdentity.brand,
              onRefresh: load,
              child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                  children: [
                    const AppNotice(
                        'رئيس الفرع هو من يغلق الصناديق. عندما تنتهي أخبره أن صندوقك جاهز للإغلاق.',
                        icon: 'info'),
                    const SizedBox(height: 12),
                    if (types.length > 1) ...[
                      AppSegment(
                          key: const ValueKey('cash-box-types'),
                          selected:
                              types.indexWhere((option) => option.$1 == type),
                          onChanged: (index) =>
                              setState(() => selectedType = types[index].$1),
                          labels: [
                            for (final (code, name, _, _) in types)
                              (color) => FittedBox(
                                  fit: BoxFit.scaleDown,
                                  child: Text.rich(
                                      TextSpan(children: [
                                        TextSpan(text: '$name '),
                                        TextSpan(
                                            text: '${countOf(code)}',
                                            style: AppIdentity.number(12,
                                                color: color.withValues(
                                                    alpha: .7))),
                                      ]),
                                      style: AppIdentity.body(14,
                                          weight: FontWeight.w700,
                                          color: color))),
                          ]),
                      const SizedBox(height: 12),
                    ],
                    if (busy)
                      Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: ClipRRect(
                              borderRadius: BorderRadius.circular(4),
                              child: LinearProgressIndicator(
                                  minHeight: 3,
                                  color: AppIdentity.brand,
                                  backgroundColor: AppIdentity.sunken))),
                    if (error != null) ...[
                      AppNotice(error!, error: true),
                      TextButton(
                          onPressed: busy ? null : () => unawaited(load()),
                          child: const Text('إعادة المحاولة')),
                    ],
                    if (!busy && error == null && open.isEmpty)
                      AppRows(children: [
                        Padding(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 18, vertical: 28),
                            child: Text('لا يوجد صندوق $label مفتوح.',
                                textAlign: TextAlign.center,
                                style: AppIdentity.body(13,
                                    color: AppIdentity.faint)))
                      ]),
                    for (final box in open) ...[
                      summary(box, type),
                      const SizedBox(height: 10),
                      if (entriesOf(box).isNotEmpty)
                        AppRows(children: [
                          for (final entry in entriesOf(box))
                            entryRow(entry, type),
                        ]),
                      const SizedBox(height: 16),
                    ],
                  ]))),
    ]))));
  }

  Widget summary(Map<String, dynamic> box, String type) {
    final (color, tint) = toneOf(type);
    final icon = _boxTypes.firstWhere((item) => item.$1 == type).$3;
    final totals = box['totals'] is Map ? box['totals'] as Map : const {};
    final shown = _currencies.where((currency) =>
        currency.$1 == 'ILS' ||
        (double.tryParse('${totals[currency.$1]}') ?? 0) != 0);
    return AppPanel(
        key: ValueKey('cash-box-${box['id']}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            AppIconTile(icon, color, tint),
            const SizedBox(width: 12),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text('صندوق #${box['number']}',
                      style: AppIdentity.body(15.5, weight: FontWeight.w700)),
                  Text(
                      '${AppIdentity.paymentsCount(entriesOf(box).length)} · مفتوح منذ ${dateTime(box['opened_at'])}',
                      style: AppIdentity.body(12.5, color: AppIdentity.faint)),
                ])),
            const SizedBox(width: 8),
            const AppTag('مفتوح', AppTone.good),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            for (final (code, symbol, name) in shown)
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text(name,
                        style: AppIdentity.body(12, color: AppIdentity.faint)),
                    FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                            '${AppIdentity.grouped(totals[code])} $symbol',
                            textDirection: TextDirection.ltr,
                            style: AppIdentity.number(18,
                                weight: FontWeight.w800))),
                  ])),
          ]),
        ]));
  }

  Widget entryRow(Map<String, dynamic> entry, String type) {
    final (color, tint) = toneOf(type);
    final transaction =
        entry['transaction'] is Map ? entry['transaction'] as Map : const {};
    final subscription = transaction['subscription'] is Map
        ? transaction['subscription'] as Map
        : const {};
    String? text(Object? value) =>
        '${value ?? ''}'.trim().isEmpty ? null : '$value'.trim();
    final details = [
      if (text(subscription['account_number']) != null)
        'حساب ${subscription['account_number']}',
      dateTime(entry['occurred_at']),
      if (type == 'bank_transfer' && text(transaction['bank_name']) != null)
        '${transaction['bank_name']}',
      if (type != 'cash' && text(transaction['reference_number']) != null)
        'مرجع ${transaction['reference_number']}',
      if (type == 'clearing' && text(transaction['notes']) != null)
        '${transaction['notes']}',
      if (text(transaction['voucher_number']) != null)
        'سند ${transaction['voucher_number']}',
    ];
    final symbol = _currencies
            .where((currency) => currency.$1 == entry['currency'])
            .firstOrNull
            ?.$2 ??
        '₪';
    return AppRow(
        key: ValueKey('cash-box-entry-${entry['id']}'),
        adaptiveTrailing: true,
        leading: AppIconTile(
            _boxTypes.firstWhere((item) => item.$1 == type).$3, color, tint,
            size: 40),
        title: AppRowTitle(text(subscription['full_name']) ??
            text(subscription['subscription_name']) ??
            'مشترك'),
        subtitle: AppRowNote(details.join(' · ')),
        trailing: Text('${AppIdentity.grouped(entry['amount'])} $symbol',
            textDirection: TextDirection.ltr, style: AppIdentity.number(14.5)));
  }
}
