import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/reading_distribution_page.dart';

import 'field_app_test.dart' show MemoryStore, OfflineApi;

class DistributionApi extends ApiClient {
  bool manage = true;
  bool approve = true;
  final saved = <Map<String, dynamic>>[];

  @override
  Future<Map<String, dynamic>> readingDistribution({int? branchId}) async => {
        'branch': {'id': 1, 'name': 'Branch'},
        'cycle': {
          'id': 9,
          'reading_date': '2026-10-22',
          'available_on': '2026-10-21'
        },
        'abilities': {'manage': manage, 'approve': approve},
        'readers': [
          {'id': 5, 'name': 'Reader Five'}
        ],
        'boxes': [
          for (final id in [1, 2, 3])
            {
              'id': id,
              'name': 'Box $id',
              'number': 'B$id',
              'sub_area_id': id == 3 ? 2 : 1,
              'sub_area_name': id == 3 ? 'South' : 'North',
              'subscribers': 2,
              'version': id,
              'column': id == 2 ? 'reader:5' : 'available',
              'status': id == 2 ? 'approved' : null,
            },
        ],
      };

  @override
  Future<Map<String, dynamic>> distributeReadings(
      int cycleId, Map<String, dynamic> data) async {
    expect(cycleId, 9);
    saved.add(data);
    return {
      'moved': (data['box_ids'] as List).length,
      'conflicts': [],
      'confirmation_box_ids': []
    };
  }
}

Future<void> openDistribution(WidgetTester tester, DistributionApi api) async {
  await tester.binding.setSurfaceSize(const Size(390, 844));
  addTearDown(() => tester.binding.setSurfaceSize(null));
  await tester.pumpWidget(MaterialApp(
      home: Directionality(
          textDirection: TextDirection.rtl,
          child: ReadingDistributionPage(api: api))));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('distribution and assignment sheet fit a short landscape screen',
      (tester) async {
    final api = DistributionApi()..approve = false;
    await openDistribution(tester, api);
    await tester.binding.setSurfaceSize(const Size(640, 360));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('تحديد كل النتائج'), 150,
        scrollable: find.byType(Scrollable).first);
    await tester.tap(find.text('تحديد كل النتائج'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('توزيع 3 طبلون'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.ensureVisible(find.text('حفظ التوزيع'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التوزيع'));
    await tester.pumpAndSettle();
    expect(api.saved, hasLength(1));
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'area and assignment filters select only matching boxes for bulk assignment',
      (tester) async {
    final api = DistributionApi();
    await openDistribution(tester, api);
    await tester.tap(find.text('North'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('غير موزع').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('distribution-box-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('distribution-box-2')), findsNothing);
    expect(find.byKey(const ValueKey('distribution-box-3')), findsNothing);
    await tester.tap(find.text('تحديد كل النتائج'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('توزيع 1 طبلون'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التوزيع واعتماده'));
    await tester.pumpAndSettle();
    expect(api.saved.single['box_ids'], [1]);
    expect(api.saved.single['versions'], {'1': 1});
    expect(api.saved.single['target'], 'mobile');
    expect(api.saved.single['user_id'], 5);
    expect(api.saved.single['recurring'], true);
    expect(api.saved.single['publish'], true);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'manage-only staff save drafts and cannot enable standing distribution',
      (tester) async {
    final api = DistributionApi()..approve = false;
    await openDistribution(tester, api);
    await tester.tap(find.text('تحديد كل النتائج'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('توزيع 3 طبلون'));
    await tester.pumpAndSettle();
    expect(tester.widget<SwitchListTile>(find.byType(SwitchListTile)).onChanged,
        isNull);
    await tester.tap(find.text('حفظ التوزيع'));
    await tester.pumpAndSettle();
    expect(api.saved.single['publish'], false);
    expect(api.saved.single['recurring'], false);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('the home distribution action follows the user permission',
      (tester) async {
    for (final allowed in [false, true]) {
      final store = MemoryStore()
        ..state = {
          'token': 'test',
          'user': {
            'id': 1,
            'username': 'manager',
            'name': 'Manager',
            'can_distribute_readings': allowed
          },
        };
      await tester.pumpWidget(PowerCollectApp(
          key: ValueKey(allowed), apiClient: OfflineApi(), fieldStore: store));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('reading-distribution-service')),
          allowed ? findsOneWidget : findsNothing);
      await tester.pumpWidget(const SizedBox());
    }
  });
}
