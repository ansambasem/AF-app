import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/cash_boxes_page.dart';
import 'package:power_collect/main.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

Map<String, dynamic> box(int id, String type, String status,
        List<Map<String, dynamic>> payments, Map<String, String> totals) =>
    {
      'id': id,
      'number': '$id',
      'type': type,
      'status': status,
      'stage': status == 'open' ? 'open' : 'completed',
      'opened_at': '2026-10-18T06:00:00Z',
      'totals': {'ILS': '0.00', 'USD': '0.00', 'JOD': '0.00', ...totals},
      'payments': payments,
    };

Map<String, dynamic> entry(int id, String amount, String name,
        {String currency = 'ILS',
        Map<String, dynamic> transaction = const {}}) =>
    {
      'id': id,
      'amount': amount,
      'currency': currency,
      'occurred_at': '2026-10-20T08:05:00Z',
      'transaction': {
        'voucher_number': 'V-$id',
        ...transaction,
        'subscription': {'full_name': name, 'account_number': '10-04$id'},
      },
    };

class BoxesApi extends CollectorApi {
  BoxesApi(
      {this.collects = true,
      this.methods = const ['cash', 'bank_transfer'],
      this.clearings = true,
      this.returned = const {'cash', 'bank_transfer', 'clearing'}});
  final bool collects;
  final List<String> methods;
  final bool clearings;

  /// The box types the server has boxes of for this collector.
  final Set<String> returned;
  bool allowed = true;
  int loads = 0;

  @override
  Future<Map<String, dynamic>> me() async => {
        'user': {
          'id': 2,
          'username': 'collector',
          'name': 'Collector',
          'can_record_readings': false,
          'can_record_collections': collects,
          'can_record_split_payments': collects,
          'can_view_subscriptions': true,
          'can_adjust_balances': collects,
          'can_add_clearings': collects && clearings,
          'payment_methods': collects ? methods : <String>[],
          'can_view_readings': true,
          'can_distribute_readings': true,
          'can_send_messages': true,
          'can_view_messages': true,
        }
      };

  @override
  Future<Map<String, dynamic>> cashBoxes() async {
    loads++;
    if (!allowed) throw const ApiException('Forbidden', 403);
    return {
      'started': true,
      'boxes': [
        box(31, 'cash', 'open', [
          entry(1, '600.00', 'سعاد الخطيب'),
          entry(2, '120.00', 'خالد عودة', currency: 'USD'),
        ], {
          'ILS': '600.00',
          'USD': '120.00'
        }),
        box(32, 'bank_transfer', 'open', [
          entry(3, '1200.00', 'مخبز النور', transaction: {
            'bank_name': 'بنك فلسطين',
            'reference_number': 'TR-889'
          }),
        ], {
          'ILS': '1200.00'
        }),
        box(33, 'clearing', 'open', [
          entry(4, '100.00', 'رنا الشريف',
              transaction: {'notes': 'صيانة مولد'}),
        ], {
          'ILS': '100.00'
        }),
        box(30, 'cash', 'received', [entry(5, '999.00', 'صندوق مغلق')],
            {'ILS': '999.00'}),
      ].where((box) => returned.contains(box['type'])).toList(),
    };
  }
}

Future<void> openHome(WidgetTester tester, BoxesApi api) async {
  final store = MemoryStore()
    ..state = {
      'token': 'test-token',
      'user': {'id': 2, 'username': 'collector', 'name': 'Collector'},
    };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
}

Finder boxTab(String label) => find.descendant(
    of: find.byKey(const ValueKey('cash-box-types')),
    matching: find.textContaining(label));

void main() {
  testWidgets(
      'home lists one card per destination and the box page shows open boxes by type',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(400, 1400));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final api = BoxesApi();
    await openHome(tester, api);
    expect(find.byKey(const ValueKey('collector-collections-service')),
        findsOneWidget);
    for (final duplicate in [
      'collector-split-payment',
      'subscriber-statements-service',
    ]) {
      expect(find.byKey(ValueKey(duplicate)), findsNothing);
    }
    expect(find.text('إرسال الرسائل'), findsNothing);
    expect(find.text('القراءات الأسبوعية'), findsNothing);
    expect(find.byKey(const ValueKey('reading-distribution-service')),
        findsOneWidget);
    expect(api.loads, 0);

    final boxes = find.byKey(const ValueKey('collector-cash-boxes-service'));
    await tester.ensureVisible(boxes);
    await tester.tap(boxes);
    await tester.pumpAndSettle();
    expect(find.byType(CashBoxesPage), findsOneWidget);
    expect(api.loads, 1);
    expect(find.text('صندوق #31'), findsOneWidget);
    expect(find.text('سعاد الخطيب'), findsOneWidget);
    expect(find.text('120.00 \$'), findsWidgets);
    expect(find.text('صندوق #30'), findsNothing);
    expect(find.text('صندوق مغلق'), findsNothing);
    expect(find.textContaining('تأكيد'), findsNothing);
    expect(
        find.textContaining('رئيس الفرع هو من يغلق الصناديق'), findsOneWidget);

    await tester.tap(boxTab('حوالات بنكية'));
    await tester.pumpAndSettle();
    expect(find.text('صندوق #32'), findsOneWidget);
    expect(find.textContaining('بنك فلسطين · مرجع TR-889'), findsOneWidget);
    expect(find.text('سعاد الخطيب'), findsNothing);

    await tester.tap(boxTab('مقاصة'));
    await tester.pumpAndSettle();
    expect(find.text('صندوق #33'), findsOneWidget);
    expect(find.textContaining('صيانة مولد'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a collector with only bank transfers sees just that box',
      (tester) async {
    final api = BoxesApi(
        methods: ['bank_transfer'],
        clearings: false,
        returned: {'bank_transfer'});
    await openHome(tester, api);
    final boxes = find.byKey(const ValueKey('collector-cash-boxes-service'));
    await tester.ensureVisible(boxes);
    expect(find.descendant(of: boxes, matching: find.text('حوالات بنكية')),
        findsOneWidget);
    await tester.tap(boxes);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cash-box-types')), findsNothing);
    expect(find.text('صندوق الحوالات البنكية المفتوح'), findsOneWidget);
    expect(find.text('صندوق #32'), findsOneWidget);
    expect(find.textContaining('بنك فلسطين'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'an open box of a type no longer permitted stays visible with its payments',
      (tester) async {
    final api = BoxesApi(
        methods: ['bank_transfer'],
        clearings: false,
        returned: {'cash', 'bank_transfer'});
    await openHome(tester, api);
    final boxes = find.byKey(const ValueKey('collector-cash-boxes-service'));
    await tester.ensureVisible(boxes);
    await tester.tap(boxes);
    await tester.pumpAndSettle();
    expect(boxTab('نقدي'), findsOneWidget);
    expect(boxTab('حوالات بنكية'), findsOneWidget);
    expect(boxTab('مقاصة'), findsNothing);
    expect(find.text('سعاد الخطيب'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a revoked box permission explains itself and shows no boxes',
      (tester) async {
    final api = BoxesApi()..allowed = false;
    await openHome(tester, api);
    final boxes = find.byKey(const ValueKey('collector-cash-boxes-service'));
    await tester.ensureVisible(boxes);
    await tester.tap(boxes);
    await tester.pumpAndSettle();
    expect(find.textContaining('ليس لديك صلاحية عرض الصناديق'), findsOneWidget);
    expect(find.textContaining('صندوق #'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('statement staff get the statements card and no box card',
      (tester) async {
    final api = BoxesApi(collects: false);
    await openHome(tester, api);
    expect(find.byKey(const ValueKey('subscriber-statements-service')),
        findsOneWidget);
    expect(find.byKey(const ValueKey('collector-cash-boxes-service')),
        findsNothing);
    expect(find.text('إرسال الرسائل'), findsNothing);
    expect(find.text('القراءات الأسبوعية'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
