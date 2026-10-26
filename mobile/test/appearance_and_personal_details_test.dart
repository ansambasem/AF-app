import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/main.dart';

import 'field_app_test.dart' show MemoryStore, OfflineApi;
import 'subscriber_page_test.dart' show SubscriberApi, openSubscriber;

class AppearanceApi extends OfflineApi {
  @override
  Future<void> logout() async {
    token = null;
  }
}

class NoInternetApi extends OfflineApi {
  @override
  Future<void> logout() async => throw const ApiException(
      'لا يوجد اتصال بالإنترنت. تحقّق من الاتصال وحاول مجددًا.', 0);
}

class FailingAppearanceStore extends MemoryStore {
  @override
  Future<void> save() async => throw StateError('disk full');
}

void main() {
  final user = {'id': 2, 'name': 'محصل', 'username': 'collector'};

  Future<void> openAccount(WidgetTester tester, MemoryStore store) async {
    await tester.pumpWidget(
        PowerCollectApp(apiClient: AppearanceApi(), fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حسابي'));
    await tester.pumpAndSettle();
  }

  Future<void> choose(WidgetTester tester, String value) async {
    final option = find.byKey(ValueKey('appearance-$value'));
    await tester.ensureVisible(option);
    await tester.tap(option);
    await tester.pumpAndSettle();
  }

  testWidgets(
      'light and dark choices persist across restarts and override the phone',
      (tester) async {
    addTearDown(() {
      tester.platformDispatcher.clearPlatformBrightnessTestValue();
      AppIdentity.dark = false;
    });
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    final store = MemoryStore()..state = {'token': 'test-token', 'user': user};
    await openAccount(tester, store);
    await choose(tester, 'dark');
    expect(store.state['appearance'], 'dark');
    expect(
        Theme.of(tester.element(find.byKey(const ValueKey('appearance-dark'))))
            .brightness,
        Brightness.dark);
    await tester.pumpWidget(const SizedBox());
    await openAccount(tester, store);
    expect(AppIdentity.dark, true);
    await choose(tester, 'light');
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    await tester.pumpAndSettle();
    expect(AppIdentity.dark, false);
    expect(store.state['appearance'], 'light');
    await choose(tester, 'system');
    expect(AppIdentity.dark, true);
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    await tester.pumpAndSettle();
    expect(AppIdentity.dark, false);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('appearance survives logout while session data is cleared',
      (tester) async {
    addTearDown(() => AppIdentity.dark = false);
    final store = MemoryStore()
      ..state = {'token': 'test-token', 'user': user, 'appearance': 'dark'};
    await openAccount(tester, store);
    final logout = find.text('تسجيل الخروج');
    await tester.ensureVisible(logout);
    await tester.tap(logout);
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الخروج').last);
    await tester.pumpAndSettle();
    expect(store.token, isNull);
    expect(store.user, isNull);
    expect(store.state['appearance'], 'dark');
    expect(AppIdentity.dark, true);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'signing out without internet still ends the session on the phone and asks for a normal sign-in',
      (tester) async {
    final api = NoInternetApi()..token = 'test-token';
    final store = MemoryStore()
      ..state = {'token': 'test-token', 'user': user, 'remember_session': true};
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حسابي'));
    await tester.pumpAndSettle();
    final logout = find.text('تسجيل الخروج');
    await tester.ensureVisible(logout);
    await tester.tap(logout);
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الخروج').last);
    await tester.pumpAndSettle();

    expect(api.token, isNull);
    expect(store.token, isNull);
    expect(store.user, isNull);
    expect(find.text('تطبيق الميدان'), findsOneWidget);
    expect(find.text('سجّل الدخول للبدء'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a failed appearance save keeps the previous choice',
      (tester) async {
    addTearDown(() => AppIdentity.dark = false);
    final store = FailingAppearanceStore()
      ..state = {'token': 'test-token', 'user': user, 'appearance': 'light'};
    await openAccount(tester, store);
    await choose(tester, 'dark');
    expect(store.state['appearance'], 'light');
    expect(AppIdentity.dark, false);
    expect(find.text('تعذر حفظ المظهر. أعد المحاولة.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'personal information is grouped once and a long press copies the exact phone',
      (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData')
        copied = (call.arguments as Map)['text'] as String;
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, null));
    final api = SubscriberApi()
      ..subscriptionDetails = {
        'subscriber_name': 'صاحب الحساب',
        'subscription_name': 'متجر الكهرباء',
        'national_id': '123456789',
        'personal_phone': '0599111222',
        'area_name': 'منطقة الفرع',
        'sub_area_name': 'الحي الشرقي',
      };
    await openSubscriber(tester, api);
    final personal = find.byKey(const ValueKey('subscriber-personal-details'));
    final subscription =
        find.byKey(const ValueKey('subscriber-subscription-details'));
    for (final text in ['صاحب الحساب', '123456789', '0599111222']) {
      expect(find.descendant(of: personal, matching: find.text(text)),
          findsOneWidget);
      expect(find.descendant(of: subscription, matching: find.text(text)),
          findsNothing);
    }
    expect(find.text('صاحب الحساب'), findsOneWidget);
    expect(find.text('متجر الكهرباء'), findsOneWidget);
    final phone = find.byKey(const ValueKey('subscriber-phone-0599111222'));
    await tester.ensureVisible(phone);
    await tester.pumpAndSettle();
    await tester.tap(phone);
    await tester.pump();
    expect(copied, isNull);
    await tester.longPress(phone);
    await tester.pumpAndSettle();
    expect(copied, '0599111222');
    expect(find.text('تم نسخ رقم الهاتف.'), findsOneWidget);
    await tester.ensureVisible(find.text('الحي الشرقي'));
    expect(find.text('المنطقة 2'), findsOneWidget);
    expect(find.text('منطقة الفرع'), findsNothing);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a clipboard error keeps the subscriber page usable',
      (tester) async {
    tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData')
        throw PlatformException(code: 'clipboard-unavailable');
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, null));
    await openSubscriber(tester, SubscriberApi());
    final phone = find.byKey(const ValueKey('subscriber-phone-0599000111'));
    await tester.ensureVisible(phone);
    await tester.pumpAndSettle();
    await tester.longPress(phone);
    await tester.pumpAndSettle();
    expect(find.text('تعذر نسخ رقم الهاتف. أعد المحاولة.'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });
}
