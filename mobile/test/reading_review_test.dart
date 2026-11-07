import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/queued_readings.dart';
import 'field_app_test.dart' show MemoryStore;

class ReviewApi extends ApiClient {
  bool connected = true;
  bool timeoutOnce = false;
  bool permitted = true;
  final requests = <Map<String, dynamic>>[];
  final recorded = <String>{};
  final errors = <int, ApiException>{};
  final held = <int>{};
  Completer<void>? pending;
  @override
  Future<Map<String, dynamic>> me() async {
    if (!connected) throw const ApiException('Offline', 0);
    return {
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'can_record_readings': permitted
      }
    };
  }

  @override
  Future<Map<String, dynamic>> rosterPage(int page) async => {
        'data': [],
        'last_page': 1,
        'assignment_roster_version': 1,
        'week_start': '2026-10-16',
        'week_end': '2026-10-22',
        'can_record_readings_now': true,
      };
  @override
  Future<Map<String, dynamic>> sendReading(Map<String, dynamic> reading) async {
    requests.add(Map.from(reading));
    if (pending != null) await pending!.future;
    final subscriptionId = reading['subscription_id'] as int;
    if (errors[subscriptionId] case final error?) throw error;
    recorded.add('${reading['mobile_operation_id']}');
    if (timeoutOnce) {
      timeoutOnce = false;
      throw const ApiException('Response lost after recording', 0);
    }
    return {
      'status': held.contains(subscriptionId) ? 'needs_review' : 'pending'
    };
  }
}

Map<String, dynamic> queued(int id) => {
      'mobile_operation_id': 'operation-$id',
      'subscription_id': id,
      'week_start': '2026-10-16',
      'current_reading': '${100 + id}',
      'assignment_id': 9,
      'authorization': 'signed',
      'captured_at': '2026-10-22T10:00:00Z',
      'review': {
        'name': 'Reader Subscriber $id',
        'account_number': 'A$id',
        'meter_box_number': 'B$id',
        'sub_area_name': 'North',
        'previous_reading': 100
      },
    };

Future<MemoryStore> openReview(WidgetTester tester, ReviewApi api,
    {List<Map<String, dynamic>>? queue}) async {
  await tester.binding.setSurfaceSize(const Size(390, 844));
  addTearDown(() => tester.binding.setSurfaceSize(null));
  final store = MemoryStore()
    ..state = {
      'token': 'test',
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'can_record_readings': true
      },
      'assignment_roster_version': 1,
      'week_start': '2026-10-16',
      'can_record_readings_now': true,
      'roster_updated_at': DateTime.now().toIso8601String(),
      'subscribers': [],
      'queued_readings': queue ?? [queued(1), queued(2), queued(3)],
    };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
  await tester.tap(find.text('الإرسال').last);
  await tester.pumpAndSettle();
  return store;
}

Future<void> reveal(WidgetTester tester, Finder target) async {
  await tester.scrollUntilVisible(target, 180,
      scrollable: find.byType(Scrollable).first);
  await tester.pumpAndSettle();
}

Future<void> confirmUpload(WidgetTester tester) async {
  await tester
      .ensureVisible(find.byKey(const ValueKey('confirm-reading-upload')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('confirm-reading-upload')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
      'large readings remain fully visible with large text on a small phone',
      (tester) async {
    final api = ReviewApi();
    final reading = queued(1);
    reading['current_reading'] = '9999999999.99';
    reading['review']['previous_reading'] = 9999999998;
    await openReview(tester, api, queue: [reading]);
    await tester.binding.setSurfaceSize(const Size(320, 640));
    tester.platformDispatcher.textScaleFactorTestValue = 1.8;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    await tester.pumpAndSettle();
    await reveal(tester, find.text('9999999999.99'));
    final layoutError = tester.takeException();
    if (layoutError is FlutterError) fail(layoutError.toStringDeep());
    expect(layoutError, isNull);
    expect(tester.widget<Text>(find.text('9999999999.99')).overflow,
        isNot(TextOverflow.ellipsis));
    expect(find.text('1.99'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'select all applies to search results and an active upload cannot start twice',
      (tester) async {
    final api = ReviewApi()..pending = Completer<void>();
    final store = await openReview(tester, api);
    await tester.enterText(find.byType(TextField), 'B2');
    await tester.pumpAndSettle();
    await reveal(tester, find.text('تحديد كل النتائج الجاهزة'));
    await tester.tap(find.text('تحديد كل النتائج الجاهزة'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('upload-selected-readings')));
    await tester.pumpAndSettle();
    await tester
        .ensureVisible(find.byKey(const ValueKey('confirm-reading-upload')));
    await tester.tap(find.byKey(const ValueKey('confirm-reading-upload')));
    await tester.pump(const Duration(seconds: 1));
    expect(api.requests, hasLength(1));
    expect(api.requests.single['subscription_id'], 2);
    await tester.tap(find.byKey(const ValueKey('upload-selected-readings')));
    await tester.pump();
    expect(api.requests, hasLength(1));
    api.pending!.complete();
    await tester.pumpAndSettle();
    expect(store.queuedReadings.map((reading) => reading['subscription_id']),
        [1, 3]);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('reading review and confirmation fit a short landscape screen',
      (tester) async {
    final api = ReviewApi();
    await openReview(tester, api, queue: [queued(1)]);
    await tester.binding.setSurfaceSize(const Size(640, 360));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('upload-all-readings')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await confirmUpload(tester);
    expect(api.requests, hasLength(1));
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'connecting, automatic refresh and app resume never upload saved readings',
      (tester) async {
    final api = ReviewApi()..connected = false;
    final store = await openReview(tester, api);
    api.connected = true;
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.requests, isEmpty);
    expect(store.queuedReadings, hasLength(3));
    expect(find.textContaining('لا ترفعها تلقائيًا'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'a single reading stays local on cancel and only selected readings upload after review',
      (tester) async {
    final api = ReviewApi();
    final store = await openReview(tester, api);
    await reveal(
        tester, find.byKey(const ValueKey('upload-reading-operation-1')));
    await tester.tap(find.byKey(const ValueKey('upload-reading-operation-1')));
    await tester.pumpAndSettle();
    expect(api.requests, isEmpty);
    expect(find.text('Reader Subscriber 1'), findsWidgets);
    await tester.ensureVisible(find.text('العودة للمراجعة'));
    await tester.tap(find.text('العودة للمراجعة'));
    await tester.pumpAndSettle();
    expect(store.queuedReadings, hasLength(3));
    await reveal(
        tester, find.byKey(const ValueKey('select-reading-operation-2')));
    await tester.tap(find.byKey(const ValueKey('select-reading-operation-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('upload-selected-readings')));
    await tester.pumpAndSettle();
    expect(api.requests, isEmpty);
    await confirmUpload(tester);
    expect(api.requests.single['subscription_id'], 2);
    expect(api.requests.single.containsKey('review'), false);
    expect(store.queuedReadings.map((reading) => reading['subscription_id']),
        [1, 3]);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'bulk upload keeps field errors by their reading and reports held submissions',
      (tester) async {
    final api = ReviewApi()
      ..errors[1] = const ApiException('Validation failed', 422, errors: {
        'current_reading': ['القراءة أقل من السابقة على الخادم.']
      })
      ..held.add(2);
    final store = await openReview(tester, api);
    await tester.tap(find.byKey(const ValueKey('upload-all-readings')));
    await tester.pumpAndSettle();
    await confirmUpload(tester);
    expect(api.requests, hasLength(3));
    expect(store.queuedReadings.single['subscription_id'], 1);
    expect(store.queuedReadings.single['sync_error'],
        'القراءة أقل من السابقة على الخادم.');
    await reveal(tester, find.text('القراءة أقل من السابقة على الخادم.'));
    expect(
        tester
            .widget<TextButton>(
                find.byKey(const ValueKey('upload-reading-operation-1')))
            .onPressed,
        isNull);
    expect(api.recorded, {'operation-2', 'operation-3'});
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'an app the server says to update stops uploading and keeps every reading',
      (tester) async {
    final api = ReviewApi()
      ..errors[1] = const ApiException('حدّث التطبيق إلى آخر نسخة.', 426);
    final store = await openReview(tester, api);
    await tester.tap(find.byKey(const ValueKey('upload-all-readings')));
    await tester.pumpAndSettle();
    await confirmUpload(tester);
    expect(api.requests, hasLength(1));
    expect(store.queuedReadings, hasLength(3));
    expect(
        store.queuedReadings.where((reading) =>
            reading.containsKey('sync_error') ||
            reading.containsKey('upload_uncertain')),
        isEmpty);
    expect(find.textContaining('حدّث التطبيق إلى آخر نسخة.'), findsWidgets);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'an uncertain upload cannot be edited and retries the same operation without duplicates',
      (tester) async {
    final api = ReviewApi()..timeoutOnce = true;
    final store = await openReview(tester, api, queue: [queued(1)]);
    await tester.tap(find.byKey(const ValueKey('upload-all-readings')));
    await tester.pumpAndSettle();
    await confirmUpload(tester);
    expect(store.queuedReadings.single['upload_uncertain'], true);
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(1));
    await reveal(
        tester, find.byKey(const ValueKey('edit-reading-operation-1')));
    expect(
        tester
            .widget<TextButton>(
                find.byKey(const ValueKey('edit-reading-operation-1')))
            .onPressed,
        isNull);
    await tester.tap(find.byKey(const ValueKey('upload-all-readings')));
    await tester.pumpAndSettle();
    await confirmUpload(tester);
    expect(api.requests.map((request) => request['mobile_operation_id']),
        ['operation-1', 'operation-1']);
    expect(api.recorded, hasLength(1));
    expect(store.queuedReadings, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'local validations are visible and users without permission cannot upload',
      (tester) async {
    final api = ReviewApi()..permitted = false;
    final store = await openReview(tester, api, queue: [
      {...queued(1), 'current_reading': '99'}
    ]);
    await reveal(
        tester, find.text('القراءة الحالية أقل من السابقة. صححها قبل الرفع.'));
    expect(find.byKey(const ValueKey('upload-all-readings')), findsNothing);
    expect(api.requests, isEmpty);
    expect(store.queuedReadings, hasLength(1));
    await tester.pumpWidget(const SizedBox());
  });

  test('review data retains the captured baseline and rejects malformed values',
      () {
    final reading = queued(1);
    expect(
        readingReviewData(reading, [
          {'id': 1, 'previous_reading': 999}
        ])['previous_reading'],
        100);
    for (final value in ['NaN', 'Infinity', '-1', '12.345', '10000000000']) {
      expect(
          readingValidation({...reading, 'current_reading': value}), isNotNull);
    }
    expect(readingValidation(reading), isNull);
  });
}
