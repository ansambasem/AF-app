import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/reading_flow.dart';
import 'field_app_test.dart' show MemoryStore;

class AssignmentRefreshApi extends ApiClient {
  Map<String, dynamic> roster = readingRoster();
  int downloads = 0;
  int uploads = 0;
  bool offline = false;
  Completer<void>? gate;

  @override
  Future<Map<String, dynamic>> me() async {
    if (offline) throw const ApiException('Offline', 0);
    return {
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'can_record_readings': true
      }
    };
  }

  @override
  Future<Map<String, dynamic>> rosterPage(int page) async {
    downloads++;
    final snapshot = jsonDecode(jsonEncode(roster)) as Map<String, dynamic>;
    if (gate != null) await gate!.future;
    return snapshot;
  }

  @override
  Future<Map<String, dynamic>> sendReading(Map<String, dynamic> reading) async {
    uploads++;
    return {'status': 'pending'};
  }
}

Map<String, dynamic> readingRoster(
        {int boxId = 7,
        String number = 'B1',
        String area = 'North',
        bool previewOnly = false,
        String week = '2026-10-16'}) =>
    {
      'assignment_roster_version': 1,
      'last_page': 1,
      'week_start': week,
      'week_end': '2026-10-22',
      'can_record_readings_now': !previewOnly,
      'next_reading': previewOnly
          ? {
              'reading_date': '2099-10-29',
              'boxes': [
                {
                  'id': boxId,
                  'number': number,
                  'name': '$area Box',
                  'sub_area_id': boxId,
                  'sub_area_name': area,
                  'subscribers': 1
                }
              ]
            }
          : null,
      'data': previewOnly
          ? []
          : [
              {
                'id': boxId,
                'full_name': '$area Subscriber',
                'account_number': 'A$boxId',
                'meter_box_id': boxId,
                'meter_box_number': number,
                'meter_box_name': '$area Box',
                'sub_area_id': boxId,
                'sub_area_name': area,
                'previous_reading': 100,
                'reading_status': null,
                'assignment_id': boxId,
                'authorization': 'signed-$boxId'
              }
            ],
    };

Future<MemoryStore> startReader(
    WidgetTester tester, AssignmentRefreshApi api) async {
  await tester.binding.setSurfaceSize(const Size(390, 844));
  addTearDown(() => tester.binding.setSurfaceSize(null));
  final store = MemoryStore()
    ..state = {
      ...api.roster,
      'subscribers': api.roster['data'],
      'roster_updated_at': DateTime.now().toIso8601String(),
      'token': 'test-token',
      'user': {
        'id': 1,
        'username': 'reader',
        'name': 'Reader',
        'can_record_readings': true
      },
      'queued_readings': [
        {
          'mobile_operation_id': 'pending-operation',
          'subscription_id': 999,
          'week_start': '2026-10-16',
          'current_reading': '125'
        }
      ],
    };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
  return store;
}

Future<void> enterReadings(WidgetTester tester) async {
  await tester.tap(find.text('إدخال القراءات').last);
  await tester.pumpAndSettle();
}

Future<void> refresh(WidgetTester tester) async {
  await tester
      .widget<RefreshIndicator>(find.byType(RefreshIndicator).first)
      .onRefresh();
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
      'manual refresh replaces assigned previews immediately, clears a removed area, and never uploads',
      (tester) async {
    final api = AssignmentRefreshApi()
      ..roster = readingRoster(previewOnly: true);
    final store = await startReader(tester, api);
    await enterReadings(tester);
    final flow = tester.state(find.byType(ReadingFlow));
    await tester.tap(find.text('North').first);
    await tester.pumpAndSettle();
    final before = api.downloads;
    api.roster =
        readingRoster(boxId: 8, number: 'B2', area: 'South', previewOnly: true);
    await refresh(tester);
    expect(api.downloads, before + 1);
    expect(find.byKey(const ValueKey('preview-box-8')), findsOneWidget);
    expect(find.byKey(const ValueKey('preview-box-7')), findsNothing);
    expect(tester.state(find.byType(ReadingFlow)), same(flow));
    await tester.tap(find.byKey(const ValueKey('preview-box-8')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    expect(store.queuedReadings.single['mobile_operation_id'],
        'pending-operation');
    expect(store.token, 'test-token');
    expect(api.uploads, 0);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'reader polling and refreshing an open box replace removed work while retaining local drafts',
      (tester) async {
    final api = AssignmentRefreshApi();
    final store = await startReader(tester, api);
    await enterReadings(tester);
    await tester.tap(find.byKey(const ValueKey('box-B1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('North Subscriber'));
    await tester.pumpAndSettle();
    for (final digit in ['1', '3', '0']) {
      await tester.tap(find.byKey(ValueKey('reading-key-$digit')));
      await tester.pump();
    }
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
    expect(store.readingDrafts[7], '130');
    final before = api.downloads;
    api.roster = readingRoster(boxId: 8, number: 'B2', area: 'South');
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(api.downloads, greaterThan(before));
    expect(find.byKey(const ValueKey('box-B2')), findsOneWidget);
    expect(find.byKey(const ValueKey('box-B1')), findsNothing);
    expect(find.byKey(const ValueKey('reading-key-1')), findsNothing);
    expect(store.readingDrafts[7], '130');
    await tester.tap(find.byKey(const ValueKey('box-B2')));
    await tester.pumpAndSettle();
    api.roster = readingRoster(boxId: 9, number: 'B3', area: 'East');
    await refresh(tester);
    expect(find.byKey(const ValueKey('box-B3')), findsOneWidget);
    expect(store.queuedReadings, hasLength(1));
    expect(api.uploads, 0);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'resume and entering readings bypass a fresh cache while home polling stays lightweight',
      (tester) async {
    final api = AssignmentRefreshApi();
    final store = await startReader(tester, api);
    final initial = api.downloads;
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(api.downloads, initial);
    api.roster = readingRoster(boxId: 8, number: 'B2', area: 'South');
    await enterReadings(tester);
    expect(find.byKey(const ValueKey('box-B2')), findsOneWidget);
    api.roster = readingRoster(boxId: 9, number: 'B3', area: 'East');
    for (final state in [
      AppLifecycleState.inactive,
      AppLifecycleState.hidden,
      AppLifecycleState.paused,
      AppLifecycleState.hidden,
      AppLifecycleState.inactive,
      AppLifecycleState.resumed
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('box-B3')), findsOneWidget);
    api.offline = true;
    await refresh(tester);
    expect(find.byKey(const ValueKey('box-B3')), findsOneWidget);
    expect(store.queuedReadings, hasLength(1));
    expect(api.uploads, 0);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'a manual refresh during an in-flight request waits for the newer distribution',
      (tester) async {
    final api = AssignmentRefreshApi();
    await startReader(tester, api);
    await enterReadings(tester);
    final before = api.downloads;
    api.gate = Completer<void>();
    final onRefresh = tester
        .widget<RefreshIndicator>(find.byType(RefreshIndicator))
        .onRefresh;
    final first = onRefresh();
    await tester.pump();
    api.roster = readingRoster(boxId: 8, number: 'B2', area: 'South');
    final second = onRefresh();
    api.gate!.complete();
    await tester.pumpAndSettle();
    await Future.wait([first, second]);
    expect(api.downloads, before + 2);
    expect(find.byKey(const ValueKey('box-B2')), findsOneWidget);
    expect(api.uploads, 0);
    await tester.pumpWidget(const SizedBox());
  });

  test(
      'a delayed draft save cannot carry a previous week value into the refreshed week',
      () async {
    final store = MemoryStore()
      ..state = {
        'week_start': '2026-10-23',
        'reading_drafts_week': '2026-10-23',
        'reading_drafts': {'7': '140'}
      };
    await store.saveReadingDrafts({7: '130'}, weekStart: '2026-10-16');
    expect(store.readingDrafts, {7: '140'});
  });
}
