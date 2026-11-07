import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/field_store.dart';
import 'package:power_collect/main.dart';

class SessionApi extends ApiClient {
  bool? remembered;
  @override
  Future<Map<String, dynamic>> login(String username, String password,
      {bool remember = false}) async {
    remembered = remember;
    token = 'session-secret';
    return {
      'token': token,
      'user': {'id': 7, 'username': username, 'name': 'Reader'}
    };
  }

  @override
  Future<Map<String, dynamic>> me() async =>
      throw const ApiException('Offline', 0);
}

class SessionMemoryStore extends FieldStore {
  @override
  Future<void> load() async {}
  @override
  Future<void> save() async {}
}

class RevokedSessionApi extends SessionApi {
  bool revoked = false;
  @override
  Future<Map<String, dynamic>> me() async {
    if (revoked) throw const ApiException('Session replaced', 401);
    return {'user': <String, dynamic>{}};
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late Directory directory;
  String? vault;
  var failWrites = false;
  setUp(() async {
    directory = await Directory.systemTemp.createTemp('remember-session-');
    vault = null;
    failWrites = false;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('powercollect.session'),
            (call) async {
      if (call.method == 'read') return vault;
      if (call.method == 'write') {
        if (failWrites) throw PlatformException(code: 'SESSION_STORAGE');
        vault = call.arguments as String;
      }
      if (call.method == 'clear') vault = null;
      return null;
    });
  });
  tearDown(() async => directory.delete(recursive: true));

  testWidgets('remember me help opens only when its information icon is tapped',
      (tester) async {
    const explanation =
        'ابقَ مسجّل الدخول على هذا الجهاز لمدة تصل إلى 30 يومًا.';
    await tester.pumpWidget(PowerCollectApp(
        apiClient: SessionApi(), fieldStore: SessionMemoryStore()));
    await tester.pumpAndSettle();
    expect(find.text('تذكرني'), findsOneWidget);
    expect(find.text(explanation), findsNothing);

    await tester.tap(find.byTooltip('معلومات تذكرني'));
    await tester.pumpAndSettle();

    expect(find.text(explanation), findsOneWidget);
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,
        false);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text(explanation), findsNothing);
    await tester.tap(find.text('تذكرني'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,
        true);
    await tester.pumpWidget(const SizedBox());
  });

  test(
      'remembered credentials live in the secure vault and never in offline JSON',
      () async {
    final store = FieldStore(directory: directory);
    await store.saveSession({'id': 7, 'username': 'reader'}, 'secret-token',
        remember: true);
    expect(vault, 'secret-token');
    final file =
        await File('${directory.path}/field_state.json').readAsString();
    expect(file, isNot(contains('secret-token')));
    expect(jsonDecode(file).containsKey('token'), false);
    final restored = FieldStore(directory: directory);
    await restored.load();
    expect(restored.token, 'secret-token');
    expect(restored.remembersSession, true);
    restored.state.clear();
    await restored.save();
    expect(vault, isNull);
  });

  test(
      'upgrading a plaintext session migrates the token without losing queued readings',
      () async {
    await File('${directory.path}/field_state.json').writeAsString(jsonEncode({
      'token': 'old-secret',
      'user': {'id': 7},
      'queued_readings': [
        {'mobile_operation_id': 'unsent'}
      ],
    }));
    final store = FieldStore(directory: directory);
    await store.load();
    expect(vault, 'old-secret');
    expect(store.queuedReadings.single['mobile_operation_id'], 'unsent');
    expect(store.remembersSession, true);
    expect(await File('${directory.path}/field_state.json').readAsString(),
        isNot(contains('old-secret')));
  });

  test(
      'secure storage failure does not overwrite the previous account or its queue',
      () async {
    final store = FieldStore(directory: directory);
    await store.saveSession({'id': 7}, 'original', remember: true);
    failWrites = true;
    await expectLater(store.saveSession({'id': 8}, 'replacement'),
        throwsA(isA<PlatformException>()));
    expect(store.user?['id'], 7);
    expect(store.token, 'original');
    expect(vault, 'original');
  });

  test('a file write failure restores the prior secure token and account',
      () async {
    final store = FieldStore(directory: directory);
    await store.saveSession({'id': 7}, 'original', remember: true);
    await Directory('${directory.path}/field_state.json.tmp').create();
    await expectLater(store.saveSession({'id': 8}, 'replacement'),
        throwsA(isA<FileSystemException>()));
    expect(store.user?['id'], 7);
    expect(store.token, 'original');
    expect(vault, 'original');
  });

  testWidgets(
      'a replaced session returns to login from a detail page and keeps pending readings',
      (tester) async {
    final store = SessionMemoryStore();
    await store.saveSession(
        {'id': 7, 'username': 'reader', 'name': 'Reader'}, 'old-token',
        remember: true);
    store.state['queued_readings'] = [
      {'subscription_id': 8, 'mobile_operation_id': 'unsent-reading'}
    ];
    final api = RevokedSessionApi();
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    final navigator =
        tester.state<NavigatorState>(find.byType(Navigator).first);
    navigator.push(MaterialPageRoute<void>(
        builder: (_) => const Scaffold(body: Text('Private details'))));
    await tester.pumpAndSettle();
    api.revoked = true;
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(find.text('Private details'), findsNothing);
    expect(find.text('تسجيل الدخول'), findsOneWidget);
    expect(
        store.queuedReadings.single['mobile_operation_id'], 'unsent-reading');
    expect(store.remembersSession, false);
    expect(api.token, isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'a temporary session asks for login after restart and remember me is submitted explicitly',
      (tester) async {
    final store = SessionMemoryStore();
    await store.saveSession({'id': 7, 'username': 'reader'}, 'temporary',
        remember: false);
    final api = SessionApi();
    await tester.pumpWidget(PowerCollectApp(apiClient: api, fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.text('تسجيل الدخول'), findsOneWidget);
    expect(api.token, 'temporary');
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,
        false);
    await tester.tap(find.text('تذكرني'));
    await tester.enterText(find.byType(TextField).at(0), 'reader');
    await tester.enterText(find.byType(TextField).at(1), 'password');
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('تسجيل الدخول'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الدخول'));
    await tester.pumpAndSettle();
    expect(api.remembered, true);
    expect(store.remembersSession, true);
    expect(store.token, 'session-secret');
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
      'a remembered session restores the account without a password prompt',
      (tester) async {
    final store = SessionMemoryStore();
    await store.saveSession(
        {'id': 7, 'username': 'reader', 'name': 'Reader'}, 'remembered',
        remember: true);
    await tester.pumpWidget(
        PowerCollectApp(apiClient: SessionApi(), fieldStore: store));
    await tester.pumpAndSettle();
    expect(find.text('تسجيل الدخول'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
