import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/weekly_readings_page.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

class WeeklyApi extends CollectorApi {
  bool allowed = true;
  final queries = <String>[];
  final selectedWeeks = <String?>[];
  final selectedStatuses = <String>[];

  @override
  Future<Map<String, dynamic>> me() async => {
        'user': {
          'id': 2,
          'username': 'viewer',
          'name': 'Viewer',
          'can_view_readings': allowed,
          'can_record_readings': false,
          'can_record_collections': false,
        }
      };

  @override
  Future<Map<String, dynamic>> weeklyReadings(
      {String search = '',
      String? week,
      String subscriptionStatus = 'active',
      int page = 1}) async {
    queries.add(search);
    selectedWeeks.add(week);
    selectedStatuses.add(subscriptionStatus);
    if (!allowed) throw const ApiException('Forbidden', 403);
    return {
      'week_start': week ?? '2026-09-18',
      'week_options': [
        {'value': '2026-09-18', 'label': 'الأسبوع الحالي'},
        {'value': '2026-09-11', 'label': 'الأسبوع السابق'},
      ],
      'current_page': page,
      'last_page': 1,
      'total': 1,
      'data': [
        {
          'id': 42,
          'full_name':
              search.isEmpty ? 'Weekly Subscriber' : 'Filtered Subscriber',
          'account_number': 'A42',
          'meter_box_number': 'B1',
          'previous_reading': 100,
          'current_reading': 125,
          'consumption': 25,
          'amount_due': '20.00',
          'status': week == '2026-09-11' ? 'approved' : 'pending',
        }
      ],
    };
  }
}

Future<void> openViewer(WidgetTester tester, WeeklyApi api) async {
  final store = MemoryStore();
  store.state = {
    'token': 'test-token',
    'user': {'id': 2, 'username': 'viewer', 'name': 'Viewer'},
  };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
      'view permission is refreshed from server and opens read-only searchable weekly readings',
      (tester) async {
    final api = WeeklyApi();
    await openViewer(tester, api);
    expect(find.text('إدخال القراءات'), findsNothing);
    expect(find.text('تسجيل الدفعات'), findsNothing);
    await tester.tap(find.text('القراءات الأسبوعية'));
    await tester.pumpAndSettle();
    expect(find.text('Weekly Subscriber'), findsOneWidget);
    expect(find.text('125'), findsOneWidget);
    expect(find.text('بانتظار الاعتماد'), findsOneWidget);
    expect(api.selectedStatuses.last, 'active');
    await tester.tap(find.byKey(const ValueKey('weekly-status-disconnected')));
    await tester.pumpAndSettle();
    expect(api.selectedStatuses.last, 'disconnected');
    expect(find.byType(CheckboxListTile), findsNothing);

    await tester.tap(find.byType(DropdownButton<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('الأسبوع السابق').last);
    await tester.pumpAndSettle();
    expect(api.selectedWeeks.last, '2026-09-11');
    expect(find.text('معتمدة'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'A42');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(api.queries.last, 'A42');
    expect(find.text('Filtered Subscriber'), findsOneWidget);

    api.allowed = false;
    await tester.fling(find.byType(ListView).first, const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(find.textContaining('ليس لديك صلاحية عرض القراءات'), findsOneWidget);
    expect(find.text('Filtered Subscriber'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('users without viewing permission have no weekly reading entry',
      (tester) async {
    final api = WeeklyApi()..allowed = false;
    await openViewer(tester, api);
    expect(find.text('القراءات الأسبوعية'), findsNothing);
    expect(find.byType(WeeklyReadingsPage), findsNothing);
    expect(api.queries, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });
}
