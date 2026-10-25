import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/payment_page.dart';
import 'package:power_collect/bank_received_date_field.dart';

class RecordingApi extends ApiClient {
  RecordingApi({this.failFirst = false, this.balanceAfter});
  final bool failFirst;
  final String? balanceAfter;
  final requests = <Map<String, dynamic>>[];
  @override
  Future<Map<String, dynamic>> sendCollection(
      Map<String, dynamic> collection) async {
    requests.add(Map<String, dynamic>.from(collection));
    if (failFirst && requests.length == 1) {
      throw const ApiException('Timeout', 0);
    }
    return {
      'id': 77,
      'status': 'recorded',
      'amount': collection['amount'],
      'currency': collection['currency'],
      'amount_in_shekels': collection['currency'] == 'USD' ? '74.00' : null,
      'voucher_number': 'P-77',
      'bank_received_on': collection['bank_received_on'],
      if (balanceAfter != null) 'balance_after': balanceAfter,
    };
  }
}

void main() {
  Future<Finder> openPayment(WidgetTester tester, ApiClient api,
      {String amount = '25'}) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        home: PaymentPage(
          api: api,
          subscriber: const {
            'id': 42,
            'full_name': 'Subscriber',
            'account_number': 'A42',
            'balance': '100.00'
          },
        )));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('payment-key-2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('payment-amount')));
    await tester.pumpAndSettle();
    for (final digit in amount.split('')) {
      await tester.tap(find.byKey(ValueKey('payment-key-$digit')));
      await tester.pump();
    }
    return find.byType(Scrollable).first;
  }

  /// Scroll the form from the top until [target] is in view.
  Future<void> reveal(
      WidgetTester tester, Finder scrollable, Finder target) async {
    tester.state<ScrollableState>(scrollable).position.jumpTo(0);
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(target, 150, scrollable: scrollable);
    await tester.ensureVisible(target);
    await tester.pumpAndSettle();
  }

  Future<void> tapInList(
      WidgetTester tester, Finder scrollable, Finder target) async {
    await reveal(tester, scrollable, target);
    await tester.tap(target);
    await tester.pumpAndSettle();
  }

  Future<void> enterInList(
      WidgetTester tester, Finder scrollable, String key, String value) async {
    final field = find.byKey(ValueKey(key));
    await reveal(tester, scrollable, field);
    await tester.tap(field);
    await tester.pumpAndSettle();
    await tester.enterText(field, value);
    await tester.pumpAndSettle();
  }

  Future<void> confirmAndSubmit(WidgetTester tester, Finder scrollable) async {
    await tapInList(
        tester, scrollable, find.byKey(const ValueKey('payment-confirm')));
    await tester.tap(find.byKey(const ValueKey('payment-submit')));
    await tester.pumpAndSettle();
  }

  testWidgets(
      'phone back and a downward swipe hide the keypad and retain the payment amount',
      (tester) async {
    final api = RecordingApi();
    await openPayment(tester, api);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('payment-key-2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('payment-amount')));
    await tester.pumpAndSettle();
    await tester.timedDrag(find.byKey(const ValueKey('payment-key-5')),
        const Offset(0, 100), const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('payment-key-2')), findsNothing);
    expect(find.text('25'), findsOneWidget);
    expect(api.requests, isEmpty);
  });

  testWidgets(
      'a transfer goes to the chosen bank from the subscriber and retries the same operation',
      (tester) async {
    final api = RecordingApi(failFirst: true);
    final scrollable = await openPayment(tester, api);
    expect(find.text('تسجيل 25.00 شيكل'), findsOneWidget);

    await tapInList(tester, scrollable,
        find.byKey(const ValueKey('payment-method-bank_transfer')));
    await enterInList(tester, scrollable, 'payment-reference', 'TRANSFER-42');
    final bankDate =
        DateUtils.dateOnly(DateTime.now()).subtract(const Duration(days: 1));
    await reveal(tester, scrollable, find.byType(BankReceivedDateField));
    tester
        .widget<BankReceivedDateField>(find.byType(BankReceivedDateField))
        .onChanged(bankDate);
    await tester.pumpAndSettle();
    await confirmAndSubmit(tester, scrollable);
    expect(api.requests, isEmpty);
    expect(find.text('اختر البنك أو المحفظة التي حُوّل إليها المبلغ.'),
        findsOneWidget);

    await tapInList(tester, scrollable,
        find.byKey(const ValueKey('payment-bank-البنك الوطني الإسلامي')));
    await tester.tap(find.byKey(const ValueKey('payment-submit')));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(1));
    expect(find.text('تم تسجيل الدفعة'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('payment-submit')));
    await tester.pumpAndSettle();

    expect(api.requests, hasLength(2));
    expect(api.requests.last['mobile_operation_id'],
        api.requests.first['mobile_operation_id']);
    expect(api.requests.last, containsPair('payment_method', 'bank_transfer'));
    expect(
        api.requests.last, containsPair('bank_name', 'البنك الوطني الإسلامي'));
    expect(api.requests.last, containsPair('sender_name', 'Subscriber'));
    expect(api.requests.last, containsPair('reference_number', 'TRANSFER-42'));
    expect(api.requests.last,
        containsPair('bank_received_on', bankReceivedDateValue(bankDate)));
    expect(api.requests.last, containsPair('currency', 'ILS'));
    expect(api.requests.last.containsKey('exchange_rate'), isFalse);
    expect(api.requests.last.containsKey('sender_bank_name'), isFalse);
    expect(find.text('تم تسجيل الدفعة'), findsOneWidget);
    expect(find.text('P-77'), findsOneWidget);
    expect(find.text('تحويل إلى البنك الوطني الإسلامي'), findsOneWidget);
    expect(find.text('\u206675 ₪\u2069 عليه'), findsOneWidget);
  });

  testWidgets('a dollar payment needs an exchange rate and counts in shekels',
      (tester) async {
    final api = RecordingApi();
    final scrollable = await openPayment(tester, api, amount: '20');
    await tester.tap(find.text('\$ دولار'));
    await tester.pumpAndSettle();
    await tapInList(
        tester, scrollable, find.byKey(const ValueKey('payment-method-cash')));
    await confirmAndSubmit(tester, scrollable);
    expect(api.requests, isEmpty);
    expect(find.text('أدخل سعر الصرف لتحويل المبلغ إلى شيكل.'), findsOneWidget);

    await enterInList(tester, scrollable, 'payment-rate', '3.7');
    expect(find.text('= 74.00 ₪'), findsOneWidget);
    expect(find.text('\u206626 ₪\u2069 عليه'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('payment-submit')));
    await tester.pumpAndSettle();

    expect(api.requests.single, containsPair('currency', 'USD'));
    expect(api.requests.single, containsPair('exchange_rate', '3.7'));
    expect(api.requests.single, containsPair('payment_method', 'cash'));
    expect(find.text('بالشيكل'), findsOneWidget);
    expect(find.text('74.00 ₪'), findsOneWidget);
  });

  testWidgets(
      'like the website, a payment starts on a transfer, shows the share of the debt it covers, and the receipt shows the balance the server reports',
      (tester) async {
    final api = RecordingApi(balanceAfter: '60.00');
    final scrollable = await openPayment(tester, api);

    await reveal(tester, scrollable, find.text('تغطية المبلغ المستحق'));
    expect(find.text('25%'), findsOneWidget);
    expect(find.text('تحويل بنكي'), findsOneWidget);

    await tapInList(
        tester, scrollable, find.byKey(const ValueKey('payment-method-cash')));
    await confirmAndSubmit(tester, scrollable);

    expect(api.requests.single, containsPair('payment_method', 'cash'));
    expect(find.text('\u206660 ₪\u2069 عليه'), findsOneWidget);
  });

  testWidgets(
      'another payment from the same subscriber starts a fresh form from the new balance',
      (tester) async {
    final api = RecordingApi(balanceAfter: '75.00');
    final scrollable = await openPayment(tester, api);
    await tapInList(
        tester, scrollable, find.byKey(const ValueKey('payment-method-cash')));
    await confirmAndSubmit(tester, scrollable);
    expect(find.text('تم تسجيل الدفعة'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('payment-another')));
    await tester.pumpAndSettle();

    expect(find.text('تم تسجيل الدفعة'), findsNothing);
    expect(find.text('تسجيل الدفعة'), findsOneWidget);
    expect(find.text('\u206675 ₪\u2069 عليه'), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('payment-amount')));
    await tester.pumpAndSettle();
    for (final digit in ['1', '0']) {
      await tester.tap(find.byKey(ValueKey('payment-key-$digit')));
      await tester.pump();
    }
    final again = find.byType(Scrollable).first;
    await tapInList(
        tester, again, find.byKey(const ValueKey('payment-method-cash')));
    await confirmAndSubmit(tester, again);

    expect(api.requests, hasLength(2));
    expect(api.requests.last['amount'], '10');
    expect(api.requests.last['mobile_operation_id'],
        isNot(api.requests.first['mobile_operation_id']));
  });
}
