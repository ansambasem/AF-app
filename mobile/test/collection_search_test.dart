import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

class SubscriberRequest {
  SubscriberRequest(this.query, this.page);
  final String query;
  final int page;
  final response = Completer<Map<String, dynamic>>();

  void complete(String name, {int lastPage = 1}) => response.complete({
        'data': [
          {
            'id': page,
            'full_name': name,
            'account_number': 'A$page',
            'balance': '100.00'
          }
        ],
        'current_page': page,
        'last_page': lastPage,
      });
}

class SearchApi extends CollectorApi {
  final requests = <SubscriberRequest>[];

  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
      {int page = 1}) {
    final request = SubscriberRequest(search, page);
    requests.add(request);
    return request.response.future;
  }
}

Future<void> openCollections(WidgetTester tester, SearchApi api) async {
  final store = MemoryStore();
  store.state = {
    'token': 'test-token',
    'user': {
      'id': 2,
      'username': 'collector',
      'name': 'Collector',
      'can_record_readings': false,
      'can_record_collections': true,
    },
  };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
  await tester.tap(find.text('تسجيل الدفعات'));
  await tester.pump();
}

void main() {
  testWidgets('back dismisses the keyboard before leaving the search page',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.complete('Initial Subscriber');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Keep this search');
    await tester.pump(const Duration(milliseconds: 300));
    api.requests.last.complete('Filtered Subscriber');
    await tester.pumpAndSettle();
    tester.view.viewInsets = const FakeViewPadding(bottom: 250);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isFalse);
    expect(find.text('Filtered Subscriber'), findsOneWidget);
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,
        'Keep this search');
    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('مهامك'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('leaving the page resets search and ignores outstanding replies',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.complete('Initial Subscriber');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Old search');
    await tester.pump(const Duration(milliseconds: 300));
    final staleRequest = api.requests.last;
    await tester.tap(find.text('الرئيسية'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الدفعات'));
    await tester.pump();
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,
        isEmpty);
    expect(api.requests.last.query, isEmpty);
    expect(api.requests.last.page, 1);
    api.requests.last.complete('Fresh Subscriber');
    await tester.pumpAndSettle();
    staleRequest.complete('Stale Subscriber', lastPage: 2);
    await tester.pumpAndSettle();
    expect(find.text('Fresh Subscriber'), findsOneWidget);
    expect(find.text('Stale Subscriber'), findsNothing);
    expect(find.text('عرض المزيد'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('leaving before the search delay cancels the pending search',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.complete('Initial Subscriber');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Cancelled search');
    await tester.tap(find.text('الرئيسية'));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(1));
    await tester.tap(find.text('تسجيل الدفعات'));
    await tester.pump();
    expect(api.requests.last.query, isEmpty);
    api.requests.last.complete('Fresh Subscriber');
    await tester.pumpAndSettle();
    expect(find.text('Fresh Subscriber'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('returning from subscriber details preserves search and results',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.complete('Initial Subscriber');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Selected');
    await tester.pump(const Duration(milliseconds: 300));
    api.requests.last.complete('Selected Subscriber');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Selected Subscriber'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pump();
    expect(api.requests.last.query, 'Selected');
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,
        'Selected');
    expect(find.text('Selected Subscriber'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('subscribers load on entry and more pages remain accessible',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    expect(api.requests.single.query, isEmpty);
    expect(api.requests.single.page, 1);
    api.requests.single.complete('First Subscriber', lastPage: 2);
    await tester.pumpAndSettle();
    expect(find.text('First Subscriber'), findsOneWidget);

    await tester.tap(find.text('عرض المزيد'));
    await tester.pump();
    expect(api.requests.last.query, isEmpty);
    expect(api.requests.last.page, 2);
    api.requests.last.complete('Second Subscriber', lastPage: 2);
    await tester.pumpAndSettle();
    expect(find.text('First Subscriber'), findsOneWidget);
    expect(find.text('Second Subscriber'), findsOneWidget);
    expect(find.text('عرض المزيد'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'typing searches automatically and older replies cannot replace new results',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.complete('Initial Subscriber');
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), 'A');
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.text('Initial Subscriber'), findsOneWidget,
        reason: 'the current results stay until the new ones arrive');
    await tester.enterText(find.byType(TextField), 'Ali');
    await tester.pump(const Duration(milliseconds: 300));
    expect(api.requests, hasLength(2));
    expect(api.requests.last.query, 'Ali');
    final oldRequest = api.requests.last;

    await tester.enterText(find.byType(TextField), 'A42');
    await tester.pump(const Duration(milliseconds: 300));
    expect(api.requests.last.query, 'A42');
    api.requests.last.complete('Latest Subscriber');
    await tester.pumpAndSettle();
    oldRequest.complete('Outdated Subscriber');
    await tester.pumpAndSettle();
    expect(find.text('Latest Subscriber'), findsOneWidget);
    expect(find.text('Outdated Subscriber'), findsNothing);

    await tester.tap(find.byTooltip('مسح البحث'));
    await tester.pump();
    expect(api.requests.last.query, isEmpty);
    expect(api.requests.last.page, 1);
    api.requests.last.complete('Initial Subscriber');
    await tester.pumpAndSettle();
    expect(find.text('Initial Subscriber'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('failed loading can be retried and stale failures are ignored',
      (tester) async {
    final api = SearchApi();
    await openCollections(tester, api);
    api.requests.single.response
        .completeError(const ApiException('Connection failed', 0));
    await tester.pumpAndSettle();
    expect(find.text('Connection failed'), findsOneWidget);
    expect(find.text('لا يوجد مشتركون متاحون'), findsNothing);

    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pump();
    final staleRequest = api.requests.last;
    await tester.enterText(find.byType(TextField), 'A42');
    await tester.pump(const Duration(milliseconds: 300));
    api.requests.last.complete('Recovered Subscriber');
    await tester.pumpAndSettle();
    staleRequest.response.completeError(const ApiException('Old failure', 0));
    await tester.pumpAndSettle();
    expect(find.text('Recovered Subscriber'), findsOneWidget);
    expect(find.text('Old failure'), findsNothing);
    expect(find.text('إعادة المحاولة'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
