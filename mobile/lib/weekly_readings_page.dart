import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';

class WeeklyReadingsPage extends StatefulWidget {
  const WeeklyReadingsPage({required this.api, super.key});
  final ApiClient api;

  @override
  State<WeeklyReadingsPage> createState() => _WeeklyReadingsPageState();
}

class _WeeklyReadingsPageState extends State<WeeklyReadingsPage> {
  final search = TextEditingController();
  Timer? debounce;
  int requestId = 0;
  int page = 0;
  int lastPage = 1;
  int total = 0;
  bool busy = true;
  String? error;
  String? week;
  String subscriptionStatus = 'active';
  List<Map<String, dynamic>> weeks = [];
  List<Map<String, dynamic>> subscribers = [];

  @override
  void initState() {
    super.initState();
    unawaited(load());
  }

  @override
  void dispose() {
    debounce?.cancel();
    search.dispose();
    super.dispose();
  }

  void searchChanged(String _) {
    debounce?.cancel();
    requestId++;
    setState(() {
      subscribers = [];
      busy = true;
      error = null;
      page = 0;
      total = 0;
    });
    debounce =
        Timer(const Duration(milliseconds: 300), () => unawaited(load()));
  }

  Future<void> load({bool more = false}) async {
    if (more && (busy || page >= lastPage)) return;
    debounce?.cancel();
    final request = ++requestId;
    final nextPage = more ? page + 1 : 1;
    setState(() {
      busy = true;
      error = null;
      if (!more) {
        subscribers = [];
        page = 0;
        total = 0;
      }
    });
    try {
      final result = await widget.api.weeklyReadings(
          search: search.text.trim(),
          week: week,
          subscriptionStatus: subscriptionStatus,
          page: nextPage);
      if (!mounted || request != requestId) return;
      setState(() {
        week = result['week_start'] as String;
        weeks = (result['week_options'] as List)
            .map((item) => Map<String, dynamic>.from(item as Map))
            .toList();
        final rows = (result['data'] as List)
            .map((item) => Map<String, dynamic>.from(item as Map))
            .toList();
        subscribers = more ? [...subscribers, ...rows] : rows;
        page = result['current_page'] as int;
        lastPage = result['last_page'] as int;
        total = result['total'] as int;
      });
    } on ApiException catch (exception) {
      if (!mounted || request != requestId) return;
      setState(() {
        error = exception.statusCode == 403
            ? 'ليس لديك صلاحية عرض القراءات. اطلب تفعيل صلاحية عرض قراءات العدادات.'
            : exception.statusCode == 401
                ? 'انتهت جلسة الدخول. ارجع وسجّل الدخول مجددًا.'
                : exception.message;
        if (exception.statusCode == 401 || exception.statusCode == 403) {
          subscribers = [];
          page = 0;
          total = 0;
        }
      });
    } finally {
      if (mounted && request == requestId) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AppKeyboardBackScope(
          child: Scaffold(
              body: SafeArea(
                  child: Column(children: [
        AppHeader(
            title: 'القراءات الأسبوعية',
            subtitle: 'قراءات المشتركين · عرض فقط',
            onBack: () {
              if (!dismissAppKeyboard(context)) Navigator.pop(context);
            }),
        Expanded(
            child: RefreshIndicator(
                color: AppIdentity.brand,
                onRefresh: load,
                child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    keyboardDismissBehavior:
                        ScrollViewKeyboardDismissBehavior.onDrag,
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                    children: [
                      if (weeks.isNotEmpty) weekPicker(),
                      const SizedBox(height: 10),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(children: [
                          for (final option in const [
                            ('active', 'النشط'),
                            ('disconnected', 'المفصول'),
                            ('suspended', 'الموقوف'),
                            ('all', 'كل الحالات')
                          ])
                            Padding(
                              padding: const EdgeInsetsDirectional.only(end: 8),
                              child: FilterChip(
                                key: ValueKey('weekly-status-${option.$1}'),
                                label: Text(option.$2),
                                selected: subscriptionStatus == option.$1,
                                onSelected: (_) {
                                  setState(
                                      () => subscriptionStatus = option.$1);
                                  unawaited(load());
                                },
                              ),
                            ),
                        ]),
                      ),
                      const SizedBox(height: 10),
                      AppSearchField(
                          controller: search,
                          hint: 'اسم المشترك أو رقم الحساب أو الطبلون',
                          maxLength: 100,
                          onChanged: searchChanged,
                          onSubmitted: (_) => unawaited(load()),
                          onClear: () {
                            search.clear();
                            unawaited(load());
                          }),
                      AppSection('$total مشترك'),
                      if (busy)
                        Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: ClipRRect(
                                borderRadius: BorderRadius.circular(4),
                                child: LinearProgressIndicator(
                                    minHeight: 3,
                                    color: AppIdentity.brand,
                                    backgroundColor: AppIdentity.sunken))),
                      if (error != null) ...[
                        AppNotice(error!, error: true),
                        TextButton(
                            onPressed: busy ? null : () => unawaited(load()),
                            child: const Text('إعادة المحاولة')),
                      ],
                      if (!busy && error == null && subscribers.isEmpty)
                        AppRows(children: [
                          Padding(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 18, vertical: 28),
                              child: Text('لا توجد نتائج',
                                  textAlign: TextAlign.center,
                                  style: AppIdentity.body(13,
                                      color: AppIdentity.faint)))
                        ]),
                      for (final subscriber in subscribers)
                        Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: readingCard(subscriber)),
                      if (page > 0 && page < lastPage)
                        AppAction(
                            label: 'عرض المزيد',
                            primary: false,
                            busy: busy,
                            onPressed: busy
                                ? null
                                : () => unawaited(load(more: true))),
                    ]))),
      ]))));

  Widget weekPicker() => Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(
            color: AppIdentity.surface,
            border: Border.all(color: AppIdentity.line, width: 1.5),
            borderRadius: BorderRadius.circular(15)),
        child: Row(children: [
          AppIcon('clock', color: AppIdentity.faint),
          const SizedBox(width: 8),
          Expanded(
            child: DropdownButtonHideUnderline(
              child: DropdownButton<String>(
                key: ValueKey(week),
                value: weeks.any((option) => option['value'] == week)
                    ? week
                    : null,
                isExpanded: true,
                dropdownColor: AppIdentity.surface,
                icon: AppIcon('down', color: AppIdentity.faint),
                style: AppIdentity.body(14, weight: FontWeight.w700),
                items: [
                  for (final option in weeks)
                    DropdownMenuItem(
                        value: option['value'] as String,
                        child: Text('${option['label']}',
                            overflow: TextOverflow.ellipsis,
                            style:
                                AppIdentity.body(14, weight: FontWeight.w700))),
                ],
                onChanged: (value) {
                  if (value == null) return;
                  setState(() => week = value);
                  unawaited(load());
                },
              ),
            ),
          ),
        ]),
      );

  Widget readingCard(Map<String, dynamic> subscriber) {
    final status = subscriber['status'];
    final (statusLabel, tone, icon) = switch (status) {
      'approved' => ('معتمدة', AppTone.good, 'check'),
      'pending' => ('بانتظار الاعتماد', AppTone.warning, 'clock'),
      _ => ('لم تُدخل قراءة', AppTone.muted, 'info'),
    };
    return Container(
        padding: const EdgeInsets.all(14),
        decoration: AppIdentity.card(radius: 20),
        child:
            Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text('${subscriber['full_name']}',
                      style: AppIdentity.body(15.5, weight: FontWeight.w700)),
                  Text(
                      'حساب ${subscriber['account_number']} · طبلون ${subscriber['meter_box_number'] ?? '—'}',
                      style: AppIdentity.body(12, color: AppIdentity.faint)),
                ])),
            const SizedBox(width: 8),
            AppTag(statusLabel, tone, icon: icon),
          ]),
          const SizedBox(height: 10),
          if (subscriber['subscription_status_label'] != null) ...[
            Text('حالة المشترك: ${subscriber['subscription_status_label']}',
                style: AppIdentity.body(12, color: AppIdentity.muted)),
            const SizedBox(height: 10),
          ],
          AppReadingCompare(
              previous: AppIdentity.reading(subscriber['previous_reading']),
              current: subscriber['current_reading'] == null
                  ? null
                  : AppIdentity.reading(subscriber['current_reading']),
              currentLabel: 'الحالية',
              consumption: subscriber['consumption'] == null
                  ? null
                  : AppIdentity.reading(subscriber['consumption'])),
          if (subscriber['amount_due'] != null) ...[
            const SizedBox(height: 10),
            const AppDashedLine(),
            Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Row(children: [
                  Expanded(
                      child:
                          Text('قيمة الأسبوع', style: AppIdentity.body(13.5))),
                  Text('${AppIdentity.grouped(subscriber['amount_due'])} ₪',
                      style: AppIdentity.number(13.5)),
                ])),
          ],
        ]));
  }
}
