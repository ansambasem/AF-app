import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/collection_view.dart';
import 'package:power_collect/main.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

const historicalPayments = <Map<String, dynamic>>[
  {
    'id': 1,
    'subscription': 'دفعة نقدية سابقة',
    'payment_method': 'cash',
    'amount': '20',
    'amount_in_shekels': '20'
  },
  {
    'id': 2,
    'subscription': 'حوالة سابقة',
    'payment_method': 'bank_transfer',
    'amount': '30',
    'amount_in_shekels': '30'
  },
];

class PermissionSummaryApi extends CollectorApi {
  List<String> methods = ['cash', 'bank_transfer'];
  Map<String, dynamic> get user => {
        'id': 2,
        'name': 'محصل',
        'username': 'collector',
        'can_record_collections': true,
        'can_record_readings': false,
        'payment_methods': methods
      };
  @override
  Future<Map<String, dynamic>> me() async => {'user': user};
  @override
  Future<Map<String, dynamic>> collectionsToday() async =>
      {'data': historicalPayments};
}

void main() {
  for (final methods in <List<String>>[
    [],
    ['cash'],
    ['bank_transfer'],
    ['cash', 'bank_transfer']
  ]) {
    testWidgets('collection summary shows only permitted methods: $methods',
        (tester) async {
      await tester.binding.setSurfaceSize(const Size(320, 568));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final search = TextEditingController();
      addTearDown(search.dispose);
      await tester.pumpWidget(MaterialApp(
          theme: AppIdentity.theme,
          builder: (context, child) =>
              Directionality(textDirection: TextDirection.rtl, child: child!),
          home: Scaffold(
              body: CollectionView(
            search: search,
            results: const [],
            today: historicalPayments,
            busy: false,
            online: true,
            error: null,
            hasMore: false,
            initiallyShowingToday: true,
            paymentMethods: methods,
            onSearchChanged: () {},
            onSearch: () {},
            onLoadMore: () {},
            onOpen: (_) {},
          ))));
      expect(find.text('نقدًا اليوم'),
          methods.contains('cash') ? findsOneWidget : findsNothing);
      expect(find.text('تحويلات اليوم'),
          methods.contains('bank_transfer') ? findsOneWidget : findsNothing);
      expect(find.text('50.00 ₪'), findsOneWidget);
      expect(find.text('دفعة نقدية سابقة'), findsOneWidget);
      expect(find.text('حوالة سابقة'), findsOneWidget);
      expect(tester.getTopLeft(find.text('دفعة نقدية سابقة')).dy,
          lessThan(tester.getTopLeft(find.text('حوالة سابقة')).dy));
    });
  }

  testWidgets(
      'permission changes update the open daily summary without hiding old records',
      (tester) async {
    final api = PermissionSummaryApi();
    final store = MemoryStore()
      ..state = {'token': 'test-token', 'user': api.user};
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    final today = find.byKey(const ValueKey('collector-today-payments'));
    await tester.ensureVisible(today);
    await tester.tap(today);
    await tester.pumpAndSettle();
    expect(find.text('نقدًا اليوم'), findsOneWidget);
    expect(find.text('تحويلات اليوم'), findsOneWidget);
    api.methods = ['bank_transfer'];
    await tester.pump(const Duration(seconds: 30));
    await tester.pumpAndSettle();
    expect(find.text('نقدًا اليوم'), findsNothing);
    expect(find.text('تحويلات اليوم'), findsOneWidget);
    expect(find.text('50.00 ₪'), findsOneWidget);
    expect(find.text('دفعة نقدية سابقة'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a result cannot be opened while its search is being replaced',
      (tester) async {
    final search = TextEditingController();
    addTearDown(search.dispose);
    var opened = 0;
    Widget view(bool busy) => MaterialApp(
        theme: AppIdentity.theme,
        home: Scaffold(
            body: CollectionView(
          search: search,
          results: const [
            {'id': 42, 'full_name': 'نتيجة قديمة'}
          ],
          today: const [],
          busy: busy,
          online: true,
          error: null,
          hasMore: false,
          onSearchChanged: () {},
          onSearch: () {},
          onLoadMore: () {},
          onOpen: (_) => opened++,
        )));
    await tester.pumpWidget(view(true));
    await tester.tap(find.text('نتيجة قديمة'));
    expect(opened, 0);
    await tester.pumpWidget(view(false));
    await tester.pumpAndSettle();
    await tester.tap(find.text('نتيجة قديمة'));
    expect(opened, 1);
  });
}
