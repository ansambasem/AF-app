import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/adjustment_page.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/subscriber_page.dart';

import 'collector_workflow_test.dart' show CollectorWorkflowApi;
import 'field_app_test.dart' show MemoryStore;
import 'subscriber_page_test.dart' show SubscriberApi, openSubscriber, listed;

const chargeTypes = [
  {'value': 'penalty', 'label': 'غرامة', 'needsReason': true},
  {'value': 'subscription_fee', 'label': 'رسوم اشتراك', 'needsReason': false},
  {'value': 'disconnection_fee', 'label': 'رسوم فصل', 'needsReason': false},
  {'value': 'minimum_charge', 'label': 'الحد الأدنى', 'needsReason': false},
];

class AdjustmentApi extends SubscriberApi {
  final requests = <Map<String, dynamic>>[];
  int? failure;
  Completer<void>? waitForSave;
  Completer<void>? waitForDetails;

  @override
  Future<Map<String, dynamic>> collectionSubscriber(int id) async {
    if (waitForDetails != null) await waitForDetails!.future;
    return {
      ...await super.collectionSubscriber(id),
      'charge_types': chargeTypes
    };
  }

  @override
  Future<Map<String, dynamic>> sendAdjustment(
      int id, Map<String, dynamic> adjustment) async {
    requests.add(Map.from(adjustment));
    if (waitForSave != null) await waitForSave!.future;
    if (failure != null) throw ApiException('تعذر التسجيل', failure!);
    balance = adjustment['action'] == 'charge' ? '145.00' : '95.00';
    return {'id': 9, 'balance_after': balance};
  }
}

Future<void> openAction(
    WidgetTester tester, AdjustmentApi api, String label) async {
  await openSubscriber(tester, api);
  await tester.tap(find.byKey(const ValueKey('subscriber-account-actions')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

Future<void> review(WidgetTester tester,
    {String amount = '25', String? notes}) async {
  await tester.enterText(
      find.byKey(const ValueKey('adjustment-amount')), amount);
  if (notes != null)
    await tester.enterText(
        find.byKey(const ValueKey('adjustment-notes')), notes);
  await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
  await tester.pumpAndSettle();
}

void main() {
  for (final permission in [
    'add_charge',
    'add_discount',
    'add_clearing',
    'none'
  ]) {
    testWidgets('account arrow shows only the actions granted by $permission',
        (tester) async {
      final api = AdjustmentApi()
        ..permissionDetails = {permission: true, 'record_payment': false};
      await openSubscriber(tester, api);
      final arrow = find.byKey(const ValueKey('subscriber-account-actions'));
      if (permission == 'none') {
        expect(arrow, findsNothing);
      } else {
        await tester.tap(arrow);
        await tester.pumpAndSettle();
        expect(find.text('تحميل رسوم اشتراك'),
            permission == 'add_charge' ? findsOneWidget : findsNothing);
        expect(find.text('إضافة خصم'),
            permission == 'add_discount' ? findsOneWidget : findsNothing);
        expect(find.text('إضافة مقاصة'),
            permission == 'add_clearing' ? findsOneWidget : findsNothing);
      }
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets('payment remains unavailable until server permissions arrive',
      (tester) async {
    final api = AdjustmentApi()..waitForDetails = Completer<void>();
    await tester.pumpWidget(
        MaterialApp(home: SubscriberPage(api: api, subscriber: listed)));
    await tester.pump();
    expect(
        tester
            .widget<AppAction>(
                find.byKey(const ValueKey('subscriber-record-payment')))
            .onPressed,
        isNull);
    api.waitForDetails!.complete();
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<AppAction>(
                find.byKey(const ValueKey('subscriber-record-payment')))
            .onPressed,
        isNotNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('revoked permissions disappear from an open subscriber page',
      (tester) async {
    final api = AdjustmentApi()..permissionDetails = {'add_clearing': true};
    await openSubscriber(tester, api);
    expect(find.byKey(const ValueKey('subscriber-account-actions')),
        findsOneWidget);
    api.permissionDetails = {'add_clearing': false, 'record_payment': false};
    await tester.pump(const Duration(seconds: 30));
    await tester.pumpAndSettle();
    expect(
        find.byKey(const ValueKey('subscriber-account-actions')), findsNothing);
    expect(
        tester
            .widget<AppAction>(
                find.byKey(const ValueKey('subscriber-record-payment')))
            .onPressed,
        isNull);
    await tester.pumpWidget(const SizedBox());
  });

  for (final label in [
    'تحميل رسوم اشتراك',
    'تحميل رسوم فصل',
    'إضافة خصم',
    'إضافة مقاصة'
  ]) {
    testWidgets('$label reviews, saves and returns to the updated subscriber',
        (tester) async {
      final api = AdjustmentApi()
        ..permissionDetails = {
          'add_charge': true,
          'add_discount': true,
          'add_clearing': true
        };
      await openAction(tester, api, label);
      await review(tester, notes: 'خدمة موثقة');
      expect(api.requests, isEmpty);
      await tester.tap(find.text('تأكيد التسجيل'));
      await tester.pumpAndSettle();
      expect(api.requests, hasLength(1));
      expect(find.byType(AdjustmentPage), findsNothing);
      expect(find.byType(SubscriberPage), findsOneWidget);
      expect(api.loads, 2);
      expect(
          api.requests.single['action'],
          label.startsWith('تحميل')
              ? 'charge'
              : label == 'إضافة خصم'
                  ? 'discount'
                  : 'clearing');
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets(
      'validation, cancellation and back navigation preserve entered data',
      (tester) async {
    final api = AdjustmentApi()..permissionDetails = {'add_charge': true};
    await openAction(tester, api, 'تحميل غرامة');
    await review(tester, amount: '-2');
    expect(find.textContaining('أدخل مبلغًا موجبًا'), findsOneWidget);
    await review(tester);
    expect(find.textContaining('اكتب تفاصيل الحركة'), findsOneWidget);
    await review(tester, notes: 'سبب الغرامة');
    await tester.tap(find.text('إلغاء'));
    await tester.pumpAndSettle();
    expect(api.requests, isEmpty);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('مغادرة النموذج؟'), findsOneWidget);
    await tester.tap(find.text('إلغاء'));
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<TextFormField>(
                find.byKey(const ValueKey('adjustment-notes')))
            .controller!
            .text,
        'سبب الغرامة');
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('مغادرة'));
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberPage), findsOneWidget);
    expect(api.requests, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'timeouts freeze the payload and retry the same operation without duplicate taps',
      (tester) async {
    final api = AdjustmentApi()
      ..permissionDetails = {'add_clearing': true}
      ..failure = 0
      ..waitForSave = Completer<void>();
    await openAction(tester, api, 'إضافة مقاصة');
    await review(tester, notes: 'صيانة');
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
    expect(api.requests, hasLength(1));
    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(find.byType(AdjustmentPage), findsOneWidget);
    api.waitForSave!.complete();
    await tester.pumpAndSettle();
    expect(find.textContaining('لم يصل تأكيد التسجيل'), findsOneWidget);
    expect(
        tester
            .widget<TextFormField>(
                find.byKey(const ValueKey('adjustment-amount')))
            .enabled,
        false);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('لم يصل تأكيد الحركة'), findsOneWidget);
    await tester.tap(find.text('إلغاء'));
    await tester.pumpAndSettle();
    api.failure = null;
    await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(2));
    expect(api.requests.first, api.requests.last);
    expect(find.byType(SubscriberPage), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  for (final status in [401, 403, 404]) {
    testWidgets(
        'access error $status returns safely to refresh subscriber permissions',
        (tester) async {
      final api = AdjustmentApi()
        ..permissionDetails = {'add_clearing': true}
        ..failure = status;
      await openAction(tester, api, 'إضافة مقاصة');
      await review(tester, notes: 'خدمة');
      await tester.tap(find.text('تأكيد التسجيل'));
      await tester.pumpAndSettle();
      api.permissionDetails = {'add_clearing': false};
      await tester.tap(find.text('العودة إلى المشترك'));
      await tester.pumpAndSettle();
      expect(find.byType(SubscriberPage), findsOneWidget);
      expect(find.byKey(const ValueKey('subscriber-account-actions')),
          findsNothing);
      expect(api.requests, hasLength(1));
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets(
      '320px screen with large text and keyboard can save a clearing that leaves credit',
      (tester) async {
    final api = AdjustmentApi()..permissionDetails = {'add_clearing': true};
    await openAction(tester, api, 'إضافة مقاصة');
    await tester.binding.setSurfaceSize(const Size(320, 568));
    tester.platformDispatcher.textScaleFactorTestValue = 1.5;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('adjustment-amount')), '150');
    await tester.ensureVisible(find.byKey(const ValueKey('adjustment-notes')));
    await tester.enterText(
        find.byKey(const ValueKey('adjustment-notes')), 'صيانة');
    tester.view.viewInsets = const FakeViewPadding(bottom: 220);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('تأكيد التسجيل'));
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pumpAndSettle();
    expect(api.requests.single['confirm_over_balance'], true);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'returning from subscriber preserves the search and avoids another request',
      (tester) async {
    final api = CollectorWorkflowApi();
    final store = MemoryStore()
      ..state = {'token': 'test-token', 'user': api.user};
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الدفعات'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '202600042');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    final requests = api.searches.length;
    await tester.tap(find.text('عميل تجربة التحصيل'));
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,
        '202600042');
    expect(api.searches.length, requests);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('minimum charge uses the current subscription amount',
      (tester) async {
    final api = AdjustmentApi()
      ..permissionDetails = {'add_charge': true}
      ..subscriptionDetails = {'minimum_payment': '25.00'};
    await openAction(tester, api, 'تحميل الحد الأدنى');
    final field = tester
        .widget<TextFormField>(find.byKey(const ValueKey('adjustment-amount')));
    expect(field.controller!.text, '25.00');
    expect(field.enabled, false);
    await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pumpAndSettle();
    expect(api.requests.single['type'], 'minimum_charge');
    expect(api.requests.single['amount'], '25.00');
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'Arabic amounts are accepted and keyboard back preserves the form',
      (tester) async {
    final api = AdjustmentApi()..permissionDetails = {'add_charge': true};
    await openAction(tester, api, 'تحميل رسوم اشتراك');
    await tester.enterText(
        find.byKey(const ValueKey('adjustment-amount')), '٢٥٫٥٠');
    tester.view.viewInsets = const FakeViewPadding(bottom: 220);
    addTearDown(tester.view.resetViewInsets);
    await tester.pump();
    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(find.text('مغادرة النموذج؟'), findsNothing);
    expect(find.byType(AdjustmentPage), findsOneWidget);
    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('adjustment-submit')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pumpAndSettle();
    expect(api.requests.single['amount'], '25.50');
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a validation error keeps the fields editable for correction',
      (tester) async {
    final api = AdjustmentApi()
      ..permissionDetails = {'add_discount': true}
      ..failure = 422;
    await openAction(tester, api, 'إضافة خصم');
    await review(tester);
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pumpAndSettle();
    expect(find.text('تعذر التسجيل'), findsOneWidget);
    expect(
        tester
            .widget<TextFormField>(
                find.byKey(const ValueKey('adjustment-amount')))
            .enabled,
        true);
    api.failure = null;
    await review(tester, amount: '20');
    await tester.tap(find.text('تأكيد التسجيل'));
    await tester.pumpAndSettle();
    expect(api.requests.last['value'], '20');
    expect(api.requests.last['mobile_operation_id'],
        isNot(api.requests.first['mobile_operation_id']));
    await tester.pumpWidget(const SizedBox());
  });
}
