import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/message_subscribers_page.dart';
import 'package:power_collect/main.dart';
import 'package:power_collect/subscriber_message_page.dart';

import 'field_app_test.dart' show CollectorApi, MemoryStore;

Future<void> openViewer(WidgetTester tester, MessageApi api) async {
  final store = MemoryStore();
  store.state = {
    'token': 'test-token',
    'user': {'id': 2, 'username': 'messages', 'name': 'موظف الرسائل'}
  };
  await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
  await tester.pumpAndSettle();
}

class MessageApi extends CollectorApi {
  bool allowed = true;
  bool viewOnly = false;
  final searches = <String>[];

  @override
  Future<Map<String, dynamic>> me() async => {
        'user': {
          'id': 2,
          'name': 'موظف الرسائل',
          'username': 'messages',
          'can_send_messages': allowed && !viewOnly,
          'can_view_messages': allowed,
          'can_record_collections': false,
          'can_record_readings': false,
          'can_view_readings': false,
        }
      };

  @override
  Future<Map<String, dynamic>> messageSubscribers(String search,
      {int page = 1}) async {
    searches.add(search);
    if (!allowed) throw const ApiException('Forbidden', 403);
    return {
      'data': [
        {
          'id': 42,
          'full_name': 'أحمد علي',
          'account_number': 'A42',
          'phone': '0599000111'
        },
        {
          'id': 43,
          'full_name': 'بلا هاتف',
          'account_number': 'A43',
          'phone': null
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 2,
      'can_send': !viewOnly,
      'can_view_history': true,
      'sms_available': true
    };
  }
}

void main() {
  testWidgets(
      'view-only message staff get history access while sending stays locked',
      (tester) async {
    await openViewer(tester, MessageApi()..viewOnly = true);
    await tester.tap(find.text('سجل رسائل المشتركين'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أحمد علي'));
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<InkWell>(find.byKey(const ValueKey('subscriber-messaging')))
            .onTap,
        isNull);
    expect(
        tester
            .widget<InkWell>(
                find.byKey(const ValueKey('subscriber-message-history')))
            .onTap,
        isNotNull);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('granted staff open messaging without any collection permission',
      (tester) async {
    final api = MessageApi();
    await openViewer(tester, api);
    expect(find.text('تسجيل الدفعات'), findsNothing);
    await tester.tap(find.text('إرسال الرسائل'));
    await tester.pumpAndSettle();
    expect(find.byType(MessageSubscribersPage), findsOneWidget);
    expect(find.text('أحمد علي'), findsOneWidget);
    final missing = tester.widget<AppRow>(find.ancestor(
        of: find.text('بلا هاتف'), matching: find.byType(AppRow)));
    expect(missing.onTap, isNotNull);
    await tester.enterText(find.byType(TextField), 'احمد');
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    expect(api.searches.last, 'احمد');
    await tester.tap(find.text('أحمد علي'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('subscriber-messaging')));
    await tester.pumpAndSettle();
    expect(find.byType(SubscriberMessagePage), findsOneWidget);
    expect(find.text('إلى 0599000111'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('the home service is hidden without the sending grant',
      (tester) async {
    await openViewer(tester, MessageApi()..allowed = false);
    expect(find.text('إرسال الرسائل'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('revoking permission clears previously displayed recipients',
      (tester) async {
    final api = MessageApi();
    await openViewer(tester, api);
    await tester.tap(find.text('إرسال الرسائل'));
    await tester.pumpAndSettle();
    api.allowed = false;
    await tester.enterText(find.byType(TextField), 'احمد');
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    expect(find.text('أحمد علي'), findsNothing);
    expect(find.text('ليس لديك صلاحية إرسال الرسائل. تواصل مع الإدارة.'),
        findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
