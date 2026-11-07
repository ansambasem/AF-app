import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/split_payment_page.dart';

class SplitApi extends ApiClient {
  SplitApi({this.failFirst = false});
  final bool failFirst;
  final requests = <Map<String, dynamic>>[];
  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
          {int page = 1}) async =>
      {
        'data': [
          {
            'id': 2,
            'full_name': 'المشترك الثاني',
            'account_number': 'A2',
            'balance': '100'
          }
        ],
        'current_page': 1,
        'last_page': 1,
      };
  @override
  Future<Map<String, dynamic>> sendSplitPayment(
      Map<String, dynamic> payment) async {
    requests.add(Map<String, dynamic>.from(payment));
    if (failFirst && requests.length == 1)
      throw const ApiException('Timeout', 0);
    return {
      'id': 1,
      'total_amount': '100.00',
      'reference_number': payment['reference_number'],
      'parts': [
        {
          'id': 1,
          'subscription': 'المشترك الأول',
          'amount': '40',
          'balance_after': '60',
          'voucher_number': 'P1'
        },
        {
          'id': 2,
          'subscription': 'المشترك الثاني',
          'amount': '60',
          'balance_after': '40',
          'voucher_number': 'P2'
        },
      ]
    };
  }
}

void main() {
  test('split allocations use exact cents and reject malformed amounts', () {
    expect(splitAmountCents('10.10')! + splitAmountCents('20.20')!, 3030);
    expect(splitAmountCents('-1'), isNull);
    expect(splitAmountCents('1.001'), isNull);
    expect(splitAmountCents('1.2.3'), isNull);
    expect(splitAmountCents('1000000.01'), isNull);
  });

  Future<Finder> open(WidgetTester tester, SplitApi api) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        home: Directionality(
            textDirection: TextDirection.rtl,
            child: SplitPaymentPage(api: api, subscriber: const {
              'id': 1,
              'full_name': 'المشترك الأول',
              'account_number': 'A1',
              'balance': '100'
            }, transferBanks: const [
              'بنك فلسطين'
            ], senderBanks: const [
              'بنك القدس'
            ]))));
    await tester.pumpAndSettle();
    return find.byType(Scrollable).first;
  }

  Future<void> reveal(WidgetTester tester, Finder list, Finder field) async {
    await tester.scrollUntilVisible(field, 120,
        scrollable: list, maxScrolls: 60);
    await tester.ensureVisible(field);
    await tester.pumpAndSettle();
  }

  Future<void> enter(
      WidgetTester tester, Finder list, String key, String text) async {
    final field = find.byKey(ValueKey(key));
    await reveal(tester, list, field);
    await tester.enterText(field, text);
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester, Finder list) async {
    await enter(tester, list, 'split-total', '100');
    final bank = find.byKey(const ValueKey('split-bank'));
    await reveal(tester, list, bank);
    await tester.tap(bank);
    await tester.pumpAndSettle();
    await tester.tap(find.text('بنك فلسطين').last);
    await tester.pumpAndSettle();
    await enter(tester, list, 'split-sender', 'محمد');
    await enter(tester, list, 'split-reference', 'BANK-100');
    await enter(tester, list, 'split-part-1', '40');
    await enter(tester, list, 'split-search', 'الثاني');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    final add = find.byKey(const ValueKey('split-add-2'));
    await reveal(tester, list, add);
    await tester.tap(add);
    await tester.pumpAndSettle();
    await enter(tester, list, 'split-part-2', '60');
    final confirm = find.byKey(const ValueKey('split-confirm'));
    await reveal(tester, list, confirm);
    await tester.tap(confirm);
    await tester.pumpAndSettle();
  }

  testWidgets('back hides the split form keyboard before asking to discard',
      (tester) async {
    final api = SplitApi();
    final list = await open(tester, api);
    await enter(tester, list, 'split-sender', 'اسم محفوظ');
    tester.view.viewInsets = const FakeViewPadding(bottom: 220);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isFalse);
    expect(find.text('إغلاق الحوالة المقسّمة؟'), findsNothing);
    expect(find.text('اسم محفوظ'), findsOneWidget);
    expect(api.requests, isEmpty);
    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('إغلاق الحوالة المقسّمة؟'), findsOneWidget);
    await tester.tap(find.text('متابعة'));
    await tester.pumpAndSettle();
    expect(find.text('اسم محفوظ'), findsOneWidget);
  });

  testWidgets('a complete reviewed transfer submits both shares together',
      (tester) async {
    final api = SplitApi();
    final list = await open(tester, api);
    expect(
        tester
            .widget<AppAction>(find.byKey(const ValueKey('split-submit')))
            .onPressed,
        isNull);
    await fill(tester, list);
    await tester.tap(find.byKey(const ValueKey('split-submit')));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(1));
    expect(api.requests.single['parts'], [
      {'subscription_id': 1, 'amount': '40'},
      {'subscription_id': 2, 'amount': '60'}
    ]);
    expect(api.requests.single['collector_confirmed'], true);
    expect(find.text('تم تسجيل الحوالة وحصص الاشتراكات معًا.'), findsOneWidget);
    expect(find.text('الرصيد بعد الدفعة: 60'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'an uncertain response freezes the allocation and retries the same operation',
      (tester) async {
    final api = SplitApi(failFirst: true);
    final list = await open(tester, api);
    await fill(tester, list);
    await tester.tap(find.byKey(const ValueKey('split-submit')));
    await tester.pumpAndSettle();
    expect(find.text('إعادة التحقق من التسجيل'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('split-submit')));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(2));
    expect(api.requests.first, api.requests.last);
    expect(tester.takeException(), isNull);
  });
}
