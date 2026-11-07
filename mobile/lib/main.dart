import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'field_store.dart';
import 'field_shell.dart';
import 'login_page.dart';

void main() => runApp(const PowerCollectApp());

class PowerCollectApp extends StatefulWidget {
  const PowerCollectApp({super.key, this.apiClient, this.fieldStore});
  final ApiClient? apiClient;
  final FieldStore? fieldStore;

  @override
  State<PowerCollectApp> createState() => _PowerCollectAppState();
}

class _PowerCollectAppState extends State<PowerCollectApp>
    with WidgetsBindingObserver {
  late final ApiClient api = widget.apiClient ?? ApiClient();
  late final FieldStore store = widget.fieldStore ?? FieldStore();
  bool loading = true;
  Map<String, dynamic>? user;
  String? startupError;
  final navigatorKey = GlobalKey<NavigatorState>();
  ThemeMode appearance = ThemeMode.system;

  bool get isDark =>
      appearance == ThemeMode.dark ||
      (appearance == ThemeMode.system && _systemIsDark);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    api.onUnauthenticated = requireLogin;
    restore();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    api.onUnauthenticated = null;
    super.dispose();
  }

  @override
  void didChangePlatformBrightness() {
    AppIdentity.dark = isDark;
    rebuildEverything();
  }

  bool get _systemIsDark =>
      WidgetsBinding.instance.platformDispatcher.platformBrightness ==
      Brightness.dark;

  Future<void> restore() async {
    try {
      await store.load();
      appearance = ThemeMode.values.firstWhere(
          (mode) => mode.name == store.state['appearance'],
          orElse: () => ThemeMode.system);
      api.token = store.token;
      if (mounted)
        setState(() => user =
            store.remembersSession && store.token != null ? store.user : null);
    } catch (_) {
      startupError = 'تعذر فتح التخزين المحلي. أعد تشغيل التطبيق.';
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> login(String username, String password, bool remember) async {
    if (store.queuedReadings.isNotEmpty &&
        store.user?['username'] != username) {
      throw const ApiException(
          'توجد قراءات غير مرسلة لحساب آخر. اتصل بالإنترنت وزامنها أولًا.', 0);
    }
    final previousToken = api.token;
    final result = await api.login(username, password, remember: remember);
    final nextUser = Map<String, dynamic>.from(result['user'] as Map);
    try {
      await store.saveSession(nextUser, result['token'] as String,
          remember: remember);
    } on PendingReadingsAccountSwitch {
      try {
        await api.logout();
      } on ApiException catch (_) {
        // Restore the original session even if the new token cannot be revoked.
      }
      api.token = previousToken;
      throw const ApiException(
          'توجد قراءات غير مرسلة لحساب آخر. زامنها أولًا.', 0);
    } catch (_) {
      try {
        await api.logout();
      } on ApiException catch (_) {}
      api.token = previousToken;
      throw const ApiException(
          'تعذر حفظ جلسة الدخول بأمان على الجهاز. حاول مجددًا.', 0);
    }
    if (mounted) setState(() => user = nextUser);
  }

  Future<void> logout() async {
    if (store.queuedReadings.isNotEmpty) {
      throw const ApiException(
          'زامن القراءات غير المرسلة قبل تسجيل الخروج.', 0);
    }
    try {
      await api.logout();
    } on ApiException catch (error) {
      // Offline, the session still ends on this phone: the token is erased
      // from it, and the collector signs in again like anyone else.
      if (!error.isNetwork) rethrow;
      api.token = null;
    }
    store.state.clear();
    store.state['appearance'] = appearance.name;
    await store.save();
    if (mounted) setState(() => user = null);
  }

  void requireLogin() {
    if (user == null) return;
    api.token = null;
    store.state['remember_session'] = false;
    unawaited(store.save().catchError((Object _) {}));
    navigatorKey.currentState?.popUntil((route) => route.isFirst);
    if (mounted) setState(() => user = null);
  }

  Future<void> setAppearance(ThemeMode mode) async {
    final previous = store.state['appearance'];
    store.state['appearance'] = mode.name;
    try {
      await store.save();
    } catch (_) {
      if (previous == null) {
        store.state.remove('appearance');
      } else {
        store.state['appearance'] = previous;
      }
      rethrow;
    }
    if (!mounted) return;
    setState(() => appearance = mode);
    AppIdentity.dark = isDark;
    rebuildEverything();
  }

  @override
  Widget build(BuildContext context) {
    AppIdentity.dark = isDark;
    return MaterialApp(
      navigatorKey: navigatorKey,
      title: 'PowerCollect',
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: AppIdentity.theme,
      home: loading
          ? Scaffold(
              body: Center(
                  child: CircularProgressIndicator(color: AppIdentity.brand)))
          : startupError != null
              ? Scaffold(body: Center(child: Text(startupError!)))
              : user == null
                  ? LoginPage(onLogin: login)
                  : FieldShell(
                      api: api,
                      store: store,
                      user: user!,
                      onLogout: logout,
                      appearance: appearance,
                      onAppearanceChanged: setAppearance,
                      onReauthenticate: requireLogin),
    );
  }
}
