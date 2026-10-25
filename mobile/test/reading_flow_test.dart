import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/field_store.dart';
import 'package:power_collect/main.dart';

import 'field_app_test.dart' show MemoryStore, OfflineApi;

MemoryStore readerStore({List<Map<String, dynamic>> queued = const []}) =>
    MemoryStore()
      ..state = {
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
            'recent_readings': [
              {
                'week_start': '2026-09-14',
                'current_reading': 1200,
                'consumption': 40
              },
              {
                'week_start': '2026-09-07',
                'current_reading': 1160,
                'consumption': 50
              },
            ],
          },
          {
            'id': 2,
            'assignment_id': 12,
            'authorization': 'signed-work',
            'full_name': 'Subscriber Two',
            'account_number': 'A2',
            'meter_box_id': 7,
            'meter_box_number': 'B1',
            'previous_reading': 300,
            'reading_status': null,
          },
        ],
        'queued_readings': queued,
      };

Future<void> openBox(WidgetTester tester, MemoryStore store,
    {bool startEditing = true}) async {
  await tester.binding.setSurfaceSize(const Size(390, 844));
  addTearDown(() => tester.binding.setSurfaceSize(null));
  await tester
      .pumpWidget(PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
  await tester.pumpAndSettle();
  await tester.tap(find.text('إدخال القراءات').last);
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('box-B1')));
  await tester.pumpAndSettle();
  expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
  if (startEditing) {
    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
  }
}

Future<void> typeReading(WidgetTester tester, String digits) async {
  for (final digit in digits.split('')) {
    await tester.tap(find.byKey(ValueKey('reading-key-$digit')));
    await tester.pump();
  }
}

void main() {
  testWidgets(
      'readings with more than two decimal places explain the error before saving',
      (tester) async {
    final store = readerStore();
    await openBox(tester, store);
    await typeReading(tester, '1245.123');
    expect(find.text('القراءة تقبل منزلتين عشريتين كحد أقصى.'), findsOneWidget);
    expect(find.text('حفظ قراءة واحدة على الجهاز'), findsNothing);
    expect(store.queuedReadings, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'the next reading date and assigned boxes are visible offline while entry stays locked',
      (tester) async {
    final store = readerStore();
    store.state['subscribers'] = [];
    store.state['can_record_readings_now'] = false;
    store.state['next_reading'] = {
      'reading_date': '2026-10-29',
      'boxes': [
        {
          'id': 7,
          'name': 'North Box',
          'number': 'B1',
          'sub_area_id': 1,
          'sub_area_name': 'North',
          'subscribers': 2,
          'subscriptions': [
            {
              'id': 55,
              'name': 'Assigned Preview Subscriber',
              'account_number': 'P55',
              'phone': '0599000001'
            }
          ],
        },
        {
          'id': 8,
          'name': 'South Box',
          'number': 'B2',
          'sub_area_id': 2,
          'sub_area_name': 'South',
          'subscribers': 3
        },
      ],
    };
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.textContaining('الخميس 29/10/2026'), findsOneWidget);
    expect(find.text('2 طبلون موزع إليك'), findsOneWidget);
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();
    expect(find.textContaining('الخميس 29/10/2026'), findsOneWidget);
    expect(find.byKey(const ValueKey('preview-box-7')), findsOneWidget);
    expect(find.byKey(const ValueKey('preview-box-8')), findsOneWidget);
    await tester.tap(find.text('North').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('preview-box-8')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('preview-box-7')));
    await tester.pumpAndSettle();
    expect(find.text('Assigned Preview Subscriber'), findsOneWidget);
    expect(find.text('0599000001'), findsOneWidget);
    await tester.tap(find.text('Assigned Preview Subscriber'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    expect(store.queuedReadings, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('downloaded boxes stay locked outside their dated entry window',
      (tester) async {
    final store = readerStore();
    store.state['entry_starts_at'] = '2099-01-01T00:00:00+02:00';
    store.state['entry_ends_at'] = '2099-01-01T23:59:59+02:00';
    store.state['reading_date'] = '2099-01-01';
    await openBox(tester, store, startEditing: false);
    expect(find.text('Subscriber One'), findsOneWidget);
    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    expect(store.queuedReadings, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('area 2 filters the reader boxes and remains searchable offline',
      (tester) async {
    final store = readerStore();
    final subscribers = store.state['subscribers'] as List;
    subscribers[0].addAll({'sub_area_id': 1, 'sub_area_name': 'North'});
    subscribers[1].addAll({
      'sub_area_id': 2,
      'sub_area_name': 'South',
      'meter_box_id': 8,
      'meter_box_number': 'B2'
    });
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('North').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('box-B1')), findsOneWidget);
    expect(find.byKey(const ValueKey('box-B2')), findsNothing);
    await tester.tap(find.text('كل المناطق'));
    await tester.enterText(find.byType(TextField), 'South');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('box-B1')), findsNothing);
    expect(find.byKey(const ValueKey('box-B2')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'phone back and a downward swipe hide the keypad without losing a reading',
      (tester) async {
    final store = readerStore();
    await openBox(tester, store, startEditing: false);
    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
    await typeReading(tester, '1245');
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
    await tester.timedDrag(find.byKey(const ValueKey('reading-key-5')),
        const Offset(0, 100), const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    expect(store.readingDrafts, {1: '1245'});
    await tester.pumpWidget(const SizedBox());
  });

  test('drafts belong to the roster week and leave once queued', () async {
    final directory =
        await Directory.systemTemp.createTemp('powercollect-drafts-');
    try {
      final store = FieldStore(directory: directory)
        ..state['week_start'] = '2026-09-21';
      await store.saveReadingDrafts({1: '1250', 2: ''});
      final restored = FieldStore(directory: directory);
      await restored.load();
      expect(restored.readingDrafts, {1: '1250'});

      await restored.queueReading({
        'mobile_operation_id': 'operation-1',
        'subscription_id': 1,
        'current_reading': '1250',
      });
      expect(restored.readingDrafts, isEmpty);

      await restored.reopenReading('operation-1');
      expect(restored.queuedReadings, isEmpty);
      expect(restored.readingDrafts, {1: '1250'});

      restored.state['week_start'] = '2026-09-28';
      expect(restored.readingDrafts, isEmpty);
    } finally {
      await directory.delete(recursive: true);
    }
  });

  testWidgets(
      'a typed reading is compared with past weeks and survives leaving the box',
      (tester) async {
    final store = readerStore();
    await openBox(tester, store);

    await typeReading(tester, '1300');
    expect(find.textContaining('أعلى من المعتاد بكثير'), findsOneWidget);
    for (var index = 0; index < 3; index++) {
      await tester.tap(find.byKey(const ValueKey('reading-key-delete')));
      await tester.pump();
    }
    await typeReading(tester, '245');
    expect(find.text('ضمن المعتاد'), findsOneWidget);

    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing,
        reason: 'back first hides the keypad');
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(store.readingDrafts, {1: '1245'});
    expect(find.byKey(const ValueKey('resume-drafts')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('resume-drafts')));
    await tester.pumpAndSettle();
    expect(find.text('1245'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('the keypad closes and reopens on the chosen subscriber',
      (tester) async {
    final store = readerStore();
    await openBox(tester, store);

    await tester.tap(find.byKey(const ValueKey('reading-keypad-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);

    await tester.tap(find.text('Subscriber Two'));
    await tester.pumpAndSettle();
    await typeReading(tester, '350');
    expect(find.text('350'), findsWidgets);
    expect(store.queuedReadings, isEmpty);
    await tester.tap(find.text('حفظ قراءة واحدة على الجهاز'));
    await tester.pumpAndSettle();
    expect(store.queuedReadings.single['subscription_id'], 2);
    expect(store.queuedReadings.single['current_reading'], '350');
    expect(store.queuedReadings.single['assignment_id'], 12);
    expect(store.queuedReadings.single['authorization'], 'signed-work');
    expect(DateTime.tryParse(store.queuedReadings.single['captured_at']),
        isNotNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a reading saved on the phone can be re-entered before sending',
      (tester) async {
    final store = readerStore(queued: [
      {
        'mobile_operation_id': 'operation-1',
        'subscription_id': 1,
        'week_start': '2026-09-21',
        'current_reading': '1290',
      }
    ]);
    await openBox(tester, store, startEditing: false);

    await tester.tap(find.text('Subscriber One'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إعادة إدخال القراءة'));
    await tester.pumpAndSettle();
    expect(store.queuedReadings, isEmpty);
    expect(store.readingDrafts, {1: '1290'});

    await tester.tap(find.byKey(const ValueKey('reading-key-delete')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('reading-key-delete')));
    await tester.pump();
    await typeReading(tester, '40');
    await tester.tap(find.text('حفظ قراءة واحدة على الجهاز'));
    await tester.pumpAndSettle();
    expect(store.queuedReadings.single['current_reading'], '1240');
    expect(store.queuedReadings.single['mobile_operation_id'],
        isNot('operation-1'));
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('offline search finds a name however its Arabic is spelled',
      (tester) async {
    final store = readerStore();
    (store.state['subscribers'] as List)[0]['full_name'] = 'أحمد مصطفى';
    await tester.pumpWidget(
        PowerCollectApp(apiClient: OfflineApi(), fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إدخال القراءات').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byType(TextField));
    tester.testTextInput.enterText('م');
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isTrue);
    tester.testTextInput.enterText('مصطفي احمد');
    await tester.pumpAndSettle();
    expect(find.text('أحمد مصطفى'), findsOneWidget);
    expect(find.text('Subscriber Two'), findsNothing);

    expect(tester.testTextInput.isVisible, isTrue);
    tester.testTextInput.enterText('احمد خالد');
    await tester.pumpAndSettle();
    expect(find.text('أحمد مصطفى'), findsNothing);
    await tester.tap(find.byTooltip('مسح البحث'));
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isTrue);
    tester.testTextInput.enterText('B1');
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isTrue);
    expect(find.byKey(const ValueKey('box-B1')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
