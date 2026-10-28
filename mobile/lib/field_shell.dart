import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'cash_boxes_page.dart';
import 'development_credits.dart';
import 'collection_view.dart';
import 'payment_page.dart';
import 'subscriber_page.dart';
import 'field_store.dart';
import 'queued_readings.dart';
import 'reading_flow.dart';
import 'weekly_readings_page.dart';
import 'reading_distribution_page.dart';
import 'reading_assignments.dart';
import 'message_subscribers_page.dart';

enum FieldSection { home, readings, collections, sync, account }

class FieldShell extends StatefulWidget {
  const FieldShell(
      {required this.api,
      required this.store,
      required this.user,
      required this.onLogout,
      required this.onReauthenticate,
      this.appearance = ThemeMode.system,
      this.onAppearanceChanged,
      super.key});
  final ApiClient api;
  final FieldStore store;
  final Map<String, dynamic> user;
  final Future<void> Function() onLogout;
  final VoidCallback onReauthenticate;
  final ThemeMode appearance;
  final Future<void> Function(ThemeMode)? onAppearanceChanged;
  @override
  State<FieldShell> createState() => _FieldShellState();
}

class _FieldShellState extends State<FieldShell> with WidgetsBindingObserver {
  late Map<String, dynamic> currentUser = widget.user;
  FieldSection section = FieldSection.home;
  bool online = false;
  bool syncing = false;
  bool uploadingReadings = false;
  Future<void>? synchronization;
  bool rosterRefreshRequested = false;
  String? message;
  Timer? timer;
  final collectionSearch = TextEditingController();
  List<Map<String, dynamic>> collectionResults = [];
  List<Map<String, dynamic>> today = [];
  bool collectionBusy = false;
  Timer? collectionDebounce;
  int collectionRequest = 0;
  int collectionPage = 0;
  int collectionLastPage = 1;
  String? collectionError;
  bool requiresLogin = false;
  bool readingFocus = false;
  int? focusSubscriberId;

  bool get canRead => currentUser['can_record_readings'] == true;
  bool get canCollect => currentUser['can_record_collections'] == true;
  List<String> get paymentMethods => currentUser['payment_methods'] is List
      ? List<String>.from(currentUser['payment_methods'] as List)
      : const [];
  String get collectionIcon =>
      paymentMethods.contains('cash') ? 'cash' : 'bank';
  bool get canViewSubscriptions =>
      currentUser['can_view_subscriptions'] == true;
  bool get canBrowseSubscribers =>
      canCollect ||
      canViewSubscriptions ||
      currentUser['can_adjust_balances'] == true;
  bool get canRecordSplitPayments =>
      canCollect && currentUser['can_record_split_payments'] == true;

  /// The box types the user's payments go into: cash, bank transfers and
  /// clearings, each by its own permission.
  Set<String> get ownBoxTypes => {
        if (canCollect && paymentMethods.contains('cash')) 'cash',
        if (canCollect && paymentMethods.contains('bank_transfer'))
          'bank_transfer',
        if (currentUser['can_add_clearings'] == true) 'clearing',
      };
  bool get canViewOwnBoxes => ownBoxTypes.isNotEmpty;
  bool get canUseReadingSync =>
      canRead || widget.store.queuedReadings.isNotEmpty;
  bool collectionShowToday = false;
  bool collectionFocusSearch = false;
  bool get canViewReadings => currentUser['can_view_readings'] == true;
  bool get canDistributeReadings =>
      currentUser['can_distribute_readings'] == true ||
      currentUser['can_approve_reading_assignments'] == true;
  bool get canSendMessages => currentUser['can_send_messages'] == true;
  bool get canViewMessages => currentUser['can_view_messages'] == true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (widget.store.state['assignment_roster_version'] != 1) {
      widget.store.state.remove('subscribers');
      widget.store.state.remove('roster_updated_at');
    }
    timer = Timer.periodic(const Duration(seconds: 30), (_) {
      if ((WidgetsBinding.instance.lifecycleState == null ||
          WidgetsBinding.instance.lifecycleState ==
              AppLifecycleState.resumed)) {
        if (ModalRoute.of(context)?.isCurrent == true) {
          synchronize(forceRoster: section == FieldSection.readings);
        } else {
          final sessionToken = widget.api.token;
          unawaited(widget.api.me().catchError((Object error) {
            if (error is ApiException &&
                error.statusCode == 401 &&
                mounted &&
                widget.api.token == sessionToken) {
              widget.onReauthenticate();
            }
            return <String, dynamic>{};
          }));
        }
      }
    });
    WidgetsBinding.instance
        .addPostFrameCallback((_) => synchronize(forceRoster: true));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) synchronize(forceRoster: true);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    timer?.cancel();
    collectionDebounce?.cancel();
    collectionSearch.dispose();
    super.dispose();
  }

  Future<void> synchronize({bool forceRoster = false}) {
    if (!mounted || uploadingReadings) return Future<void>.value();
    rosterRefreshRequested = rosterRefreshRequested || forceRoster;
    return synchronization ??=
        refreshSession().whenComplete(() => synchronization = null);
  }

  Future<void> refreshSession() async {
    final sessionToken = widget.api.token;
    setState(() => syncing = true);
    try {
      do {
        final forceRoster = rosterRefreshRequested;
        rosterRefreshRequested = false;
        final session = await widget.api.me();
        if (!mounted) return;
        setState(() {
          if (session['user'] is Map &&
              session['user']['id'] == widget.user['id']) {
            currentUser = Map<String, dynamic>.from(session['user'] as Map);
            widget.store.state['user'] = currentUser;
          }
          online = true;
          requiresLogin = false;
        });
        final lastRosterUpdate = DateTime.tryParse(
            '${widget.store.state['roster_updated_at'] ?? ''}');
        final shouldRefreshRoster = canRead &&
            (forceRoster ||
                lastRosterUpdate == null ||
                DateTime.now().difference(lastRosterUpdate) >
                    const Duration(minutes: 5));
        await Future.wait([
          if (shouldRefreshRoster) refreshRoster(),
          if (canCollect) refreshCollections(),
        ]);
        if (mounted) setState(() => message = null);
      } while (mounted && rosterRefreshRequested);
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        if (mounted && widget.api.token == sessionToken)
          widget.onReauthenticate();
        return;
      }
      if (mounted)
        setState(() {
          online = false;
          requiresLogin = error.statusCode == 401;
          message = error.statusCode == 401
              ? 'انتهت جلسة الدخول. سجّل الدخول مجددًا لمتابعة العمل.'
              : error.message;
        });
    } finally {
      if (mounted) setState(() => syncing = false);
    }
  }

  Future<void> refreshRoster() async {
    final all = <Map<String, dynamic>>[];
    Map<String, dynamic>? first;
    var page = 1;
    do {
      final result = await widget.api.rosterPage(page);
      first ??= result;
      all.addAll((result['data'] as List)
          .map((item) => Map<String, dynamic>.from(item as Map)));
      if (page >= (result['last_page'] as int)) break;
      page++;
    } while (true);
    widget.store.state['subscribers'] = all;
    widget.store.state['week_start'] = first['week_start'];
    widget.store.state['week_end'] = first['week_end'];
    for (final key in [
      'assignment_roster_version',
      'next_reading',
      'entry_starts_at',
      'entry_ends_at',
      'reading_date'
    ]) {
      widget.store.state[key] = first[key];
    }
    widget.store.state['can_record_readings_now'] =
        first['can_record_readings_now'];
    widget.store.state['roster_updated_at'] = DateTime.now().toIso8601String();
    await widget.store.save();
    if (mounted) setState(() {});
  }

  Future<ReadingUploadReport> uploadReadings(
      List<Map<String, dynamic>> reviewed) async {
    if (uploadingReadings || syncing || !canRead) {
      return const ReadingUploadReport(
          message: 'تعذّر الرفع الآن. تحقق من الصلاحية وانتظر انتهاء التحديث.',
          interrupted: true);
    }
    setState(() => uploadingReadings = true);
    var sent = 0;
    var held = 0;
    var failed = 0;
    final reviewNotes = <String>[];
    String? interruption;
    try {
      for (final reading in reviewed) {
        if (!mounted) break;
        final id = reading['mobile_operation_id'] as String;
        if (!widget.store.queuedReadings
            .any((item) => item['mobile_operation_id'] == id)) continue;
        final validation = readingValidation(reading);
        if (validation != null) {
          await widget.store.markReadingError(id, validation);
          failed++;
          continue;
        }
        try {
          await widget.store.markReadingUncertain(id);
          final response = await widget.api.sendReading({...reading}
            ..remove('sync_error')
            ..remove('upload_uncertain')
            ..remove('review'));
          final subscribers = widget.store.subscribers;
          for (final subscriber in subscribers) {
            if (subscriber['id'] == reading['subscription_id'] &&
                reading['week_start'] == widget.store.state['week_start']) {
              subscriber['current_reading'] = reading['current_reading'];
              subscriber['reading_status'] =
                  ['needs_review', 'approved'].contains(response['status'])
                      ? response['status']
                      : 'pending';
            }
          }
          widget.store.state['subscribers'] = subscribers;
          await widget.store.removeReading(id);
          sent++;
          if (response['status'] == 'needs_review') {
            held++;
            final review = readingReviewData(reading, subscribers);
            reviewNotes.add(
                '${review['name'] ?? review['full_name'] ?? 'مشترك #${reading['subscription_id']}'}: ${response['message'] ?? 'تحتاج مراجعة المسؤول.'}');
          }
        } on ApiException catch (error) {
          if (error.isNetwork || error.statusCode >= 500) {
            interruption =
                'انقطع الاتصال أو تعذّر معرفة نتيجة الرفع. بقيت القراءات على الجهاز؛ أعد المحاولة بنفسها لتجنب التكرار.';
            if (mounted) setState(() => online = false);
            break;
          }
          if (error.statusCode == 401 || error.statusCode == 403) {
            await widget.store.markReadingUncertain(id, uncertain: false);
            interruption = error.statusCode == 401
                ? 'انتهت جلسة الدخول. سجّل الدخول مجددًا ثم راجع القراءات وارفعها.'
                : 'لم يعد الرفع مسموحًا لحسابك. تواصل مع المسؤول.';
            if (mounted)
              setState(() => requiresLogin = error.statusCode == 401);
            break;
          }
          if (error.statusCode == 426) {
            // The server turned this old app away before reading anything,
            // so the readings stay on the phone until the app is updated.
            await widget.store.markReadingUncertain(id, uncertain: false);
            interruption = error.message;
            break;
          }
          final details = error.errors.values
              .expand((value) => value is List ? value : [value])
              .map((value) => '$value')
              .toSet();
          await widget.store.markReadingError(
              id, details.isEmpty ? error.message : details.join('\n'));
          failed++;
        }
      }
    } catch (_) {
      interruption =
          'تعذّر حفظ حالة الرفع على الجهاز. احتفظ بالقراءات وأعد المحاولة بنفسها.';
    } finally {
      if (mounted) setState(() => uploadingReadings = false);
    }
    return ReadingUploadReport(
        sent: sent,
        held: held,
        failed: failed,
        reviewNotes: reviewNotes,
        message: interruption,
        interrupted: interruption != null);
  }

  Future<void> refreshCollections() async {
    final result = await widget.api.collectionsToday();
    if (mounted)
      setState(() => today = (result['data'] as List)
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList());
  }

  void collectionSearchChanged() {
    collectionDebounce?.cancel();
    collectionRequest++;
    // The current results stay on screen, dimmed, until the new ones arrive.
    setState(() {
      collectionBusy = true;
      collectionError = null;
    });
    collectionDebounce = Timer(const Duration(milliseconds: 300),
        () => unawaited(searchCollections()));
  }

  Future<void> searchCollections({bool loadMore = false}) async {
    if (!canBrowseSubscribers ||
        !mounted ||
        section != FieldSection.collections) return;
    if (loadMore && (collectionBusy || collectionPage >= collectionLastPage)) {
      return;
    }
    collectionDebounce?.cancel();
    final request = ++collectionRequest;
    final query = collectionSearch.text.trim();
    final page = loadMore ? collectionPage + 1 : 1;
    setState(() {
      collectionBusy = true;
      collectionError = null;
    });
    try {
      final result =
          await widget.api.findCollectionSubscribers(query, page: page);
      if (mounted && request == collectionRequest)
        setState(() {
          online = true;
          requiresLogin = false;
          final results = (result['data'] as List)
              .map((item) => Map<String, dynamic>.from(item as Map))
              .toList();
          collectionResults =
              loadMore ? [...collectionResults, ...results] : results;
          collectionPage = result['current_page'] as int? ?? page;
          collectionLastPage = result['last_page'] as int? ?? page;
        });
    } on ApiException catch (error) {
      if (mounted && request == collectionRequest)
        setState(() {
          if (!loadMore) {
            collectionResults = [];
            collectionPage = 0;
          }
          if (error.isNetwork || error.statusCode == 401) online = false;
          if (error.statusCode == 401) requiresLogin = true;
          collectionError = error.message;
        });
    } finally {
      if (mounted && request == collectionRequest) {
        setState(() => collectionBusy = false);
      }
    }
  }

  Future<void> saveReadings(List<Map<String, dynamic>> readings) async {
    await widget.store.queueReadings(readings);
    if (!mounted) return;
    setState(() => message = null);
  }

  /// Open the subscriber's page, where the payment is recorded from.
  Future<void> openSubscriber(Map<String, dynamic> subscriber) async {
    FocusScope.of(context).unfocus();
    if (!online) {
      showAppToast(context, 'عرض حساب المشترك يتطلب اتصالًا بالخادم.',
          icon: 'cloudoff');
      return;
    }
    final recorded = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => SubscriberPage(
          api: widget.api,
          subscriber: subscriber,
          paymentMethods: paymentMethods,
          exchangeRates: exchangeRatesFrom(currentUser['currencies']),
          transferBanks: currentUser['transfer_banks'] is List
              ? List<String>.from(currentUser['transfer_banks'] as List)
              : defaultTransferBanks,
          senderBanks: currentUser['sender_banks'] is List
              ? List<String>.from(currentUser['sender_banks'] as List)
              : const []),
    ));
    if (!mounted || section != FieldSection.collections) return;
    try {
      if (recorded == true && canCollect) await refreshCollections();
      if (mounted && recorded == true) await searchCollections();
    } on ApiException catch (_) {
      // The payment is already recorded; a failed refresh must not obscure it.
    }
  }

  Future<void> signOut() async {
    if (widget.store.queuedReadings.isNotEmpty) {
      showAppToast(context, 'زامن القراءات غير المرسلة قبل تسجيل الخروج.',
          icon: 'err');
      return;
    }
    final confirmed = await showAppSheet<bool>(context,
        title: 'تسجيل الخروج؟',
        message:
            'ستحتاج إلى الإنترنت لتسجيل الدخول مرة أخرى، حتى لو خرجت دون اتصال.',
        children: [_confirmButtons('تسجيل الخروج')]);
    if (confirmed != true) return;
    try {
      await widget.onLogout();
    } on ApiException catch (error) {
      if (mounted) setState(() => message = error.message);
    }
  }

  Widget _confirmButtons(String confirm) => Builder(
      builder: (context) => Row(children: [
            Expanded(
                child: AppAction(
                    label: 'إلغاء',
                    primary: false,
                    onPressed: () => Navigator.pop(context, false))),
            const SizedBox(width: 8),
            Expanded(
                child: AppAction(
                    label: confirm,
                    onPressed: () => Navigator.pop(context, true))),
          ]));

  Future<void> discardRejectedReading(Map<String, dynamic> reading) async {
    if (uploadingReadings || reading['upload_uncertain'] == true) return;
    final confirmed = await showAppSheet<bool>(context,
        title: 'حذف القراءة المحلية؟',
        message: 'رفض الخادم هذه القراءة: ${reading['sync_error']}\n'
            'سيُحذف الإدخال من هذا الجهاز ويمكنك إدخاله من جديد.',
        children: [_confirmButtons('حذف')]);
    if (confirmed != true) return;
    await widget.store.removeReading(reading['mobile_operation_id'] as String);
    if (mounted) {
      setState(() {});
      showAppToast(context, 'حُذفت القراءة من الجهاز', icon: 'trash');
    }
  }

  void resetCollectionSearch() {
    collectionDebounce?.cancel();
    collectionRequest++;
    collectionSearch.clear();
    collectionResults = [];
    collectionPage = 0;
    collectionLastPage = 1;
    collectionBusy = false;
    collectionError = null;
  }

  void navigate(FieldSection destination,
      {bool showToday = false, bool focusSearch = false}) {
    FocusScope.of(context).unfocus();
    setState(() {
      if (section != destination &&
          (section == FieldSection.collections ||
              destination == FieldSection.collections)) {
        resetCollectionSearch();
      }
      section = destination;
      collectionShowToday = showToday;
      collectionFocusSearch = focusSearch;
      message = null;
      if (destination != FieldSection.readings) {
        readingFocus = false;
        focusSubscriberId = null;
      }
    });
    if (destination == FieldSection.collections && canBrowseSubscribers) {
      unawaited(searchCollections());
    }
    if (destination == FieldSection.readings && canRead) {
      unawaited(synchronize(forceRoster: true));
    }
  }

  /// Take a saved reading back off the queue and open it on the keypad.
  Future<void> reenterReading(Map<String, dynamic> reading) async {
    if (syncing || uploadingReadings || reading['upload_uncertain'] == true) {
      showAppToast(context, 'جارٍ الإرسال الآن. أعد المحاولة بعد لحظات.',
          icon: 'clock');
      return;
    }
    if (!canRead ||
        !readingEntryIsOpen(widget.store.state, DateTime.now()) ||
        reading['week_start'] != widget.store.state['week_start'] ||
        !widget.store.subscribers.any(
            (subscriber) => subscriber['id'] == reading['subscription_id']))
      return;
    await widget.store.reopenReading(reading['mobile_operation_id'] as String);
    if (!mounted) return;
    setState(() {
      section = FieldSection.readings;
      message = null;
      focusSubscriberId = reading['subscription_id'] as int;
    });
  }

  void openWeeklyReadings() {
    if (!canViewReadings) return;
    Navigator.of(context).push(MaterialPageRoute<void>(
        builder: (_) => WeeklyReadingsPage(api: widget.api)));
  }

  List<AppNavItem> get destinations => [
        const AppNavItem('home', 'الرئيسية', 'home'),
        if (canRead) const AppNavItem('readings', 'القراءات', 'bolt'),
        if (canBrowseSubscribers)
          AppNavItem('collections', canCollect ? 'التحصيل' : 'كشوف الحساب',
              canCollect ? collectionIcon : 'hist'),
        if (canUseReadingSync)
          AppNavItem('sync', 'الإرسال', 'cloud',
              badge: widget.store.queuedReadings.length),
        const AppNavItem('account', 'حسابي', 'user'),
      ];

  @override
  Widget build(BuildContext context) {
    final inReadings = section == FieldSection.readings && canRead;
    return AppKeyboardBackScope(
      canPop: section == FieldSection.home,
      onBack: inReadings ? null : () => navigate(FieldSection.home),
      child: Scaffold(
        body: AnimatedSwitcher(
          duration: const Duration(milliseconds: 200),
          switchInCurve: Curves.easeOutCubic,
          transitionBuilder: (child, animation) =>
              FadeTransition(opacity: animation, child: child),
          child: KeyedSubtree(
            key: ValueKey(inReadings ? FieldSection.readings : section),
            child: inReadings
                ? ReadingFlow(
                    key: ValueKey('readings-$focusSubscriberId'),
                    store: widget.store,
                    online: online,
                    syncing: syncing || uploadingReadings,
                    message: message,
                    requiresLogin: requiresLogin,
                    focusSubscriberId: focusSubscriberId,
                    onSync: () => navigate(FieldSection.sync),
                    onRefresh: () => synchronize(forceRoster: true),
                    onSave: saveReadings,
                    onExit: () => navigate(FieldSection.home),
                    onReauthenticate: widget.onReauthenticate,
                    onFocusChanged: (focused) =>
                        setState(() => readingFocus = focused),
                  )
                : SafeArea(bottom: false, child: sectionBody()),
          ),
        ),
        bottomNavigationBar: inReadings && readingFocus
            ? null
            : AppNavBar(
                items: destinations,
                selected: section.name,
                onSelect: (id) => navigate(FieldSection.values.byName(id))),
      ),
    );
  }

  String get offlineText => switch (section) {
        FieldSection.collections =>
          'لا يوجد اتصال. اتصل بالإنترنت لعرض حسابات المشتركين.',
        FieldSection.sync =>
          'لا يوجد اتصال. القراءات محفوظة على الجهاز؛ راجعها وأكّد رفعها عند الاتصال.',
        _ => canRead
            ? 'لا يوجد اتصال. تُحفظ القراءات على الجهاز حتى تراجعها وتؤكد رفعها. التحصيل يحتاج إلى الإنترنت.'
            : 'لا يوجد اتصال. اتصل بالإنترنت لتسجيل الدفعات.',
      };

  Widget sectionBody() {
    final firstName = '${widget.user['name'] ?? ''}'.trim().split(' ').first;
    final title = switch (section) {
      FieldSection.collections =>
        canCollect ? 'التحصيل' : 'كشوف حساب المشتركين',
      FieldSection.sync => 'حالة الإرسال',
      FieldSection.account => 'حسابي',
      _ => 'مرحبًا، $firstName',
    };
    return Column(children: [
      AppHeader(
          title: title,
          subtitle: switch (section) {
            FieldSection.home =>
              '${widget.user['branch_name'] ?? 'جميع الفروع'}',
            FieldSection.collections => canCollect
                ? 'ابحث عن مشترك لتسجيل دفعة'
                : 'ابحث عن مشترك لعرض كشف حسابه',
            FieldSection.sync => 'القراءات المحفوظة على الجهاز',
            _ => null,
          },
          onSync: section == FieldSection.sync
              ? null
              : canUseReadingSync
                  ? () => navigate(FieldSection.sync)
                  : synchronize,
          syncLabel: canUseReadingSync ? 'حالة الإرسال' : 'تحديث البيانات',
          online: online,
          syncing: syncing,
          pending: widget.store.queuedReadings.length),
      if (!online && !syncing && section != FieldSection.account)
        ConstrainedBox(
            constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(context).height * .25),
            child: SingleChildScrollView(
                child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                    child: AppNotice(offlineText,
                        warning: true, icon: 'cloudoff')))),
      if (message != null && (online || requiresLogin))
        ConstrainedBox(
            constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(context).height * .25),
            child: SingleChildScrollView(
                child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                    child: AppNotice(message!, warning: true)))),
      if (requiresLogin)
        TextButton(
            onPressed: widget.onReauthenticate,
            child: const Text('تسجيل الدخول مجددًا')),
      Expanded(
          child: switch (section) {
        FieldSection.collections when canBrowseSubscribers => CollectionView(
            key: ValueKey(
                'collections-$collectionShowToday-$collectionFocusSearch'),
            initiallyShowingToday: collectionShowToday,
            focusSearch: collectionFocusSearch,
            canRecordPayments: canCollect,
            paymentMethods: paymentMethods,
            search: collectionSearch,
            results: collectionResults,
            today: today,
            busy: collectionBusy,
            online: online,
            error: collectionError,
            hasMore: collectionPage > 0 && collectionPage < collectionLastPage,
            onSearchChanged: collectionSearchChanged,
            onLoadMore: () => unawaited(searchCollections(loadMore: true)),
            onSearch: () => unawaited(searchCollections()),
            onRefresh: () async {
              await synchronize(forceRoster: true);
              await searchCollections();
            },
            onWeeklyReadings: canViewReadings ? openWeeklyReadings : null,
            onOpen: openSubscriber),
        FieldSection.sync when canUseReadingSync => syncView(),
        FieldSection.account => account(),
        _ => home(),
      }),
    ]);
  }

  Widget refreshable(Widget list) => RefreshIndicator(
      color: AppIdentity.brand,
      onRefresh: () => synchronize(forceRoster: true),
      child: list);

  Widget home() {
    final total = today.fold<double>(0, (sum, payment) {
      return sum +
          (double.tryParse(
                  '${payment['amount_in_shekels'] ?? payment['amount']}') ??
              0);
    });
    final subscribers = widget.store.subscribers;
    final queued = widget.store.queuedReadings;
    final done = subscribers
        .where((subscriber) =>
            subscriber['reading_status'] != null ||
            queued.any((reading) =>
                reading['subscription_id'] == subscriber['id'] &&
                reading['week_start'] == widget.store.state['week_start']))
        .length;
    final drafts = widget.store.readingDrafts.length;
    final weekStart = widget.store.state['week_start'];
    final rejected = queued.any((reading) => reading['sync_error'] != null);
    final ratio = subscribers.isEmpty ? 0.0 : done / subscribers.length;
    final hasTasks = canRead || canBrowseSubscribers || canViewOwnBoxes;

    // Weekly readings open from the collection screen and messages from the
    // subscriber screen, so home lists them only for staff without those.
    final tools = <Widget>[
      if (canViewReadings && !canBrowseSubscribers)
        AppServiceCard(
            title: 'القراءات الأسبوعية',
            subtitle: 'قراءات المشتركين والاستهلاك',
            summary: 'عرض فقط',
            icon: 'hist',
            color: AppIdentity.info,
            tint: AppIdentity.infoTint,
            onTap: openWeeklyReadings),
      if (canDistributeReadings)
        AppServiceCard(
            key: const ValueKey('reading-distribution-service'),
            title: 'توزيع القراءات',
            subtitle: 'المناطق والطبلونات وقارئو العدادات',
            summary: 'توزيع القراءة القادمة',
            icon: 'bolt',
            color: AppIdentity.brand,
            tint: AppIdentity.brandSoft,
            onTap: () => Navigator.push(
                context,
                MaterialPageRoute<void>(
                    builder: (_) => ReadingDistributionPage(api: widget.api)))),
      if ((canSendMessages || canViewMessages) && !canBrowseSubscribers)
        AppServiceCard(
            title: canSendMessages ? 'إرسال الرسائل' : 'سجل رسائل المشتركين',
            subtitle: 'رسائل المشترك وسجل الإرسال',
            summary: canSendMessages ? 'رسائل نصية SMS' : 'عرض فقط',
            icon: 'message',
            color: AppIdentity.info,
            tint: AppIdentity.infoTint,
            onTap: () => Navigator.push(
                context,
                MaterialPageRoute<void>(
                    builder: (_) => MessageSubscribersPage(api: widget.api)))),
    ];
    return refreshable(ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [
          AppHero(
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Container(
                  height: 26,
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  decoration: BoxDecoration(
                      color: const Color(0x18FFFFFF),
                      borderRadius: BorderRadius.circular(99)),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Container(
                        width: 7,
                        height: 7,
                        decoration: BoxDecoration(
                            color: syncing
                                ? const Color(0xFF93C5FD)
                                : online
                                    ? AppIdentity.heroGood
                                    : AppIdentity.heroWarn,
                            shape: BoxShape.circle)),
                    const SizedBox(width: 6),
                    Text(
                        syncing
                            ? 'جارٍ المزامنة'
                            : online
                                ? 'متصل'
                                : 'دون اتصال',
                        style: AppIdentity.body(12,
                            weight: FontWeight.w600, color: Colors.white)),
                  ]),
                ),
                const Spacer(),
                if (canRead && weekStart != null)
                  Flexible(
                      child: Text.rich(
                          TextSpan(children: [
                            const TextSpan(text: 'أسبوع '),
                            TextSpan(
                                text:
                                    '${AppIdentity.shortDate(weekStart)} – ${AppIdentity.shortDate(widget.store.state['week_end'])}',
                                style: AppIdentity.number(12.5,
                                    weight: FontWeight.w500,
                                    color: AppIdentity.heroFaint)),
                          ]),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppIdentity.body(12.5,
                              color: AppIdentity.heroFaint))),
              ]),
              const SizedBox(height: 16),
              Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
                if (canRead)
                  Expanded(
                      child: Row(children: [
                    AppRing(ratio),
                    const SizedBox(width: 12),
                    Expanded(
                        child: heroStat(
                            'قراءات الأسبوع', '$done / ${subscribers.length}')),
                  ])),
                if (canRead && canCollect) const SizedBox(width: 12),
                if (canCollect)
                  Expanded(
                      child: Material(
                          color: Colors.transparent,
                          child: InkWell(
                              key: const ValueKey('collector-today-payments'),
                              borderRadius: BorderRadius.circular(14),
                              onTap: () => navigate(FieldSection.collections,
                                  showToday: true),
                              child: heroStat('تحصيل اليوم',
                                  '${AppIdentity.grouped(total)} ₪',
                                  note: AppIdentity.paymentsCount(today.length),
                                  linked: true)))),
                if (!canRead && !canCollect)
                  Expanded(
                      child: heroStat('الفرع',
                          '${widget.user['branch_name'] ?? 'جميع الفروع'}',
                          size: 18)),
              ]),
            ]),
          ),
          if (canRead &&
              readingScheduleMessage(widget.store.state, DateTime.now()) !=
                  null)
            Padding(
                padding: const EdgeInsets.only(top: 12),
                child: AppNotice(
                    readingScheduleMessage(widget.store.state, DateTime.now())!,
                    warning: !readingEntryIsOpen(
                        widget.store.state, DateTime.now()))),
          if (queued.isNotEmpty)
            AppStrip(
                key: const ValueKey('home-pending'),
                icon: rejected ? 'err' : 'clock',
                tone: rejected ? AppTone.bad : AppTone.warning,
                text:
                    '${AppIdentity.readingsCount(queued.length)} بانتظار المزامنة',
                action: 'عرض',
                onTap: () => navigate(FieldSection.sync)),
          if (canRead && drafts > 0)
            AppStrip(
                icon: 'note',
                tone: AppTone.brand,
                text: '${AppIdentity.readingsCount(drafts)} مكتوبة ولم تُحفظ',
                action: 'متابعة',
                onTap: () => navigate(FieldSection.readings)),
          if (hasTasks) const AppSection('مهامك'),
          if (canRead)
            AppServiceCard(
                title: 'إدخال القراءات',
                subtitle: 'قراءات عدادات الطبلونات',
                summary: subscribers.isEmpty &&
                        widget.store.state['next_reading'] != null
                    ? '${(widget.store.state['next_reading']['boxes'] as List? ?? []).length} طبلون موزع إليك'
                    : 'قُرئت $done من ${subscribers.length}',
                icon: 'bolt',
                color: AppIdentity.brand,
                tint: AppIdentity.brandSoft,
                onTap: () => navigate(FieldSection.readings)),
          if (canBrowseSubscribers) subscribersService(),
          if (canViewOwnBoxes)
            AppServiceCard(
                key: const ValueKey('collector-cash-boxes-service'),
                title: 'صندوقي',
                subtitle: ownBoxTypes.length == 1
                    ? 'صندوقك المفتوح ودفعاته'
                    : 'صناديقك المفتوحة ودفعاتها حسب النوع',
                summary: cashBoxTypesSummary(ownBoxTypes),
                icon: 'box',
                color: AppIdentity.info,
                tint: AppIdentity.infoTint,
                onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                        builder: (_) => CashBoxesPage(
                            api: widget.api, types: ownBoxTypes)))),
          if (tools.isNotEmpty) ...[
            AppSection(hasTasks ? 'أدوات أخرى' : 'خدماتك'),
            ...tools,
          ],
          if (!hasTasks && tools.isEmpty)
            const AppNotice(
                'لا توجد خدمات مفعّلة لحسابك. تواصل مع الإدارة لمنحك الصلاحيات.'),
        ]));
  }

  /// The subscribers card. Every payment, split transfer and statement starts
  /// from one subscriber, so its search box is the single way into them.
  Widget subscribersService() {
    final (title, subtitle, icon, color, tint) = canCollect
        ? (
            'تسجيل الدفعات',
            'ابحث عن المشترك ثم اختر العملية',
            collectionIcon,
            AppIdentity.good,
            AppIdentity.goodTint
          )
        : (
            canViewSubscriptions ? 'كشوف حساب المشتركين' : 'حركات المشتركين',
            'الدفعات والقراءات والحركات المالية',
            'hist',
            AppIdentity.info,
            AppIdentity.infoTint
          );
    final hint = !canCollect
        ? 'كشف الحساب من صفحة المشترك'
        : canRecordSplitPayments
            ? 'الدفعة والحوالة المقسّمة وكشف الحساب من صفحة المشترك'
            : 'الدفعة وكشف الحساب من صفحة المشترك';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Container(
        key: ValueKey(canCollect
            ? 'collector-collections-service'
            : 'subscriber-statements-service'),
        decoration: AppIdentity.card(),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(22),
            onTap: () => navigate(FieldSection.collections),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(children: [
                      AppIconTile(icon, color, tint, size: 52, iconSize: 24),
                      const SizedBox(width: 14),
                      Expanded(
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                            Text(title,
                                style: AppIdentity.body(16,
                                    weight: FontWeight.w700)),
                            Text(subtitle,
                                style: AppIdentity.body(12.5,
                                    color: AppIdentity.faint)),
                          ])),
                      const SizedBox(width: 8),
                      AppIcon('chev', size: 18, color: AppIdentity.faint),
                    ]),
                    const SizedBox(height: 12),
                    InkWell(
                      key: const ValueKey('home-subscriber-search'),
                      borderRadius: BorderRadius.circular(15),
                      onTap: () =>
                          navigate(FieldSection.collections, focusSearch: true),
                      child: Container(
                        constraints: const BoxConstraints(minHeight: 48),
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(
                            color: AppIdentity.raised,
                            border:
                                Border.all(color: AppIdentity.line, width: 1.5),
                            borderRadius: BorderRadius.circular(15)),
                        child: Row(children: [
                          AppIcon('search', color: AppIdentity.faint),
                          const SizedBox(width: 8),
                          Expanded(
                              child: Text('الاسم أو رقم الحساب أو الطبلون',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: AppIdentity.body(14.5,
                                      color: AppIdentity.faint))),
                        ]),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(hint,
                        style: AppIdentity.body(12, color: AppIdentity.faint)),
                  ]),
            ),
          ),
        ),
      ),
    );
  }

  Widget heroStat(String label, String value,
          {String? note, double size = 23, bool linked = false}) =>
      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label,
            style: AppIdentity.body(12.5, color: AppIdentity.heroFaint)),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text(value,
                      textDirection: TextDirection.ltr,
                      style: AppIdentity.number(size,
                          weight: FontWeight.w800, color: Colors.white)))),
        ),
        if (note != null)
          Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(children: [
                Flexible(
                    child: Text(note,
                        style: AppIdentity.body(12.5,
                            color: linked
                                ? AppIdentity.heroGood
                                : AppIdentity.heroFaint))),
                if (linked) ...[
                  const SizedBox(width: 2),
                  AppIcon('chev', size: 14, color: AppIdentity.heroGood),
                ],
              ])),
      ]);

  Widget syncView() => ReadingReview(
        queue: widget.store.queuedReadings,
        subscribers: widget.store.subscribers,
        canUpload: canRead,
        canEdit: (reading) =>
            canRead &&
            readingEntryIsOpen(widget.store.state, DateTime.now()) &&
            reading['week_start'] == widget.store.state['week_start'] &&
            widget.store.subscribers.any(
                (subscriber) => subscriber['id'] == reading['subscription_id']),
        online: online,
        busy: syncing || uploadingReadings,
        onRefresh: () => synchronize(forceRoster: true),
        onUpload: uploadReadings,
        onReenter: reenterReading,
        onDiscard: discardRejectedReading,
      );
  Widget account() {
    final name = '${widget.user['name'] ?? '?'}';
    return ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [
          AppPanel(
              child: Row(children: [
            SizedBox(
                width: 66,
                height: 66,
                child: AppHero(
                    radius: 22,
                    shadow: false,
                    padding: EdgeInsets.zero,
                    child: Center(
                        child: Text(name.characters.first,
                            style: AppIdentity.heading(28,
                                color: Colors.white))))),
            const SizedBox(width: 14),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(name, style: AppIdentity.heading(21)),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text('@${widget.user['username']}',
                        textDirection: TextDirection.ltr,
                        style: AppIdentity.body(13, color: AppIdentity.faint)),
                  ),
                  Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Row(children: [
                        AppIcon('pin', size: 15, color: AppIdentity.faint),
                        const SizedBox(width: 4),
                        Expanded(
                            child: Text(
                                '${widget.user['branch_name'] ?? 'جميع الفروع'}',
                                style: AppIdentity.body(13,
                                    color: AppIdentity.faint))),
                      ])),
                ])),
          ])),
          if (widget.onAppearanceChanged != null) ...[
            const AppSection('مظهر التطبيق'),
            AppRows(children: [
              for (final (mode, label) in [
                (ThemeMode.light, 'فاتح'),
                (ThemeMode.dark, 'داكن'),
                (ThemeMode.system, 'حسب إعداد الهاتف'),
              ])
                AppRow(
                  key: ValueKey('appearance-${mode.name}'),
                  title: AppRowTitle(label),
                  trailing: Icon(
                      widget.appearance == mode
                          ? Icons.radio_button_checked
                          : Icons.radio_button_unchecked,
                      color: widget.appearance == mode
                          ? AppIdentity.brand
                          : AppIdentity.faint),
                  onTap: () async {
                    try {
                      await widget.onAppearanceChanged!(mode);
                    } catch (_) {
                      if (mounted)
                        showAppToast(context, 'تعذر حفظ المظهر. أعد المحاولة.',
                            icon: 'err');
                    }
                  },
                ),
            ]),
          ],
          const AppSection('الصلاحيات المتاحة'),
          AppRows(children: [
            for (final (allowed, label, icon) in [
              (canRead, 'إدخال القراءات', 'bolt'),
              (canCollect, 'تسجيل الدفعات', collectionIcon),
              (
                currentUser['can_adjust_balances'] == true,
                'إضافة الحركات المالية',
                'cash'
              ),
              (canRecordSplitPayments, 'الحوالات المقسّمة', 'bank'),
              (canViewSubscriptions, 'كشوف حساب المشتركين', 'hist'),
              (canViewReadings, 'عرض القراءات الأسبوعية', 'hist'),
              (
                currentUser['can_distribute_readings'] == true,
                'توزيع القراءات',
                'bolt'
              ),
              (
                currentUser['can_approve_reading_assignments'] == true,
                'اعتماد توزيع القراءات',
                'check'
              ),
              (canSendMessages, 'إرسال الرسائل', 'message'),
              (canViewMessages, 'سجل الرسائل', 'message'),
            ])
              if (allowed)
                AppRow(
                    leading: AppIconTile(
                        icon,
                        allowed ? AppIdentity.ink : AppIdentity.faint,
                        AppIdentity.sunken,
                        size: 40,
                        iconSize: 20),
                    title: Text(label,
                        style: AppIdentity.body(15,
                            weight: FontWeight.w700,
                            color:
                                allowed ? AppIdentity.ink : AppIdentity.faint)),
                    trailing: AppTag(allowed ? 'مفعّلة' : 'غير مفعّلة',
                        allowed ? AppTone.good : AppTone.muted)),
          ]),
          if (widget.store.queuedReadings.isNotEmpty)
            Padding(
                padding: const EdgeInsets.only(top: 12),
                child: AppNotice(
                    '${AppIdentity.readingsCount(widget.store.queuedReadings.length)} لم تُرسل بعد. زامنها قبل تسجيل الخروج.',
                    warning: true)),
          const SizedBox(height: 16),
          AppAction(
              label: 'تسجيل الخروج',
              primary: false,
              icon: 'logout',
              onPressed: signOut),
          const SizedBox(height: 24),
          const DevelopmentCredits(),
        ]);
  }
}
