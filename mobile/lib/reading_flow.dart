import 'dart:async';

import 'package:flutter/material.dart';

import 'app_identity.dart';
import 'field_store.dart';
import 'reading_assignments.dart';
import 'search_text.dart';

Color get _background => AppIdentity.background;
Color get _surface => AppIdentity.surface;
Color get _raised => AppIdentity.raised;
Color get _sunken => AppIdentity.sunken;
Color get _line => AppIdentity.line;
Color get _lineSoft => AppIdentity.lineSoft;
Color get _ink => AppIdentity.ink;
Color get _muted => AppIdentity.muted;
Color get _faint => AppIdentity.faint;
Color get _brand => AppIdentity.brand;
Color get _good => AppIdentity.good;
Color get _warning => AppIdentity.warning;
Color get _bad => AppIdentity.bad;
TextStyle _body(double size,
        {FontWeight weight = FontWeight.normal, Color? color}) =>
    AppIdentity.body(size, weight: weight, color: color);
TextStyle _number(double size, {Color? color}) =>
    AppIdentity.number(size, color: color, weight: FontWeight.w800);

/// How a typed reading compares with the meter's history.
typedef _Verdict = ({String text, Color color, String icon});

class ReadingFlow extends StatefulWidget {
  const ReadingFlow({
    required this.store,
    required this.online,
    required this.syncing,
    required this.message,
    required this.requiresLogin,
    required this.onSync,
    required this.onSave,
    required this.onExit,
    required this.onReauthenticate,
    this.onRefresh,
    this.onFocusChanged,
    this.focusSubscriberId,
    super.key,
  });

  final FieldStore store;
  final bool online;
  final bool syncing;
  final String? message;
  final bool requiresLogin;
  final VoidCallback onSync;
  final Future<void> Function(List<Map<String, dynamic>>) onSave;
  final VoidCallback onExit;
  final VoidCallback onReauthenticate;
  final Future<void> Function()? onRefresh;

  /// Told whether a box (or another inner page) is open, so the app can
  /// give the keypad the whole screen.
  final ValueChanged<bool>? onFocusChanged;

  /// A subscriber whose box opens straight away, with the keypad on them.
  final int? focusSubscriberId;

  @override
  State<ReadingFlow> createState() => _ReadingFlowState();
}

class _ReadingFlowState extends State<ReadingFlow> with WidgetsBindingObserver {
  final search = TextEditingController();
  late final Map<int, String> drafts = widget.store.readingDrafts;
  final rowKeys = <int, GlobalKey>{};
  Timer? draftSave;
  Timer? reveal;
  String? boxKey;
  String? previewBoxId;
  int? selectedId;
  bool keypadOpen = false;
  String view = 'boxes';
  String filter = 'all';
  String? subArea;
  bool saving = false;
  String? saveError;
  int savedCount = 0;
  String savedBox = '';
  List<String> savedOperationIds = [];
  String? rosterWeek;

  @override
  void initState() {
    super.initState();
    rosterWeek = widget.store.state['week_start'] as String?;
    WidgetsBinding.instance.addObserver(this);
    final focus = widget.store.subscribers
        .where((subscriber) => subscriber['id'] == widget.focusSubscriberId)
        .firstOrNull;
    if (focus != null && _entryOpen) {
      boxKey = _boxKey(focus);
      selectedId = focus['id'] as int;
      keypadOpen = _editable(focus);
      view = 'box';
      WidgetsBinding.instance.addPostFrameCallback((_) {
        widget.onFocusChanged?.call(true);
        _revealSelected();
      });
    }
  }

  @override
  void didUpdateWidget(covariant ReadingFlow oldWidget) {
    super.didUpdateWidget(oldWidget);
    final nextWeek = widget.store.state['week_start'] as String?;
    final weekChanged = rosterWeek != nextWeek;
    if (weekChanged) {
      draftSave?.cancel();
      drafts
        ..clear()
        ..addAll(widget.store.readingDrafts);
      rosterWeek = nextWeek;
    }
    final subscribers = widget.store.subscribers;
    final areas = {
      for (final subscriber in subscribers)
        '${subscriber['sub_area_id'] ?? 'none'}',
      for (final box
          in widget.store.state['next_reading']?['boxes'] as List? ?? [])
        '${box['sub_area_id'] ?? 'none'}',
    };
    if (subArea != null && !areas.contains(subArea)) subArea = null;
    if (weekChanged ||
        (boxKey != null && _inBox(boxKey!).isEmpty) ||
        (previewBoxId != null && _previewBox == null)) {
      final wasFocused = view != 'boxes';
      boxKey = null;
      previewBoxId = null;
      selectedId = null;
      keypadOpen = false;
      view = 'boxes';
      if (wasFocused)
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) widget.onFocusChanged?.call(false);
        });
    } else if (!_entryOpen ||
        !subscribers.any((subscriber) => subscriber['id'] == selectedId)) {
      selectedId = null;
      keypadOpen = false;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) _persistDrafts();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    if (draftSave?.isActive ?? false) _persistDrafts();
    draftSave?.cancel();
    reveal?.cancel();
    search.dispose();
    super.dispose();
  }

  void _draftsChanged() {
    draftSave?.cancel();
    draftSave = Timer(const Duration(milliseconds: 400), _persistDrafts);
  }

  void _persistDrafts() {
    draftSave?.cancel();
    unawaited(widget.store
        .saveReadingDrafts(Map.of(drafts), weekStart: rosterWeek)
        .catchError((Object _) {}));
  }

  void _setView(String next) {
    setState(() {
      view = next;
      if (next == 'boxes') previewBoxId = null;
    });
    widget.onFocusChanged?.call(next != 'boxes');
  }

  String _boxKey(Map<String, dynamic> subscriber) =>
      '${subscriber['meter_box_id'] ?? subscriber['meter_box_number'] ?? 'none'}';

  String _boxNumber(Map<String, dynamic> subscriber) =>
      '${subscriber['meter_box_number'] ?? '—'}';

  List<Map<String, dynamic>> _inBox(String key) => widget.store.subscribers
      .where((subscriber) => _boxKey(subscriber) == key)
      .toList();

  Map<String, List<Map<String, dynamic>>> _groups() {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final subscriber in widget.store.subscribers) {
      groups.putIfAbsent(_boxKey(subscriber), () => []).add(subscriber);
    }
    return groups;
  }

  Map<String, dynamic>? _queued(Map<String, dynamic> subscriber) {
    for (final reading in widget.store.queuedReadings) {
      if (reading['subscription_id'] == subscriber['id'] &&
          reading['week_start'] == widget.store.state['week_start']) {
        return reading;
      }
    }
    return null;
  }

  bool _done(Map<String, dynamic> subscriber) =>
      subscriber['reading_status'] != null || _queued(subscriber) != null;

  bool get _entryOpen => readingEntryIsOpen(widget.store.state, DateTime.now());

  bool _editable(Map<String, dynamic> subscriber) =>
      _entryOpen && !_done(subscriber);

  bool _hasDraft(Map<String, dynamic> subscriber) =>
      (drafts[subscriber['id']] ?? '').isNotEmpty;

  double _previous(Map<String, dynamic> subscriber) =>
      double.tryParse('${subscriber['previous_reading'] ?? 0}') ?? 0;

  /// The subscriber's last weeks, newest first, as the server sent them.
  List<Map<String, dynamic>> _history(Map<String, dynamic> subscriber) =>
      (subscriber['recent_readings'] as List? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();

  /// Their usual weekly consumption, from the weeks the server sent.
  double? _usual(Map<String, dynamic> subscriber) {
    final weeks = _history(subscriber)
        .map((week) => double.tryParse('${week['consumption']}'))
        .whereType<double>()
        .toList();
    if (weeks.isEmpty) return null;
    return weeks.reduce((sum, week) => sum + week) / weeks.length;
  }

  _Verdict? _verdict(Map<String, dynamic> subscriber, double? value) {
    if (value == null) return null;
    final input = drafts[subscriber['id']] ?? '';
    if (input.contains('.') && input.split('.').last.length > 2) {
      return (
        text: 'القراءة تقبل منزلتين عشريتين كحد أقصى.',
        color: _bad,
        icon: 'err'
      );
    }
    final previous = _previous(subscriber);
    if (value < previous) {
      return (
        text: 'أقل من السابقة (${AppIdentity.reading(previous)})',
        color: _bad,
        icon: 'err',
      );
    }
    final usual = _usual(subscriber);
    if (usual == null || usual <= 0) return null;
    final consumption = value - previous;
    if (consumption > usual * 2 && consumption - usual >= 10) {
      return (
        text:
            'أعلى من المعتاد بكثير (${(consumption / usual).toStringAsFixed(1)}×)',
        color: _warning,
        icon: 'up',
      );
    }
    if (consumption == 0) {
      return (
        text: 'لا استهلاك هذا الأسبوع، تأكّد من العداد',
        color: _warning,
        icon: 'info',
      );
    }
    return (
      text: 'ضمن المعتاد',
      color: _good,
      icon: 'check',
    );
  }

  bool _ready(Map<String, dynamic> subscriber) {
    if (!_editable(subscriber)) return false;
    final input = drafts[subscriber['id']] ?? '';
    if (input.contains('.') && input.split('.').last.length > 2) return false;
    final value = double.tryParse(drafts[subscriber['id']] ?? '');
    return value != null && value >= _previous(subscriber);
  }

  void _openBox(String key, {int? focusId}) {
    FocusScope.of(context).unfocus();
    final subscribers = _inBox(key);
    final first = focusId ??
        subscribers
            .where((subscriber) => _editable(subscriber) && !_ready(subscriber))
            .map((subscriber) => subscriber['id'] as int)
            .firstOrNull ??
        subscribers
            .where(_editable)
            .map((subscriber) => subscriber['id'] as int)
            .firstOrNull;
    final focus = subscribers
        .where((subscriber) => subscriber['id'] == first)
        .firstOrNull;
    setState(() {
      boxKey = key;
      selectedId = first;
      keypadOpen = focusId != null && focus != null && _editable(focus);
      saveError = null;
    });
    _setView('box');
    _revealSelected();
  }

  void _back() {
    if (saving) return;
    if (dismissAppKeyboard(context)) return;
    if (view == 'boxes') {
      widget.onExit();
    } else if (view == 'box' && keypadOpen) {
      setState(() => keypadOpen = false);
    } else {
      setState(() {
        boxKey = null;
        selectedId = null;
        keypadOpen = false;
      });
      _setView('boxes');
    }
  }

  void _select(Map<String, dynamic> subscriber) {
    if (_editable(subscriber)) {
      setState(() {
        selectedId = subscriber['id'] as int;
        keypadOpen = true;
      });
      _revealSelected();
      return;
    }
    final queued = _queued(subscriber);
    if (queued != null) {
      unawaited(_askToReenter(queued, subscriber));
      return;
    }
    showAppToast(
        context,
        _entryOpen
            ? 'وصلت قراءة ${subscriber['full_name']} إلى النظام. لتعديلها تواصل مع المدقق.'
            : 'إدخال قراءات هذا الأسبوع غير متاح حاليًا.',
        icon: 'info');
  }

  /// Scroll the chosen subscriber into view once the keypad has opened.
  void _revealSelected() {
    reveal?.cancel();
    reveal = Timer(const Duration(milliseconds: 240), () {
      final context = rowKeys[selectedId]?.currentContext;
      if (mounted && context != null && context.mounted) {
        Scrollable.ensureVisible(context,
            alignment: .3,
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOutCubic);
      }
    });
  }

  Future<void> _askToReenter(
      Map<String, dynamic> reading, Map<String, dynamic> subscriber) async {
    if (!_entryOpen || reading['upload_uncertain'] == true) {
      showAppToast(
          context,
          reading['upload_uncertain'] == true
              ? 'نتيجة الرفع غير مؤكدة. أعد رفع نفس القراءة أولًا لتجنب التكرار.'
              : 'تعديل القراءة متاح يوم القراءة فقط.',
          icon: 'info');
      return;
    }
    final reenter = await showAppSheet<bool>(context,
        title: '${subscriber['full_name']}',
        message: reading['sync_error'] == null
            ? 'القراءة محفوظة على الجهاز ولم تُرسل بعد، ويمكنك تعديلها.'
            : 'رفض الخادم هذه القراءة: ${reading['sync_error']}',
        children: [
          AppReadingCompare(
              previous: AppIdentity.reading(subscriber['previous_reading']),
              current: AppIdentity.reading(reading['current_reading']),
              highlightCurrent: true,
              consumption: _consumptionText(subscriber,
                  double.tryParse('${reading['current_reading']}'))),
          const SizedBox(height: 16),
          Builder(
              builder: (context) => AppAction(
                  label: 'إعادة إدخال القراءة',
                  icon: 'edit',
                  onPressed: () => Navigator.pop(context, true))),
          const SizedBox(height: 8),
          Builder(
              builder: (context) => AppAction(
                  label: 'إبقاؤها كما هي',
                  primary: false,
                  onPressed: () => Navigator.pop(context, false))),
        ]);
    if (reenter == true) await _reenter(reading);
  }

  Future<void> _reenter(Map<String, dynamic> reading) async {
    if (widget.syncing || !_entryOpen || reading['upload_uncertain'] == true) {
      showAppToast(context, 'جارٍ الإرسال الآن. أعد المحاولة بعد لحظات.',
          icon: 'clock');
      return;
    }
    await widget.store.reopenReading(reading['mobile_operation_id'] as String);
    if (!mounted) return;
    final subscriber = widget.store.subscribers
        .where((item) => item['id'] == reading['subscription_id'])
        .firstOrNull;
    setState(() {
      drafts[reading['subscription_id'] as int] =
          '${reading['current_reading']}';
      if (subscriber != null) {
        boxKey = _boxKey(subscriber);
        selectedId = subscriber['id'] as int;
        keypadOpen = _editable(subscriber);
      }
    });
    _setView(subscriber == null ? 'boxes' : 'box');
    _revealSelected();
  }

  void _key(String key) {
    if (selectedId == null || boxKey == null || saving) return;
    final subscribers = _inBox(boxKey!);
    final current = subscribers
        .where((subscriber) => subscriber['id'] == selectedId)
        .firstOrNull;
    if (current == null || !_editable(current)) return;
    if (key == 'next') {
      final index = subscribers.indexOf(current);
      final following = [
        ...subscribers.skip(index + 1),
        ...subscribers.take(index),
      ]
          .where((subscriber) => _editable(subscriber) && !_ready(subscriber))
          .map((subscriber) => subscriber['id'] as int)
          .firstOrNull;
      setState(() {
        if (following == null) {
          keypadOpen = false;
        } else {
          selectedId = following;
        }
      });
      if (following != null) _revealSelected();
      return;
    }
    final value = drafts[selectedId] ?? '';
    if (key == 'clear') {
      setState(() => drafts.remove(selectedId));
    } else if (key == 'delete') {
      setState(() => drafts[selectedId!] =
          value.isEmpty ? '' : value.substring(0, value.length - 1));
    } else if (key == '.') {
      if (!value.contains('.')) {
        setState(() => drafts[selectedId!] = value.isEmpty ? '0.' : '$value.');
      }
    } else if (value.length < 9) {
      setState(() => drafts[selectedId!] = value == '0' ? key : '$value$key');
    }
    _draftsChanged();
  }

  Future<void> _save() async {
    if (boxKey == null || saving) return;
    final ready = _inBox(boxKey!).where(_ready).toList();
    if (ready.isEmpty) return;
    final readings = ready
        .map((subscriber) => <String, dynamic>{
              'mobile_operation_id': newOperationId(),
              'subscription_id': subscriber['id'],
              'week_start': widget.store.state['week_start'],
              'current_reading':
                  drafts[subscriber['id']]?.replaceFirst(RegExp(r'\.$'), ''),
              'review': {
                'name': subscriber['full_name'],
                'account_number': subscriber['account_number'],
                'meter_box_number': subscriber['meter_box_number'],
                'meter_box_name': subscriber['meter_box_name'],
                'sub_area_name': subscriber['sub_area_name'],
                'previous_reading': subscriber['previous_reading'],
                if (_verdict(subscriber,
                            double.tryParse(drafts[subscriber['id']] ?? ''))
                        ?.color ==
                    _warning)
                  'warning': _verdict(subscriber,
                          double.tryParse(drafts[subscriber['id']] ?? ''))
                      ?.text,
              },
              'notes': '',
              if (subscriber['assignment_id'] != null) ...{
                'assignment_id': subscriber['assignment_id'],
                'authorization': subscriber['authorization'],
                'captured_at': DateTime.now().toUtc().toIso8601String(),
              },
            })
        .toList();
    draftSave?.cancel();
    setState(() {
      saving = true;
      saveError = null;
    });
    try {
      await widget.onSave(readings);
      if (!mounted) return;
      setState(() {
        savedBox = _boxNumber(ready.first);
        savedCount = ready.length;
        savedOperationIds = readings
            .map((reading) => reading['mobile_operation_id'] as String)
            .toList();
        saving = false;
        for (final subscriber in ready) {
          drafts.remove(subscriber['id']);
        }
        selectedId = null;
        keypadOpen = false;
      });
      _setView('done');
    } catch (_) {
      if (mounted) {
        setState(() {
          saving = false;
          saveError = 'تعذر حفظ القراءات على الجهاز. حاول مجددًا.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final content = switch (view) {
      'box' => _refreshable(_boxView()),
      'preview' => _refreshable(_previewBoxView()),
      'done' => _doneView(),
      _ => _boxesView(),
    };
    final selected = view != 'box' || boxKey == null
        ? null
        : _inBox(boxKey!)
            .where((subscriber) => subscriber['id'] == selectedId)
            .firstOrNull;
    final showKeypad = keypadOpen && selected != null && _editable(selected);
    // While typing, the keypad and comparison need the room; the header's
    // cloud icon still shows when the phone is offline.
    final showBanners = view != 'done' && !(view == 'box' && showKeypad);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _back();
      },
      child: ColoredBox(
        color: _background,
        child: SafeArea(
          bottom: view != 'boxes',
          child: Column(children: [
            if (view != 'done') _header(),
            if (!widget.online && showBanners)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                child: const AppNotice(
                    'لا يوجد اتصال. تُحفظ القراءات على الجهاز حتى تراجعها وتؤكد رفعها.',
                    warning: true,
                    icon: 'cloudoff'),
              ),
            if (widget.message != null &&
                (widget.online || widget.requiresLogin) &&
                showBanners)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                child: AppNotice(widget.message!, warning: true),
              ),
            if (widget.requiresLogin && view != 'done')
              TextButton(
                  onPressed: widget.onReauthenticate,
                  child: const Text('تسجيل الدخول مجددًا')),
            Expanded(
                child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 200),
              switchInCurve: Curves.easeOutCubic,
              transitionBuilder: (child, animation) => FadeTransition(
                  opacity: animation,
                  child: SlideTransition(
                      position:
                          Tween(begin: const Offset(0, .02), end: Offset.zero)
                              .animate(animation),
                      child: child)),
              child: KeyedSubtree(key: ValueKey(view), child: content),
            )),
            if (view == 'box') ...[
              AppReveal(
                  visible: showKeypad,
                  child: selected == null
                      ? const SizedBox(width: double.infinity)
                      : _comparePanel(selected)),
              _saveBar(),
              AppReveal(
                  visible: showKeypad,
                  child: AppKeypad(
                      onKey: _key,
                      withNext: true,
                      enabled: !saving,
                      nextLabel: _hasFollowing(selected) ? 'التالي' : 'تم',
                      onClose: () => setState(() => keypadOpen = false))),
            ],
          ]),
        ),
      ),
    );
  }

  bool _hasFollowing(Map<String, dynamic>? selected) {
    if (selected == null || boxKey == null) return false;
    return _inBox(boxKey!).any((subscriber) =>
        subscriber['id'] != selected['id'] &&
        _editable(subscriber) &&
        !_ready(subscriber));
  }

  Widget _header() {
    final subscribers =
        boxKey == null ? <Map<String, dynamic>>[] : _inBox(boxKey!);
    final preview = _previewBox;
    final first = preview == null
        ? subscribers.firstOrNull
        : {
            'meter_box_number': preview['number'],
            'meter_box_name': preview['name'],
            'sub_area_name': preview['sub_area_name'],
            'meter_box_location': preview['location'],
          };
    final weekStart = widget.store.state['week_start'];
    final weekEnd = widget.store.state['week_end'];
    final boxView = view == 'box' || view == 'preview';
    return AppHeader(
        title: boxView
            ? 'طبلون ${first == null ? '—' : _boxNumber(first)}'
            : 'إدخال القراءات',
        subtitle: boxView
            ? [
                '${first?['meter_box_name'] ?? 'الطبلون'}',
                if (first?['sub_area_name'] != null)
                  '${first?['sub_area_name']}',
                if ('${first?['meter_box_location'] ?? ''}'.isNotEmpty)
                  '${first?['meter_box_location']}',
              ].join(' · ')
            : weekStart == null
                ? 'اختر طبلونًا أو ابحث'
                : 'أسبوع ${AppIdentity.shortDate(weekStart)} – ${AppIdentity.shortDate(weekEnd)}',
        onBack: view == 'boxes' ? null : _back,
        onSync: widget.onSync,
        online: widget.online,
        syncing: widget.syncing,
        pending: widget.store.queuedReadings.length);
  }

  Widget _refreshable(Widget list) => widget.onRefresh == null
      ? list
      : RefreshIndicator(
          color: _brand, onRefresh: widget.onRefresh!, child: list);

  Widget _boxesView() {
    final groups = _groups();
    final previews =
        (widget.store.state['next_reading']?['boxes'] as List? ?? [])
            .map((item) => Map<String, dynamic>.from(item as Map))
            .where((box) => !groups.containsKey('${box['id']}'))
            .toList();
    final query = search.text.trim();
    final areas = <String, String>{
      for (final box in previews)
        '${box['sub_area_id'] ?? 'none'}':
            '${box['sub_area_name'] ?? 'بدون منطقة 2'}',
      for (final subscriber in widget.store.subscribers)
        '${subscriber['sub_area_id'] ?? 'none'}':
            '${subscriber['sub_area_name'] ?? 'بدون منطقة 2'}'
    };
    bool inArea(Map<String, dynamic> subscriber) =>
        subArea == null || '${subscriber['sub_area_id'] ?? 'none'}' == subArea;
    bool complete(List<Map<String, dynamic>> subscribers) =>
        subscribers.where(_done).length == subscribers.length;
    final matchingPreviews = previews
        .where((box) =>
            inArea(box) &&
            (query.isNotEmpty || filter != 'done') &&
            matchesSearch(query, [
              box['number'],
              box['name'],
              box['location'],
              box['sub_area_name']
            ]))
        .toList();
    final matchingBoxes = groups.entries.where((entry) {
      if (!inArea(entry.value.first)) return false;
      if (query.isEmpty && filter == 'remaining' && complete(entry.value)) {
        return false;
      }
      if (query.isEmpty && filter == 'done' && !complete(entry.value)) {
        return false;
      }
      final first = entry.value.first;
      return matchesSearch(query, [
        _boxNumber(first),
        first['meter_box_name'],
        first['meter_box_location'],
        first['sub_area_name'],
      ]);
    }).toList();
    final matchingSubscribers = widget.store.subscribers.where((subscriber) {
      if (query.isEmpty || !inArea(subscriber)) return false;
      return matchesSearch(
          query, [subscriber['full_name'], subscriber['account_number']]);
    }).toList();
    final total = widget.store.subscribers.length;
    final done = widget.store.subscribers.where(_done).length;
    final draftBox = groups.entries
        .where((entry) => entry.value.any(
            (subscriber) => _editable(subscriber) && _hasDraft(subscriber)))
        .firstOrNull;
    int countFor(String value) =>
        groups.values
            .where((subscribers) =>
                value == 'all' ||
                (value == 'done'
                    ? complete(subscribers)
                    : !complete(subscribers)))
            .length +
        (value == 'done' ? 0 : previews.length);
    return _refreshable(ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [
          if (readingScheduleMessage(widget.store.state, DateTime.now()) !=
              null)
            Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: AppNotice(
                    readingScheduleMessage(widget.store.state, DateTime.now())!,
                    warning: !_entryOpen)),
          if (query.isEmpty && total > 0) _progressCard(done, total),
          if (query.isEmpty && draftBox != null)
            Padding(
                padding: const EdgeInsets.only(top: 12),
                child: _resumeCard(draftBox.key, draftBox.value)),
          const SizedBox(height: 12),
          AppSearchField(
              key: const ValueKey('reading-box-search'),
              controller: search,
              hint: 'منطقة 2 أو الطبلون أو المشترك',
              onChanged: (_) => setState(() {}),
              onClear: () => setState(search.clear)),
          if (areas.isNotEmpty)
            Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Wrap(spacing: 6, runSpacing: 6, children: [
                  AppChip('كل المناطق',
                      selected: subArea == null,
                      onTap: () => setState(() => subArea = null)),
                  for (final area in areas.entries)
                    AppChip(area.value,
                        selected: subArea == area.key,
                        onTap: () => setState(() => subArea = area.key)),
                ])),
          AppSection(query.isEmpty ? 'الطبلونات الموزعة إليك' : 'نتائج البحث',
              note: query.isEmpty
                  ? null
                  : '${matchingBoxes.length + matchingSubscribers.length + matchingPreviews.length} نتيجة'),
          if (query.isEmpty && (total > 0 || previews.isNotEmpty))
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Wrap(spacing: 6, runSpacing: 6, children: [
                for (final (value, label) in [
                  ('all', 'الكل'),
                  ('remaining', 'المتبقية'),
                  ('done', 'المكتملة'),
                ])
                  AppChip(label,
                      count: countFor(value),
                      selected: filter == value,
                      onTap: () => setState(() => filter = value)),
              ]),
            ),
          if (total == 0 && previews.isEmpty)
            AppRows(children: const [
              AppEmpty('down', 'لا توجد طبلونات موزعة إليك حاليًا',
                  'تظهر الطبلونات بعد اعتماد توزيعها. اسحب للتحديث.')
            ]),
          if ((total > 0 || previews.isNotEmpty) &&
              matchingBoxes.isEmpty &&
              matchingSubscribers.isEmpty &&
              matchingPreviews.isEmpty)
            AppRows(children: [
              query.isEmpty
                  ? AppEmpty(
                      filter == 'remaining' ? 'check' : 'bolt',
                      filter == 'remaining'
                          ? 'قُرئت كل الطبلونات'
                          : 'لا توجد طبلونات مكتملة بعد',
                      filter == 'remaining'
                          ? 'أحسنت! لا يوجد ما تبقّى لهذا الأسبوع.'
                          : 'تظهر هنا الطبلونات بعد قراءة كل مشتركيها.',
                      good: filter == 'remaining')
                  : AppEmpty(
                      'search',
                      'لا توجد نتائج لـ «${search.text.trim()}»',
                      'جرّب رقم الطبلون أو جزءًا من الاسم.'),
            ]),
          if (matchingBoxes.isNotEmpty ||
              matchingSubscribers.isNotEmpty ||
              matchingPreviews.isNotEmpty)
            AppRows(children: [
              for (final box in matchingPreviews) _previewBoxRow(box),
              for (final entry in matchingBoxes)
                _boxRow(entry.key, entry.value),
              for (final subscriber in matchingSubscribers)
                _subscriberResult(subscriber),
            ]),
          if (!_entryOpen &&
              readingScheduleMessage(widget.store.state, DateTime.now()) ==
                  null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: const AppNotice(
                  'إدخال قراءات هذا الأسبوع غير متاح حاليًا.',
                  warning: true),
            ),
        ]));
  }

  Widget _progressCard(int done, int total) {
    final pending = widget.store.queuedReadings.length;
    final ratio = total == 0 ? 0.0 : done / total;
    return AppHero(
      padding: const EdgeInsets.all(16),
      child: Row(children: [
        AppRing(ratio),
        const SizedBox(width: 16),
        Expanded(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('قُرئت هذا الأسبوع',
                style: _body(12.5, color: AppIdentity.heroFaint)),
            Padding(
                padding: const EdgeInsets.only(top: 2, bottom: 4),
                child: Text('$done من $total',
                    style: _number(23, color: Colors.white))),
            Text(
                pending == 0
                    ? 'كل القراءات أُرسلت'
                    : '${AppIdentity.readingsCount(pending)} بانتظار الإرسال',
                style: _body(12.5,
                    weight: FontWeight.w600,
                    color: pending == 0
                        ? AppIdentity.heroGood
                        : AppIdentity.heroWarn)),
          ]),
        ),
      ]),
    );
  }

  Widget _resumeCard(String key, List<Map<String, dynamic>> subscribers) {
    final count = subscribers
        .where((subscriber) => _editable(subscriber) && _hasDraft(subscriber))
        .length;
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Material(
        color: AppIdentity.brandSoft,
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          key: const ValueKey('resume-drafts'),
          borderRadius: BorderRadius.circular(20),
          onTap: () => _openBox(key),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(children: [
              AppIcon('note', size: 26, color: _brand),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('أكمل من حيث توقفت',
                          style: _body(15, weight: FontWeight.w700)),
                      Text(
                          '${AppIdentity.readingsCount(count)} لم تُحفظ بعد في طبلون ${_boxNumber(subscribers.first)}',
                          style: _body(12.5, color: _muted)),
                    ]),
              ),
              AppIcon('chev', size: 20, color: _brand),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _previewBoxRow(Map<String, dynamic> box) => AppRow(
      key: ValueKey('preview-box-${box['id']}'),
      leading: _boxBadge('${box['number']}', complete: false),
      title: AppRowTitle('${box['name']} (${box['number']})'),
      subtitle: AppRowNote([
        '${box['sub_area_name'] ?? 'بدون منطقة 2'}',
        '${box['subscribers']} مشترك',
      ].join(' · ')),
      trailing: const AppTag('عرض فقط', AppTone.muted),
      onTap: () {
        previewBoxId = '${box['id']}';
        _setView('preview');
      });

  Map<String, dynamic>? get _previewBox =>
      (widget.store.state['next_reading']?['boxes'] as List? ?? [])
          .where((box) => '${box['id']}' == previewBoxId)
          .map((box) => Map<String, dynamic>.from(box as Map))
          .firstOrNull;

  Widget _previewBoxView() {
    final subscriptions = _previewBox?['subscriptions'] as List? ?? [];
    return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          AppNotice(readingScheduleMessage(
                  widget.store.state, DateTime.now()) ??
              'المشتركون للعرض فقط. الإدخال متاح يوم القراءة بعد تحديث الطبلونات.'),
          const SizedBox(height: 12),
          AppRows(children: [
            if (subscriptions.isEmpty)
              const AppEmpty('bolt', 'بيانات المشتركين غير متاحة حاليًا',
                  'اتصل بالإنترنت واسحب للتحديث.'),
            for (final subscription in subscriptions)
              AppRow(
                key: ValueKey('preview-subscriber-${subscription['id']}'),
                title: AppRowTitle('${subscription['name']}'),
                subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      AppRowNote('حساب ${subscription['account_number']}'),
                      if (subscription['phone'] != null)
                        AppRowNote('${subscription['phone']}'),
                    ]),
                trailing: const AppTag('عرض فقط', AppTone.muted),
              ),
          ]),
        ]);
  }

  Widget _boxRow(String key, List<Map<String, dynamic>> subscribers) {
    final first = subscribers.first;
    final done = subscribers.where(_done).length;
    final complete = done == subscribers.length;
    final drafted = subscribers
        .where((subscriber) => _editable(subscriber) && _hasDraft(subscriber))
        .length;
    final number = _boxNumber(first);
    final location = '${first['meter_box_location'] ?? ''}';
    return AppRow(
      key: ValueKey('box-$number'),
      onTap: () => _openBox(key),
      leading: _boxBadge(number, complete: complete),
      title: Text.rich(
          TextSpan(children: [
            TextSpan(text: '${first['meter_box_name'] ?? 'طبلون $number'} '),
            TextSpan(
                text: '($number)',
                style: _number(12.5, color: _faint)
                    .copyWith(fontWeight: FontWeight.w600)),
          ]),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: _body(15, weight: FontWeight.w700)),
      subtitle: Row(children: [
        Flexible(
          child: Text(
              [
                if (first['sub_area_name'] != null) '${first['sub_area_name']}',
                if (location.isNotEmpty) location,
                '${subscribers.length} مشترك'
              ].join(' · '),
              style: _body(12.5, color: _faint),
              maxLines: 1,
              overflow: TextOverflow.ellipsis),
        ),
        if (drafted > 0) ...[
          const SizedBox(width: 4),
          AppTag('مسودة $drafted', AppTone.brand),
        ],
      ]),
      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
        if (!_entryOpen)
          const AppTag('عرض فقط', AppTone.muted)
        else if (complete)
          const AppTag('مكتمل', AppTone.good)
        else
          SizedBox(
            width: 74,
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('$done من ${subscribers.length}',
                      style: AppIdentity.number(13.5)),
                  const SizedBox(height: 5),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(6),
                    child: LinearProgressIndicator(
                      value: done / subscribers.length,
                      minHeight: 6,
                      backgroundColor: _sunken,
                      color: _good,
                    ),
                  ),
                ]),
          ),
        const SizedBox(width: 12),
        AppIcon('chev', size: 18, color: _faint),
      ]),
    );
  }

  Widget _boxBadge(String number, {bool complete = false}) => Container(
        width: 50,
        height: 50,
        alignment: Alignment.center,
        decoration: BoxDecoration(
            color: complete ? _good : const Color(0xFF262C34),
            borderRadius: BorderRadius.circular(16)),
        child: complete
            ? const AppIcon('check', color: Colors.white)
            : Padding(
                padding: const EdgeInsets.all(4),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                      number.replaceFirst(
                          RegExp(r'^(BOX|N)-', caseSensitive: false), ''),
                      maxLines: 1,
                      softWrap: false,
                      style: _number(14, color: Colors.white)),
                ),
              ),
      );

  Widget _subscriberResult(Map<String, dynamic> subscriber) => AppRow(
        onTap: () =>
            _openBox(_boxKey(subscriber), focusId: subscriber['id'] as int),
        leading: AppAvatar('${subscriber['full_name']}'),
        title: AppRowTitle('${subscriber['full_name']}'),
        subtitle: AppRowNote(
            'طبلون ${_boxNumber(subscriber)} · السابقة ${AppIdentity.reading(subscriber['previous_reading'])}'),
        trailing: _statusTag(subscriber),
      );

  Widget _statusTag(Map<String, dynamic> subscriber) {
    final queued = _queued(subscriber);
    if (queued != null) {
      return queued['sync_error'] == null
          ? const AppTag('على الجهاز', AppTone.warning)
          : const AppTag('تعذر الإرسال', AppTone.bad);
    }
    return switch (subscriber['reading_status']) {
      'approved' => const AppTag('معتمدة', AppTone.good),
      'pending' => const AppTag('قيد المراجعة', AppTone.warning),
      'needs_review' => const AppTag('تحتاج مراجعة', AppTone.warning),
      _ => _hasDraft(subscriber)
          ? const AppTag('مسودة', AppTone.brand)
          : const AppTag('لم تُقرأ', AppTone.muted),
    };
  }

  Widget _boxView() {
    final subscribers =
        boxKey == null ? <Map<String, dynamic>>[] : _inBox(boxKey!);
    if (subscribers.isEmpty) {
      return ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            AppRows(children: const [
              AppEmpty('bolt', 'الطبلون غير متاح', 'ارجع إلى قائمة الطبلونات.')
            ])
          ]);
    }
    final done = subscribers.where(_done).length;
    final ready = subscribers.where(_ready).length;
    return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [
          AppPanel(
            child: Column(children: [
              Row(children: [
                Expanded(
                    child: Text('قُرئت $done من ${subscribers.length}',
                        style: _body(14, weight: FontWeight.w700))),
                if (ready > 0)
                  Flexible(
                    child: AppTag(
                        '${AppIdentity.readingsCount(ready)} جاهزة للحفظ',
                        AppTone.brand),
                  ),
              ]),
              const SizedBox(height: 10),
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Row(children: [
                  if (done > 0)
                    Expanded(
                        flex: done, child: Container(height: 8, color: _good)),
                  if (ready > 0)
                    Expanded(
                        flex: ready,
                        child: Container(height: 8, color: _brand)),
                  Expanded(
                      flex: subscribers.length - done - ready == 0
                          ? 0
                          : subscribers.length - done - ready,
                      child: Container(height: 8, color: _sunken)),
                ]),
              ),
            ]),
          ),
          const SizedBox(height: 12),
          AppRows(children: [
            for (var index = 0; index < subscribers.length; index++)
              _readingRow(subscribers[index], index),
          ]),
          const SizedBox(height: 12),
          if (saveError != null)
            AppNotice(saveError!, error: true)
          else
            const AppNotice(
                'اضغط على المشترك لإدخال قراءته. بعد الحفظ راجع القراءات من صفحة الإرسال، ثم أكّد رفعها.'),
        ]);
  }

  String? _consumptionText(Map<String, dynamic> subscriber, double? value) {
    if (value == null || value < _previous(subscriber)) return null;
    return AppIdentity.reading(
        ((value - _previous(subscriber)) * 100).round() / 100);
  }

  Widget _readingRow(Map<String, dynamic> subscriber, int index) {
    final id = subscriber['id'] as int;
    final selected = selectedId == id && keypadOpen;
    final queued = _queued(subscriber);
    final finished = _done(subscriber);
    final draft = drafts[id];
    final value = double.tryParse(draft ?? '');
    final previous = _previous(subscriber);
    final low = value != null && value < previous;
    final lastWeek = _history(subscriber).firstOrNull;
    final shownReading =
        queued?['current_reading'] ?? subscriber['current_reading'];
    final rejected = queued?['sync_error'] != null;
    final verdict = _verdict(subscriber, value);
    Widget? caret = selected ? const AppCaret() : null;

    Widget cell;
    if (!finished && draft != null && draft.isNotEmpty) {
      cell = _cell(
          top: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(draft, textDirection: TextDirection.ltr, style: _number(18)),
            if (caret != null) caret,
          ]),
          bottom: low
              ? 'أقل من السابقة'
              : '${_consumptionText(subscriber, value)} ك.و.س',
          bottomColor: verdict?.color ?? _good,
          background: selected ? _surface : _raised,
          border: low
              ? _bad
              : selected
                  ? _brand
                  : _line,
          glow: selected);
    } else if (finished) {
      cell = _cell(
          top: Text(AppIdentity.reading(shownReading),
              textDirection: TextDirection.ltr, style: _number(18)),
          bottom: queued == null
              ? subscriber['reading_status'] == 'approved'
                  ? 'معتمدة'
                  : 'قيد المراجعة'
              : rejected
                  ? 'تعذر الإرسال'
                  : 'على الجهاز',
          bottomColor: rejected ? _bad : _good,
          background: rejected ? AppIdentity.badTint : AppIdentity.goodTint,
          border: Colors.transparent);
    } else {
      cell = _cell(
          top: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(selected ? 'اكتب القراءة' : 'اضغط للإدخال',
                style: _body(13, color: selected ? _brand : _faint)),
            if (caret != null) caret,
          ]),
          background: selected ? _surface : _raised,
          border: selected ? _brand : _line,
          glow: selected);
    }
    return InkWell(
      key: rowKeys.putIfAbsent(id, GlobalKey.new),
      onTap: () => _select(subscriber),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: selected ? AppIdentity.brandSoft : Colors.transparent,
          border: BorderDirectional(
              start: selected
                  ? BorderSide(color: _brand, width: 3)
                  : BorderSide.none),
        ),
        child: Row(children: [
          Container(
            width: 30,
            height: 30,
            alignment: Alignment.center,
            decoration: BoxDecoration(
                color: selected
                    ? _brand
                    : finished
                        ? AppIdentity.goodTint
                        : _sunken,
                borderRadius: BorderRadius.circular(10)),
            child: finished && !selected
                ? AppIcon('check', size: 16, color: _good)
                : Text('${index + 1}',
                    style: _number(13, color: selected ? Colors.white : _ink)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${subscriber['full_name']}',
                  style: _body(15, weight: FontWeight.w700),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis),
              Text(
                  [
                    'السابقة ${AppIdentity.reading(subscriber['previous_reading'])}',
                    if (lastWeek?['consumption'] != null)
                      'الأسبوع الماضي ${AppIdentity.reading(lastWeek!['consumption'])}',
                  ].join(' · '),
                  style: _body(12, color: _faint),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis),
            ]),
          ),
          const SizedBox(width: 10),
          cell,
        ]),
      ),
    );
  }

  Widget _cell(
          {required Widget top,
          String? bottom,
          Color? bottomColor,
          required Color background,
          required Color border,
          bool glow = false}) =>
      Container(
        width: 116,
        height: 52,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: background,
          border: Border.all(color: border, width: 1.5),
          borderRadius: BorderRadius.circular(15),
          boxShadow: glow
              ? [
                  BoxShadow(
                      color: _brand.withValues(alpha: .12), spreadRadius: 3)
                ]
              : null,
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child:
                Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              top,
              if (bottom != null)
                Text(bottom,
                    maxLines: 1,
                    style: _body(11,
                        weight: FontWeight.w700, color: bottomColor ?? _faint)),
            ]),
          ),
        ),
      );

  /// The chosen subscriber's previous and new reading side by side, shown
  /// above the keypad while typing.
  Widget _comparePanel(Map<String, dynamic> subscriber) {
    final draft = drafts[subscriber['id']];
    final value = double.tryParse(draft ?? '');
    final verdict = _verdict(subscriber, value);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 10),
      decoration: BoxDecoration(
          color: _surface,
          border: Border(top: BorderSide(color: _lineSoft)),
          boxShadow: const [
            BoxShadow(
                color: Color(0x0D101828), blurRadius: 16, offset: Offset(0, -6))
          ]),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(
            child: Text('${subscriber['full_name']}',
                style: _body(15, weight: FontWeight.w700),
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
          ),
          if (verdict != null) ...[
            const SizedBox(width: 8),
            AppIcon(verdict.icon, size: 15, color: verdict.color),
            const SizedBox(width: 4),
            Flexible(
              child: Text(verdict.text,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: _body(12.5,
                      weight: FontWeight.w700, color: verdict.color)),
            ),
          ],
        ]),
        const SizedBox(height: 9),
        AppReadingCompare(
          previous: AppIdentity.reading(subscriber['previous_reading']),
          current: draft == null || draft.isEmpty ? null : draft,
          currentHint: 'اكتب',
          consumption: _consumptionText(subscriber, value),
          alert: verdict?.color == _good ? null : verdict?.color,
          highlightCurrent: true,
        ),
      ]),
    );
  }

  Widget _saveBar() {
    final count = boxKey == null ? 0 : _inBox(boxKey!).where(_ready).length;
    final label = count == 0
        ? 'اكتب القراءات أولًا'
        : count == 1
            ? 'حفظ قراءة واحدة على الجهاز'
            : count == 2
                ? 'حفظ قراءتين على الجهاز'
                : 'حفظ $count قراءات على الجهاز';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(
          color: _background,
          border: Border(top: BorderSide(color: _lineSoft))),
      child: AppAction(
          label: label,
          onPressed: count == 0 || saving ? null : _save,
          busy: saving,
          icon: 'check'),
    );
  }

  MapEntry<String, List<Map<String, dynamic>>>? _nextUnfinishedBox() =>
      _groups()
          .entries
          .where((entry) => entry.value.any(_editable))
          .firstOrNull;

  Widget _doneView() {
    final pending = widget.store.queuedReadings
        .where((reading) =>
            savedOperationIds.contains(reading['mobile_operation_id']))
        .toList();
    final rejected = pending.any((reading) => reading['sync_error'] != null);
    final description = rejected
        ? 'حُفظت القراءات على الجهاز. تحقّق من حالة الإرسال لمعالجة القراءة المرفوضة.'
        : pending.isNotEmpty
            ? 'حُفظت على الجهاز. راجع القراءات واختر ما تريد رفعه، ثم أكّد الرفع أثناء الاتصال بالإنترنت.'
            : 'وصلت إلى النظام الأساسي بحالة «قيد المراجعة» بانتظار المدقق.';
    final next = _nextUnfinishedBox();
    return AppSuccess(
      title: 'تم حفظ القراءات',
      message: description,
      facts: [
        ('الطبلون', AppFact(savedBox, number: true)),
        ('عدد القراءات', AppFact('$savedCount', number: true)),
        (
          'الحالة',
          rejected
              ? const AppFact('تعذر الإرسال')
              : pending.isNotEmpty
                  ? const AppTag('بانتظار الإرسال', AppTone.warning)
                  : const AppTag('قيد المراجعة', AppTone.warning)
        ),
      ],
      actions: [
        if (pending.isNotEmpty)
          AppAction(
              label: 'مراجعة القراءات ورفعها',
              icon: 'cloud',
              onPressed: widget.onSync),
        if (next != null)
          AppAction(
              label: 'الطبلون التالي: ${_boxNumber(next.value.first)}',
              onPressed: () => _openBox(next.key),
              icon: 'arrow'),
        AppAction(
            label: 'العودة إلى الطبلونات',
            primary: next == null,
            onPressed: _back),
      ],
    );
  }
}
