import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/subscriber_history_page.dart';
import 'package:power_collect/subscriber_message_page.dart';
import 'package:power_collect/subscriber_message_history_page.dart';
import 'package:power_collect/payment_page.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/split_payment_page.dart';

import 'field_app_test.dart' show MemoryStore;
import 'subscriber_page_test.dart' show SubscriberApi, listed, openSubscriber;

class ServicesApi extends SubscriberApi {
  Map<String, dynamic> messaging = {
    'can_send': true,
    'can_view_history': true,
    'sms_available': true
  };
  bool failOlder = true;
  bool emptyHistory = false;
  bool historyDenied = false;
  String statementBalance = '120.00';
  bool failRefresh = false;
  bool failSend = true;
  bool messageOlderFails = true;
  bool messageHistoryDenied = false;
  bool emptyMessageHistory = false;
  String messageStatus = 'pending';
  Completer<Map<String, dynamic>>? sending;
  final requests = <Map<String, dynamic>>[];

  @override
  Future<Map<String, dynamic>> collectionSubscriber(int id) async =>
      {...await super.collectionSubscriber(id), 'messaging': messaging};

  @override
  Future<Map<String, dynamic>> subscriberMessageHistory(int id,
      {int page = 1}) async {
    if (messageHistoryDenied) throw const ApiException('Forbidden', 403);
    if (page == 2 && messageOlderFails) {
      messageOlderFails = false;
      throw const ApiException('تعذر تحميل الرسائل القديمة', 0);
    }
    return {
      'messages': {
        'data': emptyMessageHistory
            ? []
            : [
                {
                  'id': page,
                  'body':
                      page == 1 ? 'رسالة حديثة للمشترك' : 'تذكير قديم بالرصيد',
                  'kind': page == 1 ? 'custom' : 'balance_reminder',
                  'status': page == 1 ? 'sent' : 'failed',
                  'channel': 'sms',
                  'phone': '0599000111',
                  'createdBy': 'محمد',
                  'sentBy': page == 1 ? 'محمد' : null,
                  'createdAt': '2026-10-20T10:30:00Z',
                  'sentAt': null,
                  'error': page == 1 ? null : 'رفض المزوّد الإرسال',
                }
              ],
        'current_page': page,
        'last_page': emptyMessageHistory ? 1 : 2
      }
    };
  }

  @override
  Future<Map<String, dynamic>> subscriberTransactions(int id,
      {int page = 1}) async {
    expectSync(id, 42);
    if (historyDenied) throw const ApiException('Forbidden', 403);
    if (page == 1 && failRefresh) {
      failRefresh = false;
      throw const ApiException('تعذر تحديث السجل', 0);
    }
    if (page == 2 && failOlder) {
      failOlder = false;
      throw const ApiException('لا يوجد اتصال', 0);
    }
    return {
      'data': emptyHistory
          ? []
          : [
              {
                'id': page,
                'date': '2026-10-01',
                'description': page == 1 ? 'دفعة حديثة' : 'قراءة قديمة',
                'type': page == 1 ? 'payment' : 'meter_reading',
                'type_label': page == 1 ? 'دفعة' : 'قراءة أسبوعية',
                'recorded_at': '2026-10-01 12:30',
                'notes': 'تسديد جزء من المستحق',
                'voucher_number': 'V-123',
                'reference_number': 'REF-456',
                'amount': page == 1 ? '30.00' : '150.00',
                'is_credit': page == 1,
                'is_cancelled': false,
                'balance_after': page == 1 ? '120.00' : '150.00',
              }
            ],
      'current_page': page,
      'last_page': emptyHistory ? 1 : 2,
      'total': emptyHistory ? 0 : 2,
      'balance': statementBalance,
    };
  }

  @override
  Future<Map<String, dynamic>> sendSubscriberMessage(
      int id, Map<String, dynamic> message) async {
    expectSync(id, 42);
    requests.add(Map.from(message));
    if (failSend) {
      failSend = false;
      throw const ApiException('انتهت مهلة الاتصال بالخادم.', 0);
    }
    return sending?.future ??
        Future.value({
          'id': 1,
          'status': messageStatus,
          'error': 'رفض مزود الرسائل الإرسال'
        });
  }
}

class StatementViewerApi extends ServicesApi {
  bool allowed = true;
  final searches = <String>[];

  @override
  Future<Map<String, dynamic>> me() async => {
        'user': {
          'id': 2,
          'name': 'موظف كشوف الحساب',
          'can_view_subscriptions': allowed,
          'can_record_collections': false,
        }
      };

  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
      {int page = 1}) async {
    searches.add(search);
    return {
      'data': [listed],
      'current_page': 1,
      'last_page': 1
    };
  }

  @override
  Future<Map<String, dynamic>> collectionsToday() async =>
      throw StateError('Viewing statements must not require collection access');
}

class CollectorServicesApi extends StatementViewerApi {
  @override
  Future<Map<String, dynamic>> me() async => {
        'user': {
          'id': 2,
          'name': 'محصل',
          'can_view_subscriptions': true,
          'can_record_collections': true,
          'can_record_split_payments': true,
          'can_record_readings': false,
        }
      };

  @override
  Future<Map<String, dynamic>> collectionsToday() async => {'data': []};
}

Future<void> showMessage(WidgetTester tester, ServicesApi api,
    {Size size = const Size(320, 480)}) async {
  await tester.binding.setSurfaceSize(size);
  addTearDown(() => tester.binding.setSurfaceSize(null));
  await tester.pumpWidget(MaterialApp(
      theme: AppIdentity.theme,
      builder: (_, child) =>
          Directionality(textDirection: TextDirection.rtl, child: child!),
      home: SubscriberMessagePage(
          api: api, subscriber: {...listed, 'phone': '0599000111'})));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('back closes the message keyboard and preserves the unsent text',
      (tester) async {
    final api = ServicesApi();
    await openSubscriber(tester, api);
    await tester.tap(find.byKey(const ValueKey('subscriber-messaging')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'رسالة لم ترسل بعد');
    tester.view.viewInsets = const FakeViewPadding(bottom: 200);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberMessagePage), findsOneWidget);
    expect(tester.testTextInput.isVisible, isFalse);
    expect(find.text('رسالة لم ترسل بعد'), findsOneWidget);
    expect(api.requests, isEmpty);
    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberMessagePage), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'collectors reach payments and split transfers through one subscriber search',
      (tester) async {
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {'id': 2}
    };
    final api = CollectorServicesApi()
      ..permissionDetails = {'record_split_payment': true};
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<AppNavBar>(find.byType(AppNavBar))
            .items
            .any((item) => item.id == 'sync'),
        isFalse);
    expect(find.byTooltip('حالة الإرسال'), findsNothing);
    expect(find.byTooltip('تحديث البيانات'), findsOneWidget);
    await tester
        .ensureVisible(find.byKey(const ValueKey('collector-today-payments')));
    await tester.tap(find.byKey(const ValueKey('collector-today-payments')));
    await tester.pumpAndSettle();
    expect(find.text('الدفعات المسجّلة'), findsOneWidget);
    await tester.tap(find.text('الرئيسية'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('collector-split-payment')), findsNothing);
    expect(find.byKey(const ValueKey('subscriber-statements-service')),
        findsNothing);
    expect(find.text('الدفعة والحوالة المقسّمة وكشف الحساب من صفحة المشترك'),
        findsOneWidget);
    await tester
        .ensureVisible(find.byKey(const ValueKey('home-subscriber-search')));
    await tester.tap(find.byKey(const ValueKey('home-subscriber-search')));
    await tester.pumpAndSettle();
    expect(tester.testTextInput.isVisible, isTrue);
    await tester.tap(find.text('أحمد علي'));
    await tester.pumpAndSettle();
    await tester
        .ensureVisible(find.byKey(const ValueKey('subscriber-split-payment')));
    await tester.tap(find.byKey(const ValueKey('subscriber-split-payment')));
    await tester.pumpAndSettle();
    expect(find.byType(SplitPaymentPage), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'statement shows current balance and readable transaction details on small screens',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(320, 568));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final api = ServicesApi()..statementBalance = '-25.00';
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        builder: (_, child) =>
            Directionality(textDirection: TextDirection.rtl, child: child!),
        home: SubscriberHistoryPage(api: api, subscriber: listed)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('statement-current-balance')),
        findsOneWidget);
    expect(find.text('\u2066-25 ₪\u2069 له'), findsOneWidget);
    for (final text in [
      'دفعة',
      '01/10/2026 12:30',
      'دفعة حديثة',
      'ملاحظات: تسديد جزء من المستحق',
      'السند: V-123',
      'المرجع: REF-456',
      '30.00 ₪'
    ]) {
      await tester.ensureVisible(find.text(text));
      expect(find.text(text), findsOneWidget);
    }
    expect(find.textContaining('الرصيد بعدها'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'view-only staff reach subscriber statements without collection access',
      (tester) async {
    final api = StatementViewerApi()
      ..permissionDetails = {
        'view_history': true,
        'record_payment': false,
        'record_split_payment': false,
        'payment_methods': <String>[],
      };
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {'id': 2}
    };
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
        find.byKey(const ValueKey('subscriber-statements-service')));
    await tester
        .tap(find.byKey(const ValueKey('subscriber-statements-service')));
    await tester.pumpAndSettle();
    expect(find.textContaining('دفعاتي اليوم'), findsNothing);
    await tester.enterText(find.byType(TextField), 'أحمد');
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    expect(api.searches.last, 'أحمد');
    await tester.tap(find.text('أحمد علي'));
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<AppAction>(
                find.byKey(const ValueKey('subscriber-record-payment')))
            .onPressed,
        isNull);
    await tester
        .tap(find.byKey(const ValueKey('subscriber-financial-history')));
    await tester.pumpAndSettle();
    expect(find.text('دفعة حديثة'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'statement service is hidden without subscription view permission',
      (tester) async {
    final store = MemoryStore();
    store.state = {
      'token': 'test-token',
      'user': {'id': 2}
    };
    await tester.pumpWidget(PowerCollectApp(
        apiClient: StatementViewerApi()..allowed = false, fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('subscriber-statements-service')),
        findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'revoked statement access clears previously loaded financial lines',
      (tester) async {
    final api = ServicesApi();
    await openSubscriber(tester, api);
    await tester
        .tap(find.byKey(const ValueKey('subscriber-financial-history')));
    await tester.pumpAndSettle();
    expect(find.text('دفعة حديثة'), findsOneWidget);
    api.historyDenied = true;
    unawaited(tester
        .state<RefreshIndicatorState>(find.byType(RefreshIndicator))
        .show());
    await tester.pumpAndSettle();
    expect(find.text('دفعة حديثة'), findsNothing);
    expect(find.text('Forbidden'), findsOneWidget);
  });

  testWidgets(
      'message cards share one row and history remains available without SMS or a phone',
      (tester) async {
    final api = ServicesApi()
      ..messaging = {
        'can_send': true,
        'can_view_history': true,
        'sms_available': false
      }
      ..subscriptionDetails = {'phone': ''};
    await openSubscriber(tester, api);
    final send = find.byKey(const ValueKey('subscriber-messaging'));
    final history = find.byKey(const ValueKey('subscriber-message-history'));
    expect(tester.widget<InkWell>(send).onTap, isNull);
    expect(tester.widget<InkWell>(history).onTap, isNotNull);
    expect(tester.getRect(send).top, tester.getRect(history).top);
    expect(tester.getRect(send).width, tester.getRect(history).width);
    await tester.ensureVisible(history);
    await tester.tap(history);
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberMessageHistoryPage), findsOneWidget);
    expect(find.text('رسالة حديثة للمشترك'), findsOneWidget);
    expect(find.text('رسالة مخصصة'), findsOneWidget);
    expect(find.text('مرسلة'), findsOneWidget);
    await tester.ensureVisible(find.text('عرض رسائل أقدم'));
    await tester.tap(find.text('عرض رسائل أقدم'));
    await tester.pumpAndSettle();
    expect(find.text('تعذر تحميل الرسائل القديمة'), findsOneWidget);
    expect(find.text('رسالة حديثة للمشترك'), findsOneWidget);
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('تذكير قديم بالرصيد'), findsOneWidget);
    expect(find.text('تذكير بالرصيد'), findsOneWidget);
    expect(api.requests, isEmpty);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'history is locked without its grant and clears cached messages after revocation',
      (tester) async {
    final api = ServicesApi()
      ..messaging = {
        'can_send': true,
        'can_view_history': false,
        'sms_available': true
      };
    await openSubscriber(tester, api);
    final history = find.byKey(const ValueKey('subscriber-message-history'));
    expect(tester.widget<InkWell>(history).onTap, isNull);
    expect(find.text('تحتاج صلاحية عرض الرسائل من الإدارة'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    api.messaging['can_view_history'] = true;
    await openSubscriber(tester, api);
    await tester.ensureVisible(history);
    await tester.tap(history);
    await tester.pumpAndSettle();
    api.messageHistoryDenied = true;
    unawaited(tester
        .state<RefreshIndicatorState>(find.byType(RefreshIndicator))
        .show());
    await tester.pumpAndSettle();
    expect(find.text('رسالة حديثة للمشترك'), findsNothing);
    expect(find.text('ليس لديك صلاحية عرض الرسائل. تواصل مع الإدارة.'),
        findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('message history has an empty state without sending anything',
      (tester) async {
    final api = ServicesApi()..emptyMessageHistory = true;
    await openSubscriber(tester, api);
    final history = find.byKey(const ValueKey('subscriber-message-history'));
    await tester.ensureVisible(history);
    await tester.tap(history);
    await tester.pumpAndSettle();
    expect(find.text('لا توجد رسائل سابقة لهذا المشترك'), findsOneWidget);
    expect(api.requests, isEmpty);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets(
      'server permissions lock financial history and limit payment methods',
      (tester) async {
    final api = ServicesApi()
      ..permissionDetails = {
        'view_history': false,
        'record_payment': true,
        'payment_methods': ['cash'],
      };
    await openSubscriber(tester, api);
    final history = tester.widget<AppRow>(
        find.byKey(const ValueKey('subscriber-financial-history')));
    expect(history.onTap, isNull);
    expect(find.text('تحتاج صلاحية عرض بيانات المشترك من الإدارة'),
        findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('subscriber-record-payment')));
    await tester.pumpAndSettle();
    expect(find.byType(PaymentPage), findsOneWidget);
    await tester.scrollUntilVisible(find.text('طريقة الدفع'), 150,
        scrollable: find.byType(Scrollable).first);
    expect(find.byKey(const ValueKey('payment-method-cash')), findsOneWidget);
    expect(find.byKey(const ValueKey('payment-method-bank_transfer')),
        findsNothing);
  });

  testWidgets(
      'details opens full history and retries older entries without losing loaded lines',
      (tester) async {
    final api = ServicesApi();
    await openSubscriber(tester, api);
    await tester
        .tap(find.byKey(const ValueKey('subscriber-financial-history')));
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberHistoryPage), findsOneWidget);
    expect(find.text('دفعة حديثة'), findsOneWidget);
    await tester.tap(find.text('عرض حركات أقدم'));
    await tester.pumpAndSettle();
    expect(find.text('لا يوجد اتصال'), findsOneWidget);
    expect(find.text('دفعة حديثة'), findsOneWidget);
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('قراءة قديمة'), findsOneWidget);
    expect(find.text('دفعة حديثة'), findsOneWidget);
    expect(find.text('عرض حركات أقدم'), findsNothing);
    api.failRefresh = true;
    unawaited(tester
        .state<RefreshIndicatorState>(find.byType(RefreshIndicator))
        .show());
    await tester.pumpAndSettle();
    expect(find.text('تعذر تحديث السجل'), findsOneWidget);
    await tester
        .ensureVisible(find.widgetWithText(TextButton, 'إعادة المحاولة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('دفعة حديثة'), findsOneWidget);
    expect(find.text('قراءة قديمة'), findsNothing);
    expect(find.text('عرض حركات أقدم'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('an empty ledger has a useful empty state', (tester) async {
    final api = ServicesApi()..emptyHistory = true;
    await openSubscriber(tester, api);
    await tester
        .tap(find.byKey(const ValueKey('subscriber-financial-history')));
    await tester.pumpAndSettle();
    expect(find.text('لا توجد حركات بعد'), findsOneWidget);
    expect(find.text('عرض حركات أقدم'), findsNothing);
  });

  testWidgets(
      'the message action clearly explains permission, phone and service availability',
      (tester) async {
    for (final (permission, available, phone, explanation) in [
      (false, true, '0599000111', 'تحتاج صلاحية إرسال الرسائل من الإدارة'),
      (true, true, '', 'لا يوجد رقم هاتف لهذا المشترك'),
      (true, false, '0599000111', 'خدمة SMS غير مفعّلة؛ تواصل مع الإدارة'),
    ]) {
      final api = ServicesApi()
        ..messaging = {'can_send': permission, 'sms_available': available}
        ..subscriptionDetails = {'phone': phone};
      await openSubscriber(tester, api);
      expect(find.text(explanation), findsOneWidget);
      final row = tester
          .widget<InkWell>(find.byKey(const ValueKey('subscriber-messaging')));
      expect(row.onTap, isNull);
      await tester.pumpWidget(const SizedBox());
    }
  });

  testWidgets(
      'details opens the SMS composer without automatically opening the keyboard',
      (tester) async {
    await openSubscriber(tester, ServicesApi());
    await tester.tap(find.byKey(const ValueKey('subscriber-messaging')));
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberMessagePage), findsOneWidget);
    expect(find.text('إلى 0599000111'), findsOneWidget);
    expect(tester.testTextInput.isVisible, isFalse);
    expect(
        tester
            .widget<AppAction>(
                find.byKey(const ValueKey('subscriber-send-message')))
            .onPressed,
        isNull);
  });

  testWidgets(
      'a lost SMS response retries the same operation and shows queued rather than sent',
      (tester) async {
    final api = ServicesApi();
    await showMessage(tester, api);
    final input = find.byKey(const ValueKey('subscriber-message-body'));
    final send = find.byKey(const ValueKey('subscriber-send-message'));
    await tester.enterText(input, 'مرحباً أحمد');
    await tester.pump();
    await tester.ensureVisible(send);
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(input).readOnly, isTrue);
    expect(find.text('التحقق وإعادة المحاولة'), findsOneWidget);
    await tester.ensureVisible(send);
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();
    expect(api.requests, hasLength(2));
    expect(api.requests[0], api.requests[1]);
    expect(api.requests.first['body'], 'مرحباً أحمد');
    expect(find.text('حُفظت الرسالة وهي بانتظار الإرسال عبر خدمة SMS.'),
        findsOneWidget);
    expect(find.text('أُرسلت الرسالة عبر خدمة SMS.'), findsNothing);
    expect(find.byKey(const ValueKey('subscriber-send-message')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'busy sending blocks duplicate taps and provider failure stays visible',
      (tester) async {
    final api = ServicesApi()
      ..failSend = false
      ..sending = Completer<Map<String, dynamic>>();
    await showMessage(tester, api, size: const Size(640, 360));
    await tester.enterText(find.byType(TextField), 'رسالة للمشترك');
    await tester.pump();
    final send = find.byKey(const ValueKey('subscriber-send-message'));
    await tester.ensureVisible(send);
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pump();
    await tester.ensureVisible(send);
    await tester.pump();
    await tester.tap(send);
    await tester.pump();
    expect(api.requests, hasLength(1));
    api.sending!.complete(
        {'id': 1, 'status': 'failed', 'error': 'رفض مزود الرسائل الإرسال'});
    await tester.pumpAndSettle();
    expect(find.text('رفض مزود الرسائل الإرسال'), findsOneWidget);
    expect(find.text('أُرسلت الرسالة عبر خدمة SMS.'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
