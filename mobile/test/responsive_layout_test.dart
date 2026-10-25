import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/payment_page.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

const subscriber = {
  'id': 42,
  'full_name': 'عبد الرحمن محمد أحمد إبراهيم أبو زيد',
  'account_number': '20260000000042',
  'balance': '3293.20',
  'phone': '0599000111',
  'address': 'شارع النور بالقرب من المدرسة الرئيسية',
  'status': 'active',
  'status_label': 'فعال',
  'accounting_type_label': 'أسبوعي',
  'subscription_date': '2026-09-01',
  'tariff_name': 'منزلي',
  'kilowatt_price': '2.50',
  'minimum_payment': '25.00',
  'circuit_breaker_ampere': 20,
  'meter_box_number': 'B7',
  'previous_reading': 1200,
};

class LayoutApi extends CollectorApi {
  @override
  Future<Map<String, dynamic>> rosterPage(int page) async => {
        'data': [subscriber],
        'last_page': 1,
        'week_start': '2026-10-09',
        'week_end': '2026-10-15',
        'can_record_readings_now': true,
        'assignment_roster_version': 1,
      };

  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
          {int page = 1}) async =>
      {
        'data': [subscriber],
        'current_page': 1,
        'last_page': 1
      };

  @override
  Future<Map<String, dynamic>> collectionSubscriber(int id) async => {
        'subscription': subscriber,
        'last_reading': {
          'week_start': '2026-10-09',
          'week_end': '2026-10-15',
          'previous_reading': 1200,
          'current_reading': 1250.5,
          'consumption': 50.5,
          'status': 'pending',
          'status_label': 'قيد المراجعة',
        },
        'permissions': {
          'view_history': true,
          'record_payment': true,
          'payment_methods': ['cash', 'bank_transfer']
        },
        'last_payment': null,
        'transactions': [],
      };
}

void main() {
  void expectNoLayoutErrors(WidgetTester tester, String screen) {
    final error = tester.takeException();
    if (error is FlutterError) {
      fail('$screen: ${error.toStringDeep()}');
    }
    expect(error, isNull, reason: screen);
  }

  for (final (name, size, ratio, scale) in [
    ('small phone', const Size(320, 568), 1.0, 1.0),
    ('short phone', const Size(320, 480), 2.0, 1.0),
    ('standard phone', const Size(360, 640), 3.0, 1.0),
    ('tall phone', const Size(412, 915), 3.0, 1.0),
    ('landscape', const Size(640, 360), 2.0, 1.0),
    ('tablet', const Size(800, 1280), 2.0, 1.0),
    ('large text', const Size(320, 640), 2.0, 1.5),
    ('largest text', const Size(360, 800), 3.0, 2.0),
    ('large text landscape', const Size(640, 360), 2.0, 1.5),
    ('large text short phone', const Size(320, 568), 3.0, 2.0),
    ('notched phone', const Size(390, 844), 3.0, 1.0),
    ('three-button navigation', const Size(360, 640), 2.0, 1.0),
  ]) {
    testWidgets('collector flow fits $name', (tester) async {
      tester.view.devicePixelRatio = ratio;
      tester.view.physicalSize = size * ratio;
      tester.view.padding = FakeViewPadding(
          top: (name == 'notched phone' ? 44 : 24) * ratio,
          bottom: (name == 'three-button navigation' ? 48 : 24) * ratio);
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetPadding);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final store = MemoryStore();
      store.state = {
        'token': 'test-token',
        'user': {
          'id': 2,
          'username': 'collector',
          'name': 'محمد أحمد إبراهيم',
          'branch_name': 'فرع المخيم الرئيسي',
          'can_record_readings': true,
          'can_record_collections': true,
        },
      };
      await tester.pumpWidget(
          PowerCollectApp(apiClient: LayoutApi(), fieldStore: store));
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'home');

      await tester.tap(find.byKey(const ValueKey('nav-collections')));
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'subscriber list');
      await tester.scrollUntilVisible(
          find.text(subscriber['full_name'] as String), 120,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      expect(find.text(subscriber['full_name'] as String), findsOneWidget);
      expect(find.textContaining('3293'), findsNothing);
      expect(find.textContaining('20260000000042'), findsNothing);
      await tester.ensureVisible(find.text(subscriber['full_name'] as String));
      await tester.pumpAndSettle();
      await tester.tap(find.text(subscriber['full_name'] as String));
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'subscriber summary');
      await tester.scrollUntilVisible(find.text('نوع الاشتراك'), 160,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'subscription details');

      await tester.tap(find.byKey(const ValueKey('subscriber-record-payment')));
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'payment and keypad');
      expect(find.byType(PaymentPage), findsOneWidget);
      expect(find.byKey(const ValueKey('payment-key-2')), findsNothing);
      await tester.scrollUntilVisible(
          find.byKey(const ValueKey('payment-amount')), 100,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('payment-amount')));
      await tester.pumpAndSettle();
      if (find.byKey(const ValueKey('payment-key-2')).evaluate().isEmpty) {
        await tester.scrollUntilVisible(
            find.byKey(const ValueKey('payment-key-2')), 100,
            scrollable: find.byType(Scrollable).first);
      } else {
        await tester.ensureVisible(find.byKey(const ValueKey('payment-key-2')));
      }
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('payment-key-2')));
      await tester.pumpAndSettle();
      await tester
          .ensureVisible(find.byKey(const ValueKey('payment-keypad-close')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('payment-keypad-close')));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
          find.byKey(const ValueKey('payment-confirm')), 160,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      expectNoLayoutErrors(tester, 'payment details');
      await tester.tap(find.byTooltip('رجوع').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('رجوع'));
      await tester.pumpAndSettle();

      for (final section in ['sync', 'account']) {
        await tester.tap(find.byKey(ValueKey('nav-$section')));
        await tester.pumpAndSettle();
        expectNoLayoutErrors(tester, section);
      }
      await tester.pumpWidget(const SizedBox());
    });
  }
}
