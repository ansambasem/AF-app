import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'app_balance.dart';

class SubscriberHistoryPage extends StatefulWidget {
  const SubscriberHistoryPage(
      {required this.api, required this.subscriber, super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;

  @override
  State<SubscriberHistoryPage> createState() => _SubscriberHistoryPageState();
}

class _SubscriberHistoryPageState extends State<SubscriberHistoryPage> {
  final lines = <Map<String, dynamic>>[];
  bool loading = false;
  int page = 0;
  int lastPage = 1;
  int total = 0;
  String? error;
  bool refreshFailed = false;
  double? currentBalance;
  bool accessDenied = false;

  @override
  void initState() {
    super.initState();
    load(refresh: true);
  }

  Future<void> load({bool refresh = false}) async {
    if (loading) return;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await widget.api.subscriberTransactions(
          widget.subscriber['id'] as int,
          page: refresh ? 1 : page + 1);
      if (!mounted) return;
      setState(() {
        accessDenied = false;
        currentBalance = double.tryParse(
            '${result['balance'] ?? widget.subscriber['balance']}');
        if (refresh) lines.clear();
        lines.addAll((result['data'] as List)
            .map((line) => Map<String, dynamic>.from(line as Map)));
        page = result['current_page'] as int;
        lastPage = result['last_page'] as int;
        total = result['total'] as int;
      });
    } on ApiException catch (exception) {
      if (mounted)
        setState(() {
          if ([401, 403, 404].contains(exception.statusCode)) {
            accessDenied = true;
            currentBalance = null;
            lines.clear();
            page = 0;
            lastPage = 1;
            total = 0;
          }
          error = exception.message;
          refreshFailed = refresh;
        });
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
          body: SafeArea(
              child: Column(children: [
        AppHeader(
            title: 'السجل المالي',
            subtitle: '${widget.subscriber['full_name']}',
            onBack: () => Navigator.pop(context)),
        Expanded(
            child: RefreshIndicator(
                onRefresh: () => load(refresh: true),
                color: AppIdentity.brand,
                child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                    children: [
                      if (!accessDenied && currentBalance != null)
                        AppHero(
                            padding: const EdgeInsets.all(18),
                            child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('الرصيد الحالي',
                                      style: AppIdentity.body(14,
                                          color: AppIdentity.heroFaint)),
                                  const SizedBox(height: 6),
                                  Text(balanceText(currentBalance!),
                                      key: const ValueKey(
                                          'statement-current-balance'),
                                      style: AppIdentity.number(26,
                                          weight: FontWeight.w800,
                                          color: balanceColor(currentBalance!,
                                              onHero: true))),
                                  Text(
                                      'حساب ${widget.subscriber['account_number'] ?? '—'}',
                                      style: AppIdentity.body(13,
                                          color: AppIdentity.heroFaint)),
                                ])),
                      AppSection('جميع الحركات',
                          note: page > 0 ? '$total حركة' : null),
                      if (lines.isNotEmpty)
                        AppRows(children: [
                          for (final line in lines)
                            SubscriberTransactionRow(line)
                        ]),
                      if (!loading && error == null && lines.isEmpty)
                        const AppEmpty('hist', 'لا توجد حركات بعد',
                            'تظهر هنا القراءات والدفعات والحركات المالية.'),
                      if (error != null) ...[
                        const SizedBox(height: 12),
                        AppNotice(error!, error: true),
                        TextButton(
                            onPressed: loading
                                ? null
                                : () =>
                                    load(refresh: refreshFailed || page == 0),
                            child: const Text('إعادة المحاولة')),
                      ] else if (page < lastPage || loading) ...[
                        const SizedBox(height: 12),
                        AppAction(
                            label: page == 0 ? 'تحميل السجل' : 'عرض حركات أقدم',
                            icon: 'hist',
                            busy: loading,
                            onPressed: loading ? null : () => load()),
                      ],
                    ]))),
      ])));
}

class SubscriberTransactionRow extends StatelessWidget {
  const SubscriberTransactionRow(this.line, {super.key});
  final Map<String, dynamic> line;

  @override
  Widget build(BuildContext context) {
    final credit = line['is_credit'] == true;
    final cancelled = line['is_cancelled'] == true;
    final color = cancelled
        ? AppIdentity.faint
        : credit
            ? AppIdentity.good
            : AppIdentity.bad;
    final icon = cancelled
        ? 'x'
        : switch (line['type']) {
            'meter_reading' => 'meter',
            'discount' || 'reading_discount' => 'tag',
            'invoice' || 'subscription_fee' => 'note',
            'refund' || 'reversal' || 'cancellation' => 'rotate',
            'clearing' => 'sync',
            _ => credit ? 'cash' : 'note',
          };
    final (iconColor, tint) = cancelled
        ? (AppIdentity.faint, AppIdentity.sunken)
        : switch (icon) {
            'cash' => (AppIdentity.good, AppIdentity.goodTint),
            'meter' => (AppIdentity.warning, AppIdentity.warningTint),
            'tag' => (AppIdentity.brand, AppIdentity.brandTint),
            _ => (AppIdentity.info, AppIdentity.infoTint),
          };
    return AppRow(
        adaptiveTrailing: true,
        crossAxisAlignment: CrossAxisAlignment.start,
        leading: AppIconTile(icon, iconColor, tint, size: 38, iconSize: 18),
        title: Text(
            '${line['type_label'] ?? line['description'] ?? 'حركة مالية'}',
            style: AppIdentity.body(15,
                weight: FontWeight.w600,
                color: cancelled ? AppIdentity.faint : null)),
        subtitle:
            Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const SizedBox(height: 5),
          Text(AppIdentity.dayFirst(line['recorded_at'] ?? line['date'] ?? '—'),
              textDirection: TextDirection.ltr,
              style: AppIdentity.number(12.5,
                  weight: FontWeight.w500, color: AppIdentity.faint)),
          if (line['description'] != null &&
              line['description'] != line['type_label']) ...[
            const SizedBox(height: 7),
            Text('${line['description']}',
                style: AppIdentity.body(14, color: AppIdentity.muted)),
          ],
          if ('${line['notes'] ?? ''}'.trim().isNotEmpty) ...[
            const SizedBox(height: 5),
            Text('ملاحظات: ${line['notes']}',
                style: AppIdentity.body(13.5, color: AppIdentity.muted)),
          ],
          if ('${line['voucher_number'] ?? ''}'.trim().isNotEmpty)
            Padding(
                padding: const EdgeInsets.only(top: 5),
                child: Text('السند: ${line['voucher_number']}',
                    style: AppIdentity.body(12.5, color: AppIdentity.faint))),
          if ('${line['reference_number'] ?? ''}'.trim().isNotEmpty)
            Padding(
                padding: const EdgeInsets.only(top: 5),
                child: Text('المرجع: ${line['reference_number']}',
                    style: AppIdentity.body(12.5, color: AppIdentity.faint))),
          if (line['bank_received_on'] != null)
            Padding(
                padding: const EdgeInsets.only(top: 5),
                child: Text(
                    'وصول الحوالة إلى البنك: ${AppIdentity.shortDate(line['bank_received_on'])}',
                    style: AppIdentity.body(12.5, color: AppIdentity.muted))),
        ]),
        trailing: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${AppIdentity.grouped(line['amount'])} ₪',
              style: AppIdentity.number(16, color: color)),
          Text(
              cancelled
                  ? 'ملغاة'
                  : credit
                      ? 'له'
                      : 'عليه',
              style:
                  AppIdentity.body(12, weight: FontWeight.w600, color: color)),
        ]));
  }
}
