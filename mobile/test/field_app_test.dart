import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/field_store.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/payment_page.dart';

class OfflineApi extends ApiClient {
  @override
  Future<Map<String, dynamic>> me() async =>
      throw const ApiException('Offline', 0);
}

class MemoryStore extends FieldStore {
  @override
  Future<void> load() async {}
  @override
  Future<void> save() async {}
}

class CollectorApi extends ApiClient {
  int submitted = 0;

  @override
  Future<Map<String, dynamic>> me() async => {'user': {}};

  @override
  Future<Map<String, dynamic>> collectionsToday() async => {'data': []};

  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
          {int page = 1}) async =>
      {
        'data': [
          {
            'id': 42,
            'full_name': 'Collector Subscriber',
            'account_number': 'A42',
            'balance': '100.00'
          }
        ]
      };

  @override
  Future<Map<String, dynamic>> collectionSubscriber(int id) async => {
        'subscription': {
          'id': id,
          'full_name': 'Collector Subscriber',
          'account_number': 'A42',
          'balance': '100.00',
        },
        'permissions': {
          'view_history': true,
          'record_payment': true,
          'payment_methods': ['cash', 'bank_transfer']
        },
        'last_payment': null,
        'transactions': [],
      };

  @override
  Future<Map<String, dynamic>> sendCollection(
      Map<String, dynamic> collection) async {
    submitted++;
    expect(collection['payment_method'], 'cash');
    expect(collection['amount'], '25');
    expect(collection['collector_confirmed'], true);
    return {'id': 1, 'status': 'recorded'};
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() {
    String? sessionToken;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('powercollect.session'),
            (call) async {
      if (call.method == 'read') return sessionToken;
      if (call.method == 'write') sessionToken = call.arguments as String;
      if (call.method == 'clear') sessionToken = null;
      return null;
    });
  });
  testWidgets(
      'an upgrade clears the old branch roster and keeps unsent readings',
      (tester) async {
    final store = MemoryStore()
      ..state = {
        'token': 'test',
        'user': {
          'id': 1,
          'username': 'reader',
          'name': 'Reader',
          'can_record_readings': true
        },
        'week_start': '2026-10-16',
        'subscribers': [
          {
            'id': 1,
            'full_name': 'Old Branch Subscriber',
            'meter_box_number': 'B1'
          }
        ],
        'queued_readings': [
          {
            'mobile_operation_id': 'existing-operation',
            'subscription_id': 1,
            'current_reading': '125'
          }
        ],
      };
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();
    expect(store.subscribers, isEmpty);
    expect(store.queuedReadings.single['mobile_operation_id'],
        'existing-operation');
    expect(find.text('Old Branch Subscriber'), findsNothing);
    expect(find.text('لا توجد طبلونات موزعة إليك حاليًا'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  test('switching staff clears the previous subscriber roster', () async {
    final directory =
        await Directory.systemTemp.createTemp('powercollect-users-');
    try {
      final store = FieldStore(directory: directory);
      await store.saveSession({'id': 1, 'username': 'reader-a'}, 'token-a');
      store.state['subscribers'] = [
        {'id': 42, 'full_name': 'Private Subscriber'}
      ];
      store.state['week_start'] = '2026-09-21';
      store.state['next_reading'] = {
        'reading_date': '2026-10-29',
        'boxes': [
          {'id': 42}
        ]
      };
      await store.save();

      await store.saveSession({'id': 2, 'username': 'reader-b'}, 'token-b');
      final restored = FieldStore(directory: directory);
      await restored.load();
      expect(restored.user?['id'], 2);
      expect(restored.subscribers, isEmpty);
      expect(restored.state['week_start'], isNull);
      expect(restored.state['next_reading'], isNull);

      await restored.queueReading({
        'mobile_operation_id': newOperationId(),
        'subscription_id': 7,
      });
      await expectLater(
        restored.saveSession({'id': 1, 'username': 'reader-a'}, 'token-a'),
        throwsA(isA<PendingReadingsAccountSwitch>()),
      );
      expect(restored.user?['id'], 2);
      expect(restored.queuedReadings, hasLength(1));
    } finally {
      await directory.delete(recursive: true);
    }
  });

  test('concurrent offline readings persist without losing either entry',
      () async {
    final directory =
        await Directory.systemTemp.createTemp('powercollect-queue-');
    try {
      final store = FieldStore(directory: directory);
      await Future.wait([
        store.queueReading(
            {'mobile_operation_id': newOperationId(), 'subscription_id': 1}),
        store.queueReading(
            {'mobile_operation_id': newOperationId(), 'subscription_id': 2}),
      ]);
      final restored = FieldStore(directory: directory);
      await restored.load();
      expect(restored.queuedReadings, hasLength(2));
    } finally {
      await directory.delete(recursive: true);
    }
  });

  test('queued meter readings survive restart with the same operation id',
      () async {
    final directory =
        await Directory.systemTemp.createTemp('powercollect-test-');
    try {
      final store = FieldStore(directory: directory);
      final id = newOperationId();
      await store.queueReading({
        'mobile_operation_id': id,
        'subscription_id': 42,
        'week_start': '2026-09-21',
        'current_reading': '1250',
      });
      final restored = FieldStore(directory: directory);
      await restored.load();
      expect(restored.queuedReadings.single['mobile_operation_id'], id);
      await restored.markReadingUncertain(id);
      final pending = FieldStore(directory: directory);
      await pending.load();
      expect(pending.queuedReadings.single['upload_uncertain'], true);
      await pending.reopenReading(id);
      expect(pending.queuedReadings, hasLength(1));
      await restored.markReadingError(id, 'validation error');
      final again = FieldStore(directory: directory);
      await again.load();
      expect(again.queuedReadings.single['sync_error'], 'validation error');
      expect(again.queuedReadings.single['upload_uncertain'], isNull);
      await again.removeReading(id);
      expect(again.queuedReadings, isEmpty);
    } finally {
      await directory.delete(recursive: true);
    }
  });

  testWidgets(
      'offline reading queue remains visible and payments are absent for reader',
      (tester) async {
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'branch_name': 'Branch',
        'can_record_readings': true,
        'can_record_collections': false,
      },
      'week_start': '2026-09-21',
      'week_end': '2026-09-27',
      'can_record_readings_now': true,
      'assignment_roster_version': 1,
      'subscribers': [
        {
          'id': 42,
          'full_name': 'Subscriber One',
          'account_number': 'A42',
          'meter_box_number': 'B1',
          'previous_reading': 1200,
          'reading_status': null,
        }
      ],
      'queued_readings': [
        {
          'mobile_operation_id': newOperationId(),
          'subscription_id': 42,
          'week_start': '2026-09-21',
          'current_reading': '1250',
        }
      ],
    };
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.textContaining('قراءة واحدة بانتظار المزامنة'), findsOneWidget);
    expect(find.text('تسجيل الدفعات'), findsNothing);
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('box-B1')));
    await tester.pumpAndSettle();
    expect(find.text('Subscriber One'), findsOneWidget);
    expect(store.queuedReadings, hasLength(1));
    expect(find.text('على الجهاز'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('reader saves two readings from one box while offline',
      (tester) async {
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'branch_name': 'Branch',
        'can_record_readings': true,
        'can_record_collections': false,
      },
      'week_start': '2026-09-21',
      'week_end': '2026-09-27',
      'can_record_readings_now': true,
      'assignment_roster_version': 1,
      'subscribers': [
        {
          'id': 1,
          'full_name': 'Subscriber One',
          'account_number': 'A1',
          'meter_box_id': 7,
          'meter_box_number': 'B1',
          'previous_reading': 1200,
          'reading_status': null,
        },
        {
          'id': 2,
          'full_name': 'Subscriber Two',
          'account_number': 'A2',
          'meter_box_id': 7,
          'meter_box_number': 'B1',
          'previous_reading': 300,
          'reading_status': null,
        },
      ],
    };
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('box-B1')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
    for (final digit in ['1', '1', '0', '0']) {
      await tester.tap(find.byKey(ValueKey('reading-key-$digit')));
      await tester.pump();
    }
    expect(find.textContaining('أقل من السابقة'), findsWidgets);
    expect(store.queuedReadings, isEmpty);
    for (var index = 0; index < 4; index++) {
      await tester.tap(find.byKey(const ValueKey('reading-key-delete')));
      await tester.pump();
    }
    for (final digit in ['1', '2', '5', '0']) {
      await tester.tap(find.byKey(ValueKey('reading-key-$digit')));
      await tester.pump();
    }
    await tester.tap(find.byKey(const ValueKey('reading-key-next')));
    await tester.pump();
    for (final digit in ['3', '5', '0']) {
      await tester.tap(find.byKey(ValueKey('reading-key-$digit')));
      await tester.pump();
    }
    await tester.tap(find.text('حفظ قراءتين على الجهاز'));
    await tester.pumpAndSettle();

    expect(store.queuedReadings, hasLength(2));
    expect(store.queuedReadings.map((reading) => reading['current_reading']),
        containsAll(['1250', '350']));
    expect(find.text('تم حفظ القراءات'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('collector submits payment online without an offline queue',
      (tester) async {
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {
        'id': 2,
        'username': 'collector',
        'name': 'Collector',
        'branch_name': 'Branch',
        'can_record_readings': false,
        'can_record_collections': true,
      },
    };
    final api = CollectorApi();
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الدفعات').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Collector Subscriber'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('subscriber-record-payment')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('payment-amount')));
    await tester.pumpAndSettle();
    for (final digit in ['2', '5']) {
      await tester.tap(find.byKey(ValueKey('payment-key-$digit')));
      await tester.pump();
    }
    final cash = find.byKey(const ValueKey('payment-method-cash'));
    await tester.scrollUntilVisible(cash, 200,
        scrollable: find
            .descendant(
                of: find.byType(PaymentPage), matching: find.byType(Scrollable))
            .first);
    await tester.pumpAndSettle();
    await tester.tap(cash);
    await tester.pumpAndSettle();
    final recordButton =
        tester.widget<AppAction>(find.byKey(const ValueKey('payment-submit')));
    expect(recordButton.onPressed, isNull);
    await tester.scrollUntilVisible(
        find.byKey(const ValueKey('payment-confirm')), 200,
        scrollable: find
            .descendant(
                of: find.byType(PaymentPage), matching: find.byType(Scrollable))
            .first);
    await tester.tap(find.byKey(const ValueKey('payment-confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل 25.00 شيكل'));
    await tester.pumpAndSettle();
    expect(api.submitted, 1);
    expect(store.queuedReadings, isEmpty);
    expect(find.text('تم تسجيل الدفعة'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
