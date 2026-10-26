import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api_client.dart';
import 'adjustment_page.dart';
import 'app_balance.dart';
import 'app_identity.dart';
import 'payment_page.dart';
import 'split_payment_page.dart';
import 'subscriber_history_page.dart';
import 'subscriber_message_actions.dart';

/// A subscriber's account as the collector sees it before taking a payment:
/// personal and subscription details, the previous reading and access to their
/// financial history. Pops with whether any payment was recorded.
class SubscriberPage extends StatefulWidget {
  const SubscriberPage(
      {required this.api,
      required this.subscriber,
      this.transferBanks = defaultTransferBanks,
      this.senderBanks = const [],
      this.paymentMethods = const ['cash', 'bank_transfer'],
      this.exchangeRates = const {},
      super.key});
  final ApiClient api;

  /// The subscriber as the search listed them, shown until their page loads.
  final Map<String, dynamic> subscriber;
  final List<String> transferBanks;
  final List<String> senderBanks;
  final List<String> paymentMethods;

  /// The company's exchange rates (see [exchangeRatesFrom]).
  final Map<String, double> exchangeRates;
  @override
  State<SubscriberPage> createState() => _SubscriberPageState();
}

class _SubscriberPageState extends State<SubscriberPage>
    with WidgetsBindingObserver {
  Map<String, dynamic>? details;
  bool loading = true;
  String? error;
  bool recordedAny = false;
  Timer? permissionTimer;

  Map<String, dynamic> get subscriber => {
        ...widget.subscriber,
        if (details?['subscription'] is Map)
          ...Map<String, dynamic>.from(details!['subscription'] as Map),
      };
  double get balance => double.tryParse('${subscriber['balance']}') ?? 0;
  Map get permissions => details?['permissions'] as Map? ?? const {};
  List<String> get paymentMethods => permissions['payment_methods'] is List
      ? List<String>.from(permissions['payment_methods'] as List)
      : widget.paymentMethods;
  bool get canRecordPayment =>
      !loading && error == null && permissions['record_payment'] == true;
  bool get canRecordSplitPayment =>
      !loading && error == null && permissions['record_split_payment'] == true;
  Map<String, dynamic>? get lastReading => details?['last_reading'] is Map
      ? Map<String, dynamic>.from(details!['last_reading'] as Map)
      : null;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    permissionTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted &&
          (WidgetsBinding.instance.lifecycleState == null ||
              WidgetsBinding.instance.lifecycleState ==
                  AppLifecycleState.resumed) &&
          ModalRoute.of(context)?.isCurrent == true &&
          !loading) load();
    });
    load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        ModalRoute.of(context)?.isCurrent == true &&
        !loading) load();
  }

  @override
  void dispose() {
    permissionTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  bool get canAdjust =>
      !loading &&
      error == null &&
      (permissions['add_charge'] == true ||
          permissions['add_discount'] == true ||
          permissions['add_clearing'] == true ||
          canRecordSplitPayment);

  Future<void> openAdjustments() async {
    if (!canAdjust) return;
    final selected = await showAppSheet<Map<String, dynamic>>(context,
        title: 'إجراءات الحساب',
        children: [
          Builder(
              builder: (sheetContext) => Column(children: [
                    if (canRecordSplitPayment)
                      ListTile(
                          title: const Text('تسجيل دفعة مقسّمة'),
                          onTap: () =>
                              Navigator.pop(sheetContext, {'action': 'split'})),
                    if (permissions['add_charge'] == true)
                      for (final type
                          in (details?['charge_types'] as List? ?? const []))
                        ListTile(
                            title: Text('تحميل ${type['label']}'),
                            onTap: () => Navigator.pop(sheetContext, {
                                  'action': 'charge',
                                  'title': 'تحميل ${type['label']}',
                                  'type': type
                                })),
                    if (permissions['add_discount'] == true)
                      ListTile(
                          title: const Text('إضافة خصم'),
                          onTap: () => Navigator.pop(sheetContext,
                              {'action': 'discount', 'title': 'إضافة خصم'})),
                    if (permissions['add_clearing'] == true)
                      ListTile(
                          title: const Text('إضافة مقاصة'),
                          onTap: () => Navigator.pop(sheetContext,
                              {'action': 'clearing', 'title': 'إضافة مقاصة'})),
                  ])),
        ]);
    if (!mounted || selected == null) return;
    if (selected['action'] == 'split') {
      await recordSplitPayment();
      return;
    }
    final recorded = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => AdjustmentPage(
            api: widget.api,
            subscriber: subscriber,
            action: selected['action'] as String,
            title: selected['title'] as String,
            chargeType: selected['type'] == null
                ? null
                : Map<String, dynamic>.from(selected['type'] as Map))));
    if (!mounted) return;
    if (recorded == true) {
      recordedAny = true;
      showAppToast(context, 'سُجّلت الحركة بنجاح.');
    }
    await load();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result =
          await widget.api.collectionSubscriber(widget.subscriber['id'] as int);
      if (mounted) setState(() => details = result);
    } on ApiException catch (exception) {
      if (mounted) setState(() => error = exception.message);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> recordPayment() async {
    if (!canRecordPayment) return;
    final submitted = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => PaymentPage(
            api: widget.api,
            subscriber: subscriber,
            paymentMethods: paymentMethods,
            exchangeRates: widget.exchangeRates,
            transferBanks: widget.transferBanks)));
    if (!mounted) return;
    if (submitted == true) {
      recordedAny = true;
      showAppToast(context, 'سُجلت الدفعة مباشرة في السجل المالي.');
    }
    await load();
  }

  void close() => Navigator.pop(context, recordedAny);

  Future<void> recordSplitPayment() async {
    if (!canRecordSplitPayment) return;
    final recorded = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => SplitPaymentPage(
            api: widget.api,
            subscriber: subscriber,
            transferBanks: widget.transferBanks,
            senderBanks: widget.senderBanks)));
    if (!mounted) return;
    if (recorded == true) {
      recordedAny = true;
      showAppToast(context, 'سُجلت الحوالة المقسّمة لجميع الاشتراكات.');
    }
    await load();
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) close();
        },
        child: Scaffold(
          body: SafeArea(
            child: Column(children: [
              AppHeader(
                  title: 'ملخص المشترك',
                  subtitle: 'حساب ${subscriber['account_number']}',
                  onBack: close),
              Expanded(
                child: RefreshIndicator(
                  color: AppIdentity.brand,
                  onRefresh: load,
                  child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                      children: [
                        if (text(subscriber['subscription_name'])
                            case final name?)
                          Padding(
                              padding: const EdgeInsets.only(bottom: 12),
                              child:
                                  Text(name, style: AppIdentity.heading(22))),
                        balanceCard(),
                        if (loading)
                          Padding(
                              padding: const EdgeInsets.only(top: 12),
                              child: ClipRRect(
                                  borderRadius: BorderRadius.circular(4),
                                  child: LinearProgressIndicator(
                                      minHeight: 3,
                                      color: AppIdentity.brand,
                                      backgroundColor: AppIdentity.sunken))),
                        if (error != null) ...[
                          const SizedBox(height: 12),
                          AppNotice(error!, error: true),
                          TextButton(
                              onPressed: loading ? null : load,
                              child: const Text('إعادة المحاولة')),
                        ],
                        if (details != null) ...[
                          const AppSection('خدمات المشترك'),
                          subscriberActions(),
                          const AppSection('البيانات الشخصية'),
                          KeyedSubtree(
                              key:
                                  const ValueKey('subscriber-personal-details'),
                              child: factRows(personalFacts())),
                        ],
                        const AppSection('معلومات الاشتراك'),
                        KeyedSubtree(
                            key: const ValueKey(
                                'subscriber-subscription-details'),
                            child: factRows(subscriptionFacts())),
                      ]),
                ),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                    color: AppIdentity.background,
                    border:
                        Border(top: BorderSide(color: AppIdentity.lineSoft))),
                child: Row(children: [
                  Expanded(
                      child: AppAction(
                          key: const ValueKey('subscriber-record-payment'),
                          label: 'تسجيل دفعة',
                          icon: 'cash',
                          onPressed: canRecordPayment ? recordPayment : null)),
                  if (canAdjust) ...[
                    const SizedBox(width: 8),
                    IconButton.filledTonal(
                        key: const ValueKey('subscriber-account-actions'),
                        tooltip: 'إجراءات الحساب',
                        constraints:
                            const BoxConstraints(minWidth: 54, minHeight: 54),
                        onPressed: openAdjustments,
                        icon: const Icon(Icons.keyboard_arrow_down)),
                  ],
                ]),
              ),
            ]),
          ),
        ),
      );

  Widget balanceCard() {
    return AppHero(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text('الرصيد الحالي',
                    style:
                        AppIdentity.body(12.5, color: AppIdentity.heroFaint)),
                Text(balanceText(balance),
                    key: const ValueKey('subscriber-balance'),
                    style: AppIdentity.number(25,
                        weight: FontWeight.w800,
                        color: balanceColor(balance, onHero: true))),
              ])),
        ]));
  }

  String? text(Object? value) =>
      value == null || '$value'.trim().isEmpty ? null : '$value'.trim();

  Widget detailIcon(String icon, {bool muted = false}) {
    final (color, tint) = muted
        ? (AppIdentity.faint, AppIdentity.sunken)
        : switch (icon) {
            'phone' || 'cash' || 'check' => (
                AppIdentity.good,
                AppIdentity.goodTint
              ),
            'meter' || 'bolt' || 'breaker' || 'clock' => (
                AppIdentity.warning,
                AppIdentity.warningTint
              ),
            'home' || 'pin' || 'tag' => (
                AppIdentity.brand,
                AppIdentity.brandTint
              ),
            'err' => (AppIdentity.bad, AppIdentity.badTint),
            _ => (AppIdentity.info, AppIdentity.infoTint),
          };
    return AppIconTile(icon, color, tint, size: 38, iconSize: 18);
  }

  Widget factRows(List<(String, String, String)> facts) => AppRows(children: [
        for (final (icon, label, value) in facts)
          AppRow(
              key: icon == 'phone' ? ValueKey('subscriber-phone-$value') : null,
              onLongPress: icon == 'phone'
                  ? () async {
                      try {
                        await Clipboard.setData(ClipboardData(text: value));
                        if (mounted)
                          showAppToast(context, 'تم نسخ رقم الهاتف.');
                      } on PlatformException {
                        if (mounted)
                          showAppToast(
                              context, 'تعذر نسخ رقم الهاتف. أعد المحاولة.',
                              icon: 'err');
                      }
                    }
                  : null,
              leading: detailIcon(icon),
              title: Text(label,
                  style: AppIdentity.body(12.5, color: AppIdentity.faint)),
              subtitle: Text(value,
                  textDirection: icon == 'phone' ? TextDirection.ltr : null,
                  style: AppIdentity.body(15, weight: FontWeight.w700))),
      ]);

  List<(String, String, String)> personalFacts() => [
        if (text(subscriber['subscriber_name']) ?? text(subscriber['full_name'])
            case final name?)
          ('user', 'اسم المشترك', name),
        if (text(subscriber['national_id']) case final nationalId?)
          ('tag', 'رقم الهوية', nationalId),
        if (text(subscriber['personal_phone']) ?? text(subscriber['phone'])
            case final phone?)
          ('phone', 'رقم الهاتف', phone),
        if (text(subscriber['personal_phone']) != null &&
            text(subscriber['phone']) != null &&
            text(subscriber['personal_phone']) != text(subscriber['phone']))
          ('phone', 'هاتف التواصل', text(subscriber['phone'])!),
        if (text(subscriber['address']) case final address?)
          ('home', 'العنوان', address),
      ];

  List<(String, String, String)> subscriptionFacts() {
    final boxName = text(subscriber['meter_box_name']);
    final boxNumber = text(subscriber['meter_box_number']);
    final box = [boxName, if (boxNumber != null) '($boxNumber)']
        .whereType<String>()
        .join(' ');
    final previousReading =
        lastReading?['previous_reading'] ?? subscriber['initial_reading'];
    final currentReading = lastReading?['current_reading'];
    final previousValue = double.tryParse('$previousReading');
    final currentValue = double.tryParse('$currentReading');
    final difference = previousValue != null && currentValue != null
        ? currentValue - previousValue
        : null;
    return [
      if (text(subscriber['status_label']) case final status?)
        (
          switch (subscriber['status']) {
            'active' => 'check',
            'disconnected' => 'err',
            _ => 'clock',
          },
          'حالة الاشتراك',
          status
        ),
      if ((switch (
              '${subscriber['accounting_type'] ?? subscriber['accounting_type_label'] ?? ''}'
                  .toLowerCase()) {
        'weekly' => 'أسبوعي',
        'monthly' => 'شهري',
        _ => text(subscriber['accounting_type_label']),
      })
          case final type?)
        ('sync', 'نوع المحاسبة', type),
      if (text(subscriber['subscription_date']) case final date?)
        ('calendar', 'تاريخ الاشتراك', date),
      if (text(subscriber['tariff_name']) case final tariff?)
        ('tag', 'نوع الاشتراك', tariff),
      if (text(subscriber['kilowatt_price']) case final price?)
        ('bolt', 'سعر الكيلوواط', '${AppIdentity.grouped(price)} ₪ / ك.و.س'),
      if (text(subscriber['minimum_payment']) case final minimum?)
        ('cash', 'الحد الأدنى الأسبوعي', '${AppIdentity.grouped(minimum)} ₪'),
      if (text(subscriber['circuit_breaker_ampere']) case final ampere?)
        ('breaker', 'القاطع', '${AppIdentity.reading(ampere)} أمبير'),
      if (text(subscriber['sub_area_name']) case final area?)
        ('pin', 'المنطقة 2', area),
      if (box.isNotEmpty) ('meter', 'الطبلون', box),
      if (text(subscriber['meter_box_location']) case final location?)
        ('pin', 'موقع الطبلون', location),
      if (details != null)
        (
          'meter',
          'القراءة السابقة',
          previousReading == null
              ? 'غير متوفرة'
              : AppIdentity.reading(previousReading)
        ),
      if (details != null)
        (
          'meter',
          'القراءة الحالية',
          currentReading == null
              ? 'غير متوفرة'
              : AppIdentity.reading(currentReading)
        ),
      if (details != null)
        (
          'bolt',
          'الفرق (كيلوواط)',
          difference == null ? 'غير متوفر' : AppIdentity.reading(difference)
        ),
    ];
  }

  Widget subscriberActions() {
    final canViewHistory = permissions['view_history'] == true;
    final messaging = details?['messaging'] as Map?;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      AppRows(children: [
        AppRow(
            key: const ValueKey('subscriber-split-payment'),
            leading: detailIcon('cash', muted: !canRecordSplitPayment),
            title: const AppRowTitle('تسجيل دفعة مقسّمة'),
            subtitle: AppRowNote(canRecordSplitPayment
                ? 'حوالة واحدة توزّع على عدة اشتراكات'
                : 'تحتاج صلاحية تسجيل دفعة بتحويل بنكي'),
            trailing: AppIcon(canRecordSplitPayment ? 'chev' : 'lock',
                size: 18, color: AppIdentity.faint),
            onTap: canRecordSplitPayment ? recordSplitPayment : null),
        AppRow(
            key: const ValueKey('subscriber-financial-history'),
            leading: detailIcon('hist'),
            title: const AppRowTitle('السجل المالي'),
            subtitle: AppRowNote(canViewHistory
                ? 'جميع الدفعات والقراءات والحركات'
                : 'تحتاج صلاحية عرض بيانات المشترك من الإدارة'),
            trailing: AppIcon(canViewHistory ? 'chev' : 'lock',
                size: 18, color: AppIdentity.faint),
            onTap: canViewHistory
                ? () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => SubscriberHistoryPage(
                        api: widget.api, subscriber: subscriber)))
                : null),
      ]),
      const SizedBox(height: 12),
      SubscriberMessageActions(
          api: widget.api,
          subscriber: subscriber,
          messaging: messaging ?? const {})
    ]);
  }
}
