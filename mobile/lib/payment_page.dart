import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api_client.dart';
import 'bank_received_date_field.dart';
import 'app_balance.dart';
import 'app_identity.dart';
import 'field_store.dart';
import 'receipt_reader_page.dart';
import 'receipt_scan.dart';

/// The banks and e-wallets a transfer can go to, used until the server
/// sends its own list with the signed-in user.
const defaultTransferBanks = [
  'بنك فلسطين',
  'محفظة بالباي',
  'جوال باي',
  'البنك الإسلامي الفلسطيني',
  'البنك الوطني الإسلامي',
];

/// Each bank or e-wallet's logo, as on the website; one not listed here
/// gets a plain tile.
const _bankLogos = {
  'بنك فلسطين': 'assets/images/banks/bank-of-palestine.webp',
  'محفظة بالباي': 'assets/images/banks/palpay.webp',
  'جوال باي': 'assets/images/banks/jawwal-pay.webp',
  'البنك الإسلامي الفلسطيني': 'assets/images/banks/palestine-islamic-bank.webp',
  'البنك الوطني الإسلامي': 'assets/images/banks/national-islamic-bank.webp',
  'بنك القدس': 'assets/images/banks/quds-bank.webp',
};

String? bankLogoAsset(String name) => _bankLogos[name];

/// Currency code, symbol and name, in the website's order.
const _currencies = [
  ('ILS', '₪', 'شيكل'),
  ('USD', '\$', 'دولار'),
  ('JOD', 'JD', 'دينار'),
];

/// Record a payment the way the website's payment form does: the amount in
/// shekels, dollars or dinars (at an exchange rate), paid in cash or by a
/// transfer to one of the company's banks or e-wallets, with who sent it
/// and its reference. A transfer receipt can be read on the phone to fill
/// in its numbers.
class PaymentPage extends StatefulWidget {
  const PaymentPage(
      {required this.api,
      required this.subscriber,
      this.transferBanks = defaultTransferBanks,
      this.paymentMethods = const ['cash', 'bank_transfer'],
      this.receiptScanner = const ReceiptScanner(),
      this.receiptReader = const DeviceReceiptTextReader(),
      super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;
  final List<String> transferBanks;
  final List<String> paymentMethods;
  final ReceiptScanner receiptScanner;
  final ReceiptTextReader receiptReader;
  @override
  State<PaymentPage> createState() => _PaymentPageState();
}

class _PaymentPageState extends State<PaymentPage> {
  final amount = TextEditingController();
  final rate = TextEditingController();
  final sender = TextEditingController();
  final reference = TextEditingController();
  final notes = TextEditingController();
  final voucher = TextEditingController();
  String currency = 'ILS';
  // The website's payment form starts on a transfer too.
  String method = 'bank_transfer';
  String? bank;
  String? senderBank;
  DateTime? bankReceivedOn;
  bool senderIsSubscriber = true;
  bool busy = false;
  bool scanning = false;
  bool collectorConfirmed = false;
  bool showKeypad = false;
  bool recordedAny = false;
  late double balance = double.tryParse('${widget.subscriber['balance']}') ?? 0;
  String? error;
  Map<String, dynamic>? submission;
  Map<String, dynamic>? receipt;

  String get subscriberName => '${widget.subscriber['full_name']}';
  bool get editable => !busy && !scanning && submission == null;
  bool get throughBank => method == 'bank_transfer';
  bool get isShekel => currency == 'ILS';
  double get value => double.tryParse(amount.text) ?? 0;
  double? get rateValue => isShekel ? 1 : double.tryParse(rate.text);
  double? get inShekels => rateValue == null || rateValue! <= 0
      ? null
      : (value * rateValue! * 100).roundToDouble() / 100;
  double get owed => max(balance, 0);
  (String, String, String) get currencyLook =>
      _currencies.firstWhere((look) => look.$1 == currency);
  String get methodText => throughBank
      ? bank == null
          ? 'تحويل بنكي'
          : 'تحويل ${senderBank == null ? '' : 'من $senderBank '}إلى $bank'
      : 'نقد';

  @override
  void initState() {
    super.initState();
    method = widget.paymentMethods.contains('bank_transfer')
        ? 'bank_transfer'
        : widget.paymentMethods.firstOrNull ?? '';
    sender.text = subscriberName;
  }

  @override
  void dispose() {
    for (final controller in [
      amount,
      rate,
      sender,
      reference,
      notes,
      voucher
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  void enterAmount(String key) {
    if (!editable) return;
    final current = amount.text;
    var next = current;
    if (key == 'clear') {
      next = '';
    } else if (key == 'delete') {
      next = current.isEmpty ? '' : current.substring(0, current.length - 1);
    } else if (key == '.') {
      if (!current.contains('.')) next = '${current.isEmpty ? '0' : current}.';
    } else if (current.length < 10 &&
        !(current.contains('.') && current.split('.').last.length >= 2)) {
      next = current == '0' ? key : '$current$key';
    }
    setState(() => amount.text = next);
  }

  /// The balance the server reports after the payment; the app's own sum
  /// when an older server sends none.
  double balanceAfter(double recordedInShekels) =>
      double.tryParse('${receipt?['balance_after']}') ??
      balance - recordedInShekels;

  /// Back to an empty form for another payment from the same subscriber,
  /// starting from the balance the last one left, as the website's
  /// «دفعة جديدة» does.
  void startAnother(double newBalance) => setState(() {
        for (final controller in [amount, rate, reference, notes, voucher]) {
          controller.clear();
        }
        balance = newBalance;
        currency = 'ILS';
        method = widget.paymentMethods.contains('bank_transfer')
            ? 'bank_transfer'
            : widget.paymentMethods.firstOrNull ?? '';
        bank = null;
        senderBank = null;
        bankReceivedOn = null;
        senderIsSubscriber = true;
        sender.text = subscriberName;
        collectorConfirmed = false;
        showKeypad = false;
        submission = null;
        receipt = null;
        error = null;
      });

  void toggleSender(bool isSubscriber) => setState(() {
        senderIsSubscriber = isSubscriber;
        sender.text = isSubscriber ? subscriberName : '';
      });

  Future<void> readReceipt(ReceiptImageSource source) async {
    if (!editable) return;
    FocusScope.of(context).unfocus();
    setState(() {
      scanning = true;
      showKeypad = false;
      error = null;
    });
    ReceiptImage? image;
    try {
      image = await widget.receiptScanner.pick(source);
      if (image == null || !mounted) return;
      final picked = await Navigator.push<Map<String, String>>(
          context,
          MaterialPageRoute(
              builder: (_) => ReceiptReaderPage(
                  image: image!,
                  scanner: widget.receiptScanner,
                  reader: widget.receiptReader)));
      if (picked == null || !mounted) return;
      setState(() {
        if (picked['amount'] != null) amount.text = picked['amount']!;
        if (picked['reference_number'] != null) {
          reference.text = picked['reference_number']!;
        }
        if (picked['sender_name'] != null) {
          senderIsSubscriber = false;
          sender.text = picked['sender_name']!;
        }
      });
    } on PlatformException catch (exception) {
      if (mounted)
        setState(() => error = exception.message ??
            'تعذر فتح صورة الإيصال. جرّب صورة أخرى أو أدخل البيانات يدويًا.');
    } on MissingPluginException {
      if (mounted)
        setState(() => error = 'قراءة الإيصالات متاحة في تطبيق Android فقط.');
    } finally {
      if (image != null) {
        try {
          await widget.receiptScanner.discard(image);
        } on PlatformException {
          // Temporary images are also cleared by the Android scanner on restart.
        } on MissingPluginException {
          // Tests and unsupported platforms have no native image cache.
        }
      }
      if (mounted) setState(() => scanning = false);
    }
  }

  String? get missingDetail {
    if (!widget.paymentMethods.contains(method))
      return 'لا تملك صلاحية تسجيل هذه الدفعة.';
    if (!(value > 0) || !value.isFinite) return 'أدخل مبلغًا أكبر من صفر.';
    if (inShekels == null) return 'أدخل سعر الصرف لتحويل المبلغ إلى شيكل.';
    if (throughBank) {
      if (bank == null) return 'اختر البنك أو المحفظة التي حُوّل إليها المبلغ.';
      if (sender.text.trim().isEmpty) {
        return 'أدخل اسم صاحب الحساب الذي حُوّل منه المبلغ.';
      }
      if (reference.text.trim().isEmpty) {
        return 'أدخل الرقم المرجعي من إشعار الحوالة.';
      }
    }
    return null;
  }

  Future<void> submit() async {
    if (busy || scanning || !collectorConfirmed) return;
    if (submission == null && missingDetail != null) {
      setState(() => error = missingDetail);
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      busy = true;
      error = null;
    });
    try {
      submission ??= {
        'mobile_operation_id': newOperationId(),
        'collector_confirmed': true,
        'subscription_id': widget.subscriber['id'],
        'amount': amount.text.trim().replaceFirst(RegExp(r'\.$'), ''),
        'currency': currency,
        if (!isShekel) 'exchange_rate': rate.text.trim(),
        'payment_method': method,
        if (throughBank) ...{
          'bank_name': bank,
          'bank_received_on': bankReceivedOn == null
              ? null
              : bankReceivedDateValue(bankReceivedOn!),
          if (senderBank != null) 'sender_bank_name': senderBank,
          'sender_name': sender.text.trim(),
          'reference_number': reference.text.trim(),
        },
        if (!throughBank && voucher.text.trim().isNotEmpty)
          'manual_voucher_number': voucher.text.trim(),
        'notes': notes.text.trim(),
      };
      final result = await widget.api.sendCollection(submission!);
      recordedAny = true;
      if (mounted) setState(() => receipt = result);
    } on ApiException catch (exception) {
      if (exception.statusCode >= 400 && exception.statusCode < 500)
        submission = null;
      if (mounted)
        setState(() => error = exception.isNetwork ||
                exception.statusCode >= 500
            ? 'تعذر تأكيد تسجيل الدفعة. أعد المحاولة من هذه الشاشة بنفس البيانات، أو تحقق من دفعات اليوم قبل إنشاء دفعة أخرى.'
            : exception.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final textScale = MediaQuery.textScalerOf(context).scale(14) / 14;
    final availableHeight = MediaQuery.sizeOf(context).height -
        MediaQuery.paddingOf(context).vertical;
    final inlineKeypad = availableHeight < 500 * textScale;
    final showNumberPad =
        showKeypad && MediaQuery.viewInsetsOf(context).bottom == 0;
    final keypad = AppKeypad(
        adaptive: true,
        keyPrefix: 'payment',
        enabled: editable,
        onClose: () => setState(() => showKeypad = false),
        onKey: enterAmount);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) goBack();
      },
      child: Scaffold(
          body: SafeArea(
              child: receipt != null
                  ? Column(children: [
                      Expanded(child: success()),
                    ])
                  : Column(children: [
                      AppHeader(
                          title: subscriberName,
                          subtitle:
                              'حساب ${widget.subscriber['account_number']} · تسجيل دفعة',
                          onBack: busy || scanning ? null : goBack),
                      Expanded(
                          child: ListView(
                              keyboardDismissBehavior:
                                  ScrollViewKeyboardDismissBehavior.onDrag,
                              padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                              children: [
                            balanceStrip(),
                            const AppLabel('المبلغ المستلم',
                                hint: 'بالعملة التي استُلم بها'),
                            amountPanel(),
                            if (inlineKeypad && showNumberPad) keypad,
                            const AppLabel('طريقة الدفع'),
                            Row(children: [
                              if (widget.paymentMethods
                                  .contains('bank_transfer'))
                                methodTile('bank_transfer',
                                    'تحويل بنكي أو محفظة', 'bank'),
                              if (widget.paymentMethods
                                      .contains('bank_transfer') &&
                                  widget.paymentMethods.contains('cash'))
                                const SizedBox(width: 8),
                              if (widget.paymentMethods.contains('cash'))
                                methodTile('cash', 'نقد', 'cash'),
                            ]),
                            if (throughBank)
                              Padding(
                                  padding: const EdgeInsets.only(top: 12),
                                  child: transferDetails())
                            else ...[
                              const AppLabel('رقم السند اليدوي',
                                  hint: 'رقم الوصل الورقي'),
                              detailField('مثال: 00412', voucher,
                                  key: 'payment-voucher', ltr: true),
                            ],
                            const AppLabel('ملاحظة',
                                hint: 'اختياري · تظهر في كشف الحساب'),
                            detailField('أي تفصيل يُحفظ مع الدفعة', notes,
                                key: 'payment-notes'),
                            summary(),
                            confirmation(),
                            if (error != null) ...[
                              const SizedBox(height: 12),
                              AppNotice(error!, error: true)
                            ],
                          ])),
                      Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 16, vertical: 10),
                          decoration: BoxDecoration(
                              color: AppIdentity.background,
                              border: Border(
                                  top:
                                      BorderSide(color: AppIdentity.lineSoft))),
                          child: AppAction(
                              key: const ValueKey('payment-submit'),
                              label: busy
                                  ? 'جارٍ التسجيل...'
                                  : value > 0
                                      ? 'تسجيل ${AppIdentity.money(value)} ${currencyLook.$3}'
                                      : 'تسجيل الدفعة',
                              icon: 'check',
                              busy: busy,
                              onPressed: busy ||
                                      scanning ||
                                      !collectorConfirmed ||
                                      value <= 0
                                  ? null
                                  : submit)),
                      if (!inlineKeypad && showNumberPad) keypad,
                    ]))),
    );
  }

  void goBack() {
    if (busy || scanning) return;
    if ((receipt == null && showKeypad) ||
        MediaQuery.viewInsetsOf(context).bottom > 0) {
      FocusScope.of(context).unfocus();
      setState(() => showKeypad = false);
      return;
    }
    Navigator.pop(context, recordedAny);
  }

  Widget balanceStrip() {
    final status = widget.subscriber['status'];
    final unusual = status != null && status != 'active';
    return AppHero(
        padding: const EdgeInsets.all(16),
        radius: 26,
        child: Row(children: [
          Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                  color: const Color(0x14FFFFFF),
                  borderRadius: BorderRadius.circular(15)),
              child: const AppIcon('user', color: Colors.white)),
          const SizedBox(width: 12),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text('الرصيد الحالي',
                    style:
                        AppIdentity.body(12.5, color: AppIdentity.heroFaint)),
                Text(balanceText(balance),
                    style: AppIdentity.number(23,
                        weight: FontWeight.w800,
                        color: balanceColor(balance, onHero: true))),
              ])),
          if (unusual)
            Container(
                height: 26,
                padding: const EdgeInsets.symmetric(horizontal: 10),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                    color: const Color(0x18FFFFFF),
                    borderRadius: BorderRadius.circular(99)),
                child: Text('${widget.subscriber['status_label'] ?? status}',
                    style: AppIdentity.body(12,
                        weight: FontWeight.w600, color: AppIdentity.heroWarn))),
        ]));
  }

  Widget amountPanel() => AppPanel(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        InkWell(
          key: const ValueKey('payment-amount'),
          onTap: () {
            FocusScope.of(context).unfocus();
            setState(() => showKeypad = true);
          },
          child: Row(children: [
            Expanded(
                child: Container(
              constraints: const BoxConstraints(minHeight: 44),
              alignment: Alignment.centerRight,
              child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerRight,
                  child: Row(
                      mainAxisSize: MainAxisSize.min,
                      textDirection: TextDirection.ltr,
                      children: [
                        Text(amount.text.isEmpty ? '0.00' : amount.text,
                            textDirection: TextDirection.ltr,
                            style: AppIdentity.number(36,
                                weight: FontWeight.w800,
                                color: amount.text.isEmpty
                                    ? AppIdentity.faint
                                    : AppIdentity.ink)),
                        if (showKeypad && editable) const AppCaret(height: 34),
                      ])),
            )),
            const SizedBox(width: 10),
            Text(currencyLook.$2,
                style: AppIdentity.number(20, color: AppIdentity.faint)),
          ]),
        ),
        const SizedBox(height: 10),
        Container(
          key: const ValueKey('payment-currency'),
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
              color: AppIdentity.sunken,
              borderRadius: BorderRadius.circular(14)),
          child: Row(children: [
            for (final (index, (code, symbol, name))
                in _currencies.indexed) ...[
              if (index > 0) const SizedBox(width: 4),
              Expanded(
                child: InkWell(
                  borderRadius: BorderRadius.circular(11),
                  onTap:
                      editable ? () => setState(() => currency = code) : null,
                  child: Container(
                    height: 38,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                        color: currency == code
                            ? AppIdentity.surface
                            : Colors.transparent,
                        borderRadius: BorderRadius.circular(11),
                        boxShadow:
                            currency == code ? AppIdentity.shadow : null),
                    child: Text('$symbol $name',
                        style: AppIdentity.body(13.5,
                            weight: FontWeight.w700,
                            color: currency == code
                                ? AppIdentity.ink
                                : AppIdentity.muted)),
                  ),
                ),
              ),
            ],
          ]),
        ),
        if (!isShekel)
          Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Row(children: [
                Text('سعر الصرف',
                    style: AppIdentity.body(13.5, color: AppIdentity.muted)),
                const SizedBox(width: 8),
                SizedBox(
                    width: 90,
                    height: 40,
                    child: TextField(
                        key: const ValueKey('payment-rate'),
                        controller: rate,
                        enabled: editable,
                        onTap: () => setState(() => showKeypad = false),
                        onChanged: (_) => setState(() {}),
                        onTapOutside: (_) => FocusScope.of(context).unfocus(),
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => FocusScope.of(context).unfocus(),
                        keyboardType: const TextInputType.numberWithOptions(
                            decimal: true),
                        inputFormatters: [
                          FilteringTextInputFormatter.allow(
                              RegExp(r'^\d{0,4}(\.\d{0,4})?'))
                        ],
                        textDirection: TextDirection.ltr,
                        textAlign: TextAlign.center,
                        style: AppIdentity.number(15),
                        decoration: InputDecoration(
                            isDense: true,
                            contentPadding:
                                const EdgeInsets.symmetric(horizontal: 6),
                            hintText: currency == 'USD' ? '3.70' : '5.20',
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12),
                                borderSide: BorderSide(
                                    color: AppIdentity.line, width: 1.5)),
                            enabledBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12),
                                borderSide: BorderSide(
                                    color: AppIdentity.line, width: 1.5))))),
                const SizedBox(width: 8),
                Expanded(
                    child: Text(
                        inShekels == null
                            ? 'كم شيكل يساوي 1 ${currencyLook.$3}'
                            : '= ${AppIdentity.grouped(inShekels)} ₪',
                        style: AppIdentity.body(13.5,
                            weight: FontWeight.w700, color: AppIdentity.ink))),
              ])),
        Container(
          margin: const EdgeInsets.only(top: 12),
          padding: const EdgeInsets.only(top: 12),
          decoration: BoxDecoration(
              border: Border(top: BorderSide(color: AppIdentity.lineSoft))),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final quick in ['50', '100', '200'])
              _quickChip(quick, () => setState(() => amount.text = quick)),
            if (owed > 0)
              _quickChip(
                  'تسديد كامل الدين',
                  editable && (rateValue ?? 0) > 0
                      ? () => setState(() =>
                          amount.text = (owed / rateValue!).toStringAsFixed(2))
                      : null,
                  full: true,
                  key: const ValueKey('payment-full-debt')),
          ]),
        ),
      ]));

  Widget _quickChip(String label, VoidCallback? onTap,
      {bool full = false, Key? key}) {
    final enabled = onTap != null && editable;
    return Opacity(
      opacity: enabled ? 1 : .5,
      child: Material(
        key: key,
        color: full ? AppIdentity.brandSoft : AppIdentity.raised,
        borderRadius: BorderRadius.circular(11),
        child: InkWell(
          borderRadius: BorderRadius.circular(11),
          onTap: enabled ? onTap : null,
          child: Container(
            height: 34,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
                border: Border.all(
                    color: full
                        ? Color.alphaBlend(
                            AppIdentity.brand.withValues(alpha: .35),
                            AppIdentity.line)
                        : AppIdentity.line),
                borderRadius: BorderRadius.circular(11)),
            child: Center(
                widthFactor: 1,
                child: Text(label,
                    style: full
                        ? AppIdentity.body(13,
                            weight: FontWeight.w700, color: AppIdentity.brand)
                        : AppIdentity.number(13, color: AppIdentity.ink2))),
          ),
        ),
      ),
    );
  }

  Widget transferDetails() => AppPanel(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('قراءة إيصال التحويل',
            style: AppIdentity.body(14, weight: FontWeight.w700)),
        Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
                'صوّر الإيصال أو اختر صورته، ثم اضغط على الأرقام لنقلها إلى الحقول. تُقرأ الصورة على الجوال ولا تُرفع.',
                style: AppIdentity.body(12.5, color: AppIdentity.muted))),
        const SizedBox(height: 10),
        if (scanning)
          Row(children: [
            SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(
                    strokeWidth: 2, color: AppIdentity.brand)),
            const SizedBox(width: 10),
            Text('جارٍ فتح الإيصال...', style: AppIdentity.body(13.5))
          ])
        else
          Row(children: [
            Expanded(
                child: scanButton('receipt-camera', 'scan', 'تصوير الإيصال',
                    () => readReceipt(ReceiptImageSource.camera))),
            const SizedBox(width: 8),
            Expanded(
                child: scanButton('receipt-gallery', 'img', 'اختيار صورة',
                    () => readReceipt(ReceiptImageSource.gallery))),
          ]),
        const AppLabel('البنك المستلم'),
        GridView(
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 8,
                crossAxisSpacing: 8,
                mainAxisExtent: 98),
            shrinkWrap: true,
            padding: EdgeInsets.zero,
            physics: const NeverScrollableScrollPhysics(),
            children: [
              for (final name in widget.transferBanks) bankTile(name)
            ]),
        const AppLabel('البنك المرسل', hint: 'اختياري'),
        senderBankField(),
        BankReceivedDateField(
            value: bankReceivedOn,
            enabled: editable,
            onChanged: (date) => setState(() => bankReceivedOn = date)),
        AppLabel('اسم المحوِّل',
            trailing: AppToggle(
                key: const ValueKey('payment-sender-is-subscriber'),
                label: 'المشترك نفسه',
                value: senderIsSubscriber,
                onChanged: editable ? toggleSender : null)),
        detailField('اسم صاحب الحساب الذي حُوّل منه المبلغ', sender,
            key: 'payment-sender', readOnly: senderIsSubscriber),
        const AppLabel('الرقم المرجعي', hint: 'من إشعار الحوالة'),
        detailField('مثال: TRX-48213', reference,
            key: 'payment-reference', ltr: true),
      ]));

  Widget scanButton(
          String key, String icon, String label, VoidCallback onTap) =>
      Opacity(
        opacity: editable ? 1 : .5,
        child: AppDashedBox(
          child: Material(
            color: AppIdentity.raised,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              key: ValueKey(key),
              borderRadius: BorderRadius.circular(14),
              onTap: editable ? onTap : null,
              child: SizedBox(
                height: 46,
                child:
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  AppIcon(icon, color: AppIdentity.ink2),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppIdentity.body(13.5,
                            weight: FontWeight.w700, color: AppIdentity.ink2)),
                  ),
                ]),
              ),
            ),
          ),
        ),
      );

  Widget bankLogo(String name, double size, double radius) {
    final logo = bankLogoAsset(name);
    return ClipRRect(
        borderRadius: BorderRadius.circular(radius),
        child: logo == null
            ? Container(
                width: size,
                height: size,
                alignment: Alignment.center,
                color: AppIdentity.sunken,
                child: AppIcon('bank', color: AppIdentity.muted))
            : Image.asset(logo, width: size, height: size, fit: BoxFit.cover));
  }

  Widget bankTile(String name) {
    final selected = bank == name;
    return InkWell(
        key: ValueKey('payment-bank-$name'),
        onTap: editable ? () => setState(() => bank = name) : null,
        borderRadius: BorderRadius.circular(16),
        child: Stack(children: [
          Positioned.fill(
              child: Container(
                  padding: const EdgeInsets.fromLTRB(4, 10, 4, 8),
                  decoration: BoxDecoration(
                      color: AppIdentity.surface,
                      border: Border.all(
                          color: selected
                              ? AppIdentity.selected
                              : AppIdentity.lineSoft,
                          width: selected ? 2.5 : 1.5),
                      borderRadius: BorderRadius.circular(16)),
                  child: Column(children: [
                    bankLogo(name, 40, 12),
                    const SizedBox(height: 6),
                    Expanded(
                      child: Text(name,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                          style: AppIdentity.body(11.5,
                                  weight: FontWeight.w700,
                                  color: AppIdentity.ink2)
                              .copyWith(height: 1.25)),
                    ),
                  ]))),
          if (selected)
            PositionedDirectional(
                top: 6,
                start: 6,
                child: Container(
                    width: 17,
                    height: 17,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                        color: AppIdentity.good, shape: BoxShape.circle),
                    child: const AppIcon('check',
                        size: 11, strokeWidth: 3.4, color: Colors.white))),
        ]));
  }

  Widget senderBankField() => Material(
        color: AppIdentity.surface,
        borderRadius: BorderRadius.circular(15),
        child: InkWell(
          key: const ValueKey('payment-sender-bank'),
          borderRadius: BorderRadius.circular(15),
          onTap: editable ? chooseSenderBank : null,
          child: Container(
            height: 50,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(
                border: Border.all(color: AppIdentity.line, width: 1.5),
                borderRadius: BorderRadius.circular(15)),
            child: Row(children: [
              if (senderBank != null) ...[
                bankLogo(senderBank!, 28, 8),
                const SizedBox(width: 10),
              ],
              Expanded(
                  child: Text(
                      senderBank ?? 'اختر البنك أو المحفظة المحوّل منها',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppIdentity.body(15,
                          color: senderBank == null
                              ? AppIdentity.faint
                              : AppIdentity.ink))),
              AppIcon('down', color: AppIdentity.faint),
            ]),
          ),
        ),
      );

  Future<void> chooseSenderBank() async {
    FocusScope.of(context).unfocus();
    setState(() => showKeypad = false);
    final choice = await showAppSheet<List<String?>>(context,
        title: 'البنك المرسل',
        message: 'اختياري · من أين حُوّل المبلغ',
        children: [
          Builder(
              builder: (sheet) => AppRows(children: [
                    AppRow(
                        key: const ValueKey('payment-sender-bank-none'),
                        onTap: () => Navigator.pop(sheet, <String?>[null]),
                        leading: AppIconTile(
                            'x', AppIdentity.muted, AppIdentity.sunken,
                            size: 40),
                        title: const AppRowTitle('غير محدد'),
                        trailing: senderBank == null
                            ? AppIcon('check', color: AppIdentity.good)
                            : null),
                    for (final name in widget.transferBanks)
                      AppRow(
                          key: ValueKey('payment-sender-bank-$name'),
                          onTap: () => Navigator.pop(sheet, <String?>[name]),
                          leading: bankLogo(name, 40, 12),
                          title: AppRowTitle(name),
                          trailing: senderBank == name
                              ? AppIcon('check', color: AppIdentity.good)
                              : null),
                  ])),
        ]);
    if (choice != null && mounted) setState(() => senderBank = choice.first);
  }

  Widget summary() {
    final after = inShekels == null ? null : balance - inShekels!;
    final coverage = owed > 0 && inShekels != null
        ? min(100, (inShekels! / owed * 100).round())
        : null;
    return Padding(
      padding: const EdgeInsets.only(top: 14),
      child: AppHero(
          radius: 22,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Column(children: [
            summaryRow('الرصيد الحالي', balanceText(balance),
                color: balanceColor(balance, onHero: true)),
            summaryRow(
                'هذه الدفعة', '${AppIdentity.grouped(inShekels ?? 0)} ₪'),
            Container(
                margin: const EdgeInsets.symmetric(vertical: 4),
                height: 1,
                color: const Color(0x22FFFFFF)),
            summaryRow(
                'الرصيد بعد الدفعة', after == null ? '—' : balanceText(after),
                color: after == null
                    ? const Color(0x80FFFFFF)
                    : balanceColor(after, onHero: true)),
            if (coverage != null) ...[
              summaryRow('تغطية المبلغ المستحق', '$coverage%'),
              Padding(
                  padding: const EdgeInsets.only(top: 2, bottom: 6),
                  child: ClipRRect(
                      borderRadius: BorderRadius.circular(6),
                      child: LinearProgressIndicator(
                          value: coverage / 100,
                          minHeight: 6,
                          color: AppIdentity.heroGood,
                          backgroundColor: const Color(0x1FFFFFFF)))),
            ],
            summaryRow('الطريقة', methodText),
          ])),
    );
  }

  Widget summaryRow(String title, String text, {Color color = Colors.white}) =>
      Padding(
          padding: const EdgeInsets.symmetric(vertical: 5),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
                child: Text(title,
                    style: AppIdentity.body(13.5,
                        color: const Color(0xBFFFFFFF)))),
            const SizedBox(width: 10),
            Expanded(
                child: Text(text,
                    textAlign: TextAlign.end,
                    style: AppIdentity.number(13.5, color: color)
                        .copyWith(fontFamily: 'PlexArabic'))),
          ]));

  Widget confirmation() => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Semantics(
          checked: collectorConfirmed,
          child: InkWell(
            key: const ValueKey('payment-confirm'),
            borderRadius: BorderRadius.circular(18),
            onTap: busy || scanning
                ? null
                : () =>
                    setState(() => collectorConfirmed = !collectorConfirmed),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                  color: AppIdentity.surface,
                  border: Border.all(
                      color: collectorConfirmed
                          ? AppIdentity.good
                          : AppIdentity.lineSoft,
                      width: 1.5),
                  borderRadius: BorderRadius.circular(18)),
              child:
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                    width: 22,
                    height: 22,
                    margin: const EdgeInsets.only(top: 1),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                        color: collectorConfirmed
                            ? AppIdentity.good
                            : Colors.transparent,
                        border: Border.all(
                            color: collectorConfirmed
                                ? AppIdentity.good
                                : AppIdentity.line,
                            width: 2),
                        borderRadius: BorderRadius.circular(7)),
                    child: collectorConfirmed
                        ? const AppIcon('check',
                            size: 15, strokeWidth: 3, color: Colors.white)
                        : null),
                const SizedBox(width: 10),
                Expanded(
                    child: Text(
                        'أؤكد استلام هذه الدفعة من $subscriberName وتسجيلها مباشرة في السجل المالي.',
                        style: AppIdentity.body(13.5))),
              ]),
            ),
          ),
        ),
      );

  Widget detailField(String hint, TextEditingController controller,
          {required String key, bool ltr = false, bool readOnly = false}) =>
      TextField(
        key: ValueKey(key),
        controller: controller,
        enabled: editable,
        readOnly: readOnly,
        textDirection: ltr ? TextDirection.ltr : null,
        textAlign: ltr ? TextAlign.right : TextAlign.start,
        style: ltr
            ? AppIdentity.number(15, weight: FontWeight.w500)
            : AppIdentity.body(15,
                color: readOnly ? AppIdentity.muted : AppIdentity.ink),
        onTap: () => setState(() => showKeypad = false),
        onTapOutside: (_) => FocusScope.of(context).unfocus(),
        textInputAction: TextInputAction.done,
        onSubmitted: (_) => FocusScope.of(context).unfocus(),
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(
            hintText: hint,
            hintStyle: ltr
                ? AppIdentity.number(15,
                    weight: FontWeight.w500, color: AppIdentity.faint)
                : null,
            fillColor: readOnly ? AppIdentity.raised : AppIdentity.surface),
      );

  Widget methodTile(String key, String title, String icon) => Expanded(
        child: InkWell(
            key: ValueKey('payment-method-$key'),
            onTap: editable ? () => setState(() => method = key) : null,
            borderRadius: BorderRadius.circular(18),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 12),
              decoration: BoxDecoration(
                  color: AppIdentity.surface,
                  border: Border.all(
                      color: method == key
                          ? AppIdentity.selected
                          : AppIdentity.line,
                      width: method == key ? 2.5 : 1.5),
                  borderRadius: BorderRadius.circular(18)),
              child: Column(children: [
                AppIcon(icon,
                    size: 24,
                    color:
                        method == key ? AppIdentity.brand : AppIdentity.muted),
                const SizedBox(height: 6),
                Text(title,
                    textAlign: TextAlign.center,
                    style: AppIdentity.body(13.5,
                        weight: FontWeight.w700, color: AppIdentity.ink2))
              ]),
            )),
      );

  Widget success() {
    final recordedInShekels =
        double.tryParse('${receipt?['amount_in_shekels']}') ?? inShekels ?? 0;
    final newBalance = balanceAfter(recordedInShekels);
    final recordedAmount = receipt?['amount'] ?? amount.text;
    return AppSuccess(
      title: 'تم تسجيل الدفعة',
      message:
          '${AppIdentity.money(recordedAmount)} ${currencyLook.$3} من $subscriberName · سُجّلت مباشرة في السجل المالي.',
      facts: [
        (
          'رقم السند',
          AppFact('${receipt?['voucher_number'] ?? '—'}', number: true)
        ),
        (
          'المبلغ',
          AppFact('${AppIdentity.money(recordedAmount)} ${currencyLook.$2}',
              number: true)
        ),
        if (!isShekel)
          (
            'بالشيكل',
            AppFact('${AppIdentity.grouped(recordedInShekels)} ₪', number: true)
          ),
        ('الطريقة', AppFact(methodText)),
        if (receipt?['bank_received_on'] != null)
          (
            'وصول البنك',
            AppFact(
                '${receipt!['bank_received_on']}'.split('-').reversed.join('/'),
                number: true)
          ),
        (
          'الرصيد بعد الدفعة',
          AppFact(balanceText(newBalance),
              number: true, color: balanceColor(newBalance))
        ),
      ],
      actions: [
        AppAction(
            label: 'العودة إلى صفحة المشترك',
            onPressed: () => Navigator.pop(context, true)),
        AppAction(
            key: const ValueKey('payment-another'),
            label: 'دفعة جديدة',
            icon: 'plus',
            primary: false,
            onPressed: () => startAnother(newBalance)),
      ],
    );
  }
}
