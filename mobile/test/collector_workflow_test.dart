import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/payment_page.dart';

import 'field_app_test.dart' show MemoryStore;

class CollectorWorkflowApi extends ApiClient {
  final firstAttempt = Completer<void>();
  final paymentRequests = <Map<String, dynamic>>[];
  final searches = <String>[];
  final messages = <Map<String, dynamic>>[];
  Map<String, dynamic>? recordedPayment;
  double get balance => recordedPayment == null ? 100 : 75;
  Map<String, dynamic> get user => {
        'id': 2,
        'username': 'collector',
        'name': 'محصل البنك',
        'branch_name': 'مخيم 2',
        'can_record_collections': true,
        'can_record_readings': false,
        'can_view_subscriptions': true,
        'can_send_messages': true,
        'can_view_messages': true,
        'payment_methods': ['bank_transfer'],
        'transfer_banks': ['بنك فلسطين'],
      };
  Map<String, dynamic> get subscriber => {
        'id': 42,
        'full_name': 'عميل تجربة التحصيل',
        'account_number': '202600042',
        'balance': '$balance',
        'phone': '0599000111'
      };
  @override
  Future<Map<String, dynamic>> me() async => {'user': user};
  @override
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
      {int page = 1}) async {
    searches.add(search);
    return {
      'data': [subscriber],
      'current_page': 1,
      'last_page': 1
    };
  }

  @override
  Future<Map<String, dynamic>> collectionSubscriber(int id) async => {
        'subscription': subscriber,
        'permissions': {
          'record_payment': true,
          'view_history': true,
          'payment_methods': ['bank_transfer']
        },
        'messaging': {
          'can_send': true,
          'can_view_history': true,
          'sms_available': true
        },
        'transactions': [],
        'last_payment': null,
      };
  @override
  Future<Map<String, dynamic>> sendCollection(
      Map<String, dynamic> collection) async {
    paymentRequests.add(Map.from(collection));
    recordedPayment ??= {
      ...collection,
      'id': 1,
      'subscription': subscriber['full_name'],
      'status': 'recorded',
      'voucher_number': 'P-1',
      'amount_in_shekels': '25.00',
      'balance_after': '75.00'
    };
    if (paymentRequests.length == 1) {
      await firstAttempt.future;
      throw const ApiException('Timeout after recording', 0);
    }
    return recordedPayment!;
  }

  @override
  Future<Map<String, dynamic>> collectionsToday() async => {
        'data': [if (recordedPayment != null) recordedPayment!]
      };
  @override
  Future<Map<String, dynamic>> subscriberTransactions(int id,
          {int page = 1}) async =>
      {
        'balance': '$balance',
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {
            'id': 1,
            'date': '2026-10-21',
            'recorded_at': '2026-10-21 12:00',
            'type': 'payment',
            'type_label': 'دفعة',
            'description': 'حوالة سير العمل',
            'amount': '25.00',
            'is_credit': true,
            'is_cancelled': false,
            'reference_number': 'BANK-25',
            'voucher_number': 'P-1'
          }
        ],
      };
  @override
  Future<Map<String, dynamic>> sendSubscriberMessage(
      int id, Map<String, dynamic> message) async {
    messages.add(Map.from(message));
    return {'id': 1, 'status': 'sent'};
  }

  @override
  Future<Map<String, dynamic>> subscriberMessageHistory(int id,
          {int page = 1}) async =>
      {
        'messages': {
          'current_page': 1,
          'last_page': 1,
          'data': [
            {
              'id': 1,
              'kind': 'custom',
              'status': 'sent',
              'channel': 'sms',
              'phone': '0599000111',
              'body': messages.single['body'],
              'createdBy': 'محصل البنك',
              'createdAt': '2026-10-21T09:00:00Z'
            }
          ]
        },
      };
}

void main() {
  testWidgets(
      'collector searches, records a bank payment once through a timeout, reviews history and messages',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    Future<void> reveal(Finder target) async {
      await tester.ensureVisible(target);
      await tester.pumpAndSettle();
    }

    final api = CollectorWorkflowApi();
    final store = MemoryStore()
      ..state = {'token': 'test-token', 'user': api.user};
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.text('الإرسال'), findsNothing);
    expect(find.text('إدخال القراءات'), findsNothing);
    await tester.tap(find.text('تسجيل الدفعات'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '202600042');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(api.searches.last, '202600042');
    await tester.tap(find.text('عميل تجربة التحصيل'));
    await tester.pumpAndSettle();
    final record = find.byKey(const ValueKey('subscriber-record-payment'));
    await reveal(record);
    await tester.tap(record);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('payment-method-cash')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('payment-amount')));
    await tester.pumpAndSettle();
    for (final digit in ['2', '5']) {
      await tester.tap(find.byKey(ValueKey('payment-key-$digit')));
      await tester.pump();
    }
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(PaymentPage), findsOneWidget);
    final bank = find.byKey(const ValueKey('payment-bank-بنك فلسطين'));
    await reveal(bank);
    await tester.tap(bank);
    await tester.pumpAndSettle();
    final reference = find.byKey(const ValueKey('payment-reference'));
    await reveal(reference);
    await tester.enterText(reference, 'BANK-25');
    final confirmation = find.byKey(const ValueKey('payment-confirm'));
    await reveal(confirmation);
    await tester.tap(confirmation);
    await tester.pumpAndSettle();
    final submit = find.byKey(const ValueKey('payment-submit'));
    await tester.tap(submit);
    await tester.pump();
    await tester.tap(submit);
    expect(api.paymentRequests, hasLength(1));
    api.firstAttempt.complete();
    await tester.pumpAndSettle();
    expect(find.textContaining('تعذر تأكيد تسجيل الدفعة'), findsOneWidget);
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(find.text('تم تسجيل الدفعة'), findsOneWidget);
    expect(api.paymentRequests, hasLength(2));
    expect(api.paymentRequests.first, api.paymentRequests.last);
    expect(api.paymentRequests.first['payment_method'], 'bank_transfer');
    expect(store.queuedReadings, isEmpty);
    await tester.tap(find.text('العودة إلى صفحة المشترك'));
    await tester.pumpAndSettle();
    final history = find.byKey(const ValueKey('subscriber-financial-history'));
    await reveal(history);
    await tester.tap(history);
    await tester.pumpAndSettle();
    expect(find.text('حوالة سير العمل'), findsOneWidget);
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    final send = find.byKey(const ValueKey('subscriber-messaging'));
    await reveal(send);
    await tester.tap(send);
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('subscriber-message-body')),
        'تم تسجيل دفعتك');
    await reveal(find.byKey(const ValueKey('subscriber-send-message')));
    await tester.tap(find.byKey(const ValueKey('subscriber-send-message')));
    await tester.pumpAndSettle();
    expect(api.messages, hasLength(1));
    expect(find.text('أُرسلت الرسالة عبر خدمة SMS.'), findsOneWidget);
    await tester.tap(find.text('العودة إلى المشترك'));
    await tester.pumpAndSettle();
    final messages = find.byKey(const ValueKey('subscriber-message-history'));
    await reveal(messages);
    await tester.tap(messages);
    await tester.pumpAndSettle();
    expect(find.text('تم تسجيل دفعتك'), findsOneWidget);
    expect(find.text('رسالة مخصصة'), findsOneWidget);
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,
        '202600042');
    await tester.tap(find.text('الرئيسية'));
    await tester.pumpAndSettle();
    final today = find.byKey(const ValueKey('collector-today-payments'));
    await reveal(today);
    await tester.tap(today);
    await tester.pumpAndSettle();
    expect(find.text('نقدًا اليوم'), findsNothing);
    expect(find.text('تحويلات اليوم'), findsOneWidget);
    expect(find.text('عميل تجربة التحصيل'), findsOneWidget);
    expect(api.balance, 75);
    await tester.pumpWidget(const SizedBox());
  });
}
