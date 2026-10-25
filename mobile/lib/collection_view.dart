import 'package:flutter/material.dart';

import 'app_identity.dart';
import 'payment_page.dart' show bankLogoAsset;

class CollectionView extends StatefulWidget {
  const CollectionView(
      {required this.search,
      required this.results,
      required this.today,
      required this.busy,
      required this.online,
      required this.error,
      required this.hasMore,
      required this.onSearchChanged,
      required this.onLoadMore,
      required this.onSearch,
      required this.onOpen,
      this.canRecordPayments = true,
      this.paymentMethods = const [],
      this.initiallyShowingToday = false,
      this.onWeeklyReadings,
      this.onRefresh,
      super.key});
  final TextEditingController search;
  final List<Map<String, dynamic>> results;
  final List<Map<String, dynamic>> today;
  final bool busy;
  final bool online;
  final String? error;
  final bool hasMore;
  final VoidCallback onSearchChanged;
  final VoidCallback onLoadMore;
  final VoidCallback onSearch;
  final ValueChanged<Map<String, dynamic>> onOpen;
  final bool canRecordPayments;
  final List<String> paymentMethods;
  final bool initiallyShowingToday;
  final VoidCallback? onWeeklyReadings;
  final Future<void> Function()? onRefresh;
  @override
  State<CollectionView> createState() => _CollectionViewState();
}

class _CollectionViewState extends State<CollectionView> {
  late bool showingToday = widget.initiallyShowingToday;
  @override
  Widget build(BuildContext context) {
    final showingPayments = widget.canRecordPayments && showingToday;
    final total = widget.today
        .fold<double>(0, (sum, payment) => sum + inShekels(payment));
    final cash = widget.today
        .where((payment) => payment['payment_method'] == 'cash')
        .fold<double>(0, (sum, payment) => sum + inShekels(payment));
    final transfers = widget.today
        .where((payment) => payment['payment_method'] == 'bank_transfer')
        .fold<double>(0, (sum, payment) => sum + inShekels(payment));
    final methodStats = <Widget>[
      if (widget.paymentMethods.contains('cash'))
        AppStat('نقدًا اليوم', '${AppIdentity.grouped(cash)} ₪',
            color: AppIdentity.brand),
      if (widget.paymentMethods.contains('bank_transfer'))
        AppStat('تحويلات اليوم', '${AppIdentity.grouped(transfers)} ₪',
            color: AppIdentity.good),
    ];
    final totalStat = AppStat('حُصّل اليوم', '${AppIdentity.grouped(total)} ₪');
    final list = ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [
          if (widget.canRecordPayments)
            AppSegment(
                selected: showingToday ? 1 : 0,
                onChanged: (index) => setState(() => showingToday = index == 1),
                labels: [
                  (color) => Text('المشتركون',
                      style: AppIdentity.body(14.5,
                          weight: FontWeight.w700, color: color)),
                  (color) => Text.rich(
                      TextSpan(children: [
                        const TextSpan(text: 'دفعاتي اليوم '),
                        TextSpan(
                            text: '${widget.today.length}',
                            style: AppIdentity.number(12,
                                color: color.withValues(alpha: .7))),
                      ]),
                      style: AppIdentity.body(14.5,
                          weight: FontWeight.w700, color: color)),
                ]),
          if (widget.onWeeklyReadings != null)
            InkWell(
              onTap: widget.onWeeklyReadings,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(4, 10, 4, 0),
                child: Row(children: [
                  AppIcon('hist', color: AppIdentity.info),
                  const SizedBox(width: 10),
                  Expanded(
                      child: Text('القراءات الأسبوعية',
                          style: AppIdentity.body(14,
                              weight: FontWeight.w600,
                              color: AppIdentity.info))),
                  AppIcon('chev', size: 18, color: AppIdentity.info),
                ]),
              ),
            ),
          if (!showingPayments) ...[
            const SizedBox(height: 10),
            AppSearchField(
                controller: widget.search,
                hint: 'الاسم أو رقم الحساب أو الطبلون',
                maxLength: 100,
                onChanged: (_) => widget.onSearchChanged(),
                onSubmitted: (_) => widget.onSearch(),
                onClear: () {
                  widget.search.clear();
                  widget.onSearch();
                }),
            AppSection('المشتركون في منطقتك',
                note:
                    '${widget.results.length}${widget.hasMore ? '+' : ''} مشترك'),
            if (widget.busy)
              Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                          minHeight: 3,
                          color: AppIdentity.brand,
                          backgroundColor: AppIdentity.sunken))),
            if (widget.error != null) ...[
              AppNotice(widget.error!, error: true),
              TextButton(
                  onPressed: widget.busy ? null : widget.onSearch,
                  child: const Text('إعادة المحاولة')),
            ],
            if (widget.results.isEmpty && !widget.busy && widget.error == null)
              AppRows(children: [
                widget.search.text.trim().isNotEmpty
                    ? AppEmpty(
                        'search',
                        'لا توجد نتائج لـ «${widget.search.text.trim()}»',
                        'تأكد من الاسم، أو ابحث برقم الحساب أو رقم الطبلون.')
                    : const AppEmpty('search', 'لا يوجد مشتركون متاحون',
                        'اكتب الاسم أو رقم الحساب لعرض المشترك.'),
              ]),
            if (widget.results.isNotEmpty)
              AnimatedOpacity(
                opacity: widget.busy ? .55 : 1,
                duration: const Duration(milliseconds: 150),
                child: AppRows(children: [
                  for (final subscriber in widget.results)
                    subscriberRow(subscriber),
                ]),
              ),
            if (widget.hasMore)
              Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: AppAction(
                      label: 'عرض المزيد',
                      primary: false,
                      busy: widget.busy,
                      onPressed: widget.busy ? null : widget.onLoadMore)),
          ] else ...[
            const SizedBox(height: 12),
            if (methodStats.length < 2)
              Row(children: [
                Expanded(child: totalStat),
                if (methodStats.isNotEmpty) ...[
                  const SizedBox(width: 8),
                  Expanded(child: methodStats.single),
                ],
              ])
            else ...[
              totalStat,
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: methodStats.first),
                const SizedBox(width: 8),
                Expanded(child: methodStats.last),
              ]),
            ],
            const AppSection('الدفعات المسجّلة'),
            AppRows(children: [
              if (widget.today.isEmpty)
                Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 18, vertical: 28),
                    child: Text('لم تُسجّل دفعات اليوم بعد.',
                        textAlign: TextAlign.center,
                        style: AppIdentity.body(13, color: AppIdentity.faint)))
              else
                for (final payment in widget.today) paymentRow(payment),
            ]),
          ],
          const SizedBox(height: 12),
          AppNotice(widget.canRecordPayments
              ? 'تسجيل الدفعات يتطلب اتصالًا بالإنترنت. تُسجّل مباشرة في السجل المالي بعد تأكيد استلامها.'
              : 'عرض كشوف الحساب يتطلب اتصالًا بالإنترنت. تسجيل الدفعات يحتاج صلاحية منفصلة.'),
        ]);
    return widget.onRefresh == null
        ? list
        : RefreshIndicator(
            color: AppIdentity.brand,
            onRefresh: widget.onRefresh!,
            child: list);
  }

  Widget subscriberRow(Map<String, dynamic> subscriber) {
    return Opacity(
      opacity: widget.online ? 1 : .6,
      child: AppRow(
        onTap: widget.online && !widget.busy
            ? () => widget.onOpen(subscriber)
            : null,
        leading: AppAvatar('${subscriber['full_name']}'),
        title: Text('${subscriber['full_name']}',
            style: AppIdentity.body(15, weight: FontWeight.w700)),
        trailing: AppIcon('chev', size: 18, color: AppIdentity.faint),
      ),
    );
  }

  /// What a payment took off the balance; older servers sent shekels only.
  double inShekels(Map<String, dynamic> payment) =>
      double.tryParse('${payment['amount_in_shekels'] ?? payment['amount']}') ??
      0;

  Widget paymentRow(Map<String, dynamic> payment) {
    final logo = bankLogoAsset('${payment['bank_name']}');
    return AppRow(
      adaptiveTrailing: true,
      leading: payment['payment_method'] != 'cash' && logo != null
          ? ClipRRect(
              borderRadius: BorderRadius.circular(14),
              child:
                  Image.asset(logo, width: 44, height: 44, fit: BoxFit.cover))
          : AppIconTile(
              payment['payment_method'] == 'cash' ? 'cash' : 'bank',
              payment['payment_method'] == 'cash'
                  ? AppIdentity.good
                  : AppIdentity.muted,
              AppIdentity.sunken,
              size: 44),
      title: AppRowTitle('${payment['subscription']}'),
      subtitle: AppRowNote(
          '${payment['payment_method'] == 'cash' ? 'نقد' : 'تحويل${payment['bank_name'] == null ? '' : ' إلى ${payment['bank_name']}'}'} · سند ${payment['voucher_number'] ?? '—'}${payment['bank_received_on'] == null ? '' : '\nوصول البنك: ${AppIdentity.shortDate(payment['bank_received_on'])}'}'),
      trailing: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(
            '${AppIdentity.grouped(payment['amount'])} ${const {
                  'USD': r'$',
                  'JOD': 'JD'
                }[payment['currency']] ?? '₪'}',
            style: AppIdentity.number(14.5, color: AppIdentity.good)),
        Text('مسجّلة', style: AppIdentity.body(12, color: AppIdentity.good)),
      ]),
    );
  }
}
