import 'dart:async';
import 'package:flutter/material.dart';
import 'api_client.dart';
import 'app_identity.dart';
import 'search_text.dart';

class ReadingDistributionPage extends StatefulWidget {
  const ReadingDistributionPage({required this.api, super.key});
  final ApiClient api;
  @override
  State<ReadingDistributionPage> createState() =>
      _ReadingDistributionPageState();
}

class _ReadingDistributionPageState extends State<ReadingDistributionPage> {
  final search = TextEditingController();
  final selected = <int>{};
  final areas = <String>{};
  Map<String, dynamic> data = {};
  String status = 'all';
  String? error;
  int? branchId;
  int requestId = 0;
  bool busy = true;
  bool get canManage => data['abilities']?['manage'] == true;
  bool get canApprove => data['abilities']?['approve'] == true;
  List<Map<String, dynamic>> rows(String key) => (data[key] as List? ?? [])
      .map((item) => Map<String, dynamic>.from(item as Map))
      .toList();
  String areaKey(Map<String, dynamic> box) => '${box['sub_area_id'] ?? 'none'}';
  List<Map<String, dynamic>> get matching => rows('boxes')
      .where((box) =>
          (areas.isEmpty || areas.contains(areaKey(box))) &&
          (status == 'all' ||
              (status == 'available'
                  ? box['column'] == 'available'
                  : box['column'] != 'available')) &&
          matchesSearch(search.text.trim(),
              [box['name'], box['number'], box['sub_area_name']]))
      .toList();

  @override
  void initState() {
    super.initState();
    unawaited(load());
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> load() async {
    final request = ++requestId;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.readingDistribution(branchId: branchId);
      if (!mounted || request != requestId) return;
      setState(() {
        data = result;
        final valid = rows('boxes').map((box) => box['id']).toSet();
        selected.removeWhere((id) => !valid.contains(id));
      });
    } on ApiException catch (exception) {
      if (!mounted || request != requestId) return;
      setState(() {
        error = exception.statusCode == 403
            ? 'ليس لديك صلاحية توزيع القراءات لهذا الفرع.'
            : exception.message;
        if (exception.statusCode == 401 || exception.statusCode == 403) {
          data = {};
          selected.clear();
        }
      });
    } finally {
      if (mounted && request == requestId) setState(() => busy = false);
    }
  }

  Future<bool> confirm(String title, String text) async =>
      await showDialog<bool>(
          context: context,
          builder: (context) =>
              AlertDialog(title: Text(title), content: Text(text), actions: [
                TextButton(
                    onPressed: () => Navigator.pop(context, false),
                    child: const Text('إلغاء')),
                FilledButton(
                    onPressed: () => Navigator.pop(context, true),
                    child: const Text('تأكيد')),
              ])) ==
      true;

  Future<void> chooseTarget() async {
    FocusManager.instance.primaryFocus?.unfocus();
    final readers = rows('readers');
    String target = readers.isEmpty ? 'paper' : 'reader:${readers.first['id']}';
    bool recurring = canApprove;
    final choice = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => StatefulBuilder(
          builder: (context, update) => SingleChildScrollView(
              padding: EdgeInsets.fromLTRB(
                  20, 20, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
              child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text('توزيع ${selected.length} طبلون',
                        style: AppIdentity.heading(21)),
                    const SizedBox(height: 16),
                    DropdownButton<String>(
                        value: target,
                        isExpanded: true,
                        items: [
                          for (final reader in readers)
                            DropdownMenuItem(
                                value: 'reader:${reader['id']}',
                                child: Text('${reader['name']}',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis)),
                          const DropdownMenuItem(
                              value: 'paper', child: Text('كشف ورقي')),
                          const DropdownMenuItem(
                              value: 'available', child: Text('إلغاء التخصيص')),
                        ],
                        onChanged: (value) {
                          if (value != null) update(() => target = value);
                        }),
                    SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('توزيع ثابت للأسابيع القادمة'),
                        subtitle: Text(canApprove
                            ? 'يبقى التوزيع كما هو حتى تغيّره.'
                            : 'التوزيع الثابت يحتاج صلاحية اعتماد توزيع القراءات.'),
                        value: recurring,
                        onChanged: canApprove
                            ? (value) => update(() => recurring = value)
                            : null),
                    if (!canApprove)
                      const AppNotice(
                          'يُحفظ التوزيع مسودة إلى أن يعتمده المسؤول.'),
                    const SizedBox(height: 16),
                    AppAction(
                        label:
                            canApprove ? 'حفظ التوزيع واعتماده' : 'حفظ التوزيع',
                        onPressed: () => Navigator.pop(context,
                            {'target': target, 'recurring': recurring})),
                  ]))),
    );
    if (choice != null && mounted)
      await assign(selected.toList(), '${choice['target']}',
          choice['recurring'] == true);
  }

  Future<void> assign(List<int> ids, String target, bool recurring,
      {bool confirmDownloaded = false}) async {
    if (ids.isEmpty || !canManage) return;
    final cycleId = data['cycle']['id'] as int;
    final versions = {
      for (final box in rows('boxes').where((box) => ids.contains(box['id'])))
        '${box['id']}': box['version']
    };
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.distributeReadings(cycleId, {
        'box_ids': ids,
        'versions': versions,
        'target': target.startsWith('reader:') ? 'mobile' : target,
        if (target.startsWith('reader:'))
          'user_id': int.parse(target.split(':').last),
        'recurring': recurring,
        'publish': canApprove,
        'confirm_downloaded': confirmDownloaded,
      });
      if (!mounted) return;
      final conflicts = (result['conflicts'] as List? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();
      final failed = conflicts.map((item) => item['boxId']).toSet();
      setState(() {
        selected.removeWhere((id) => ids.contains(id) && !failed.contains(id));
      });
      final confirmationIds =
          List<int>.from(result['confirmation_box_ids'] as List? ?? []);
      await load();
      if (!mounted) return;
      if (confirmationIds.isNotEmpty &&
          !confirmDownloaded &&
          await confirm('نقل طبلونات نزّلها القارئ؟',
              'قد توجد قراءات لم تُرفع بعد. هل تؤكد نقل هذه الطبلونات؟')) {
        if (mounted)
          await assign(confirmationIds, target, recurring,
              confirmDownloaded: true);
        return;
      }
      if (conflicts.isNotEmpty) {
        setState(() => error = conflicts
            .map((item) => '${item['label']}: ${item['reason']}')
            .join('\n'));
      } else {
        showAppToast(context, 'تم توزيع ${result['moved']} طبلون.');
      }
    } on ApiException catch (exception) {
      if (mounted) setState(() => error = exception.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> approve() async {
    if (!await confirm('اعتماد توزيع القراءات؟',
        'سيتمكن القرّاء من تنزيل الطبلونات الموزعة عند حلول موعد إتاحتها.'))
      return;
    if (!mounted) return;
    setState(() => busy = true);
    try {
      final result = await widget.api
          .approveReadingDistribution(data['cycle']['id'] as int);
      await load();
      if (mounted)
        showAppToast(context, 'تم اعتماد ${result['approved']} طبلون.');
    } on ApiException catch (exception) {
      if (mounted) setState(() => error = exception.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  String date(String value) => value.split('-').reversed.join('/');
  String readerName(Map<String, dynamic> box) {
    if (box['column'] == 'available') return 'غير موزع';
    if (box['column'] == 'paper') return 'كشف ورقي';
    final reader = rows('readers')
        .where((reader) => 'reader:${reader['id']}' == box['column'])
        .firstOrNull;
    return '${reader?['name'] ?? 'قارئ غير متاح'}';
  }

  @override
  Widget build(BuildContext context) {
    final boxes = rows('boxes');
    final filtered = matching;
    final allSelected = filtered.isNotEmpty &&
        filtered.every((box) => selected.contains(box['id']));
    final areaNames = {
      for (final box in boxes) areaKey(box): '${box['sub_area_name']}'
    };
    final cycle = data['cycle'] as Map?;
    final branches = rows('branches');
    final draftCount = boxes.where((box) => box['status'] == 'draft').length;
    final hiddenSelected =
        selected.where((id) => !filtered.any((box) => box['id'] == id)).length;
    return AppKeyboardBackScope(
        child: Scaffold(
      body: SafeArea(
          child: Column(children: [
        AppHeader(
            title: 'توزيع القراءات',
            subtitle: data['branch']?['name'] as String?,
            onBack: () => Navigator.pop(context)),
        Expanded(
            child: RefreshIndicator(
                onRefresh: load,
                child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                    children: [
                      if (branches.isNotEmpty)
                        DropdownButton<int>(
                            isExpanded: true,
                            value: branchId,
                            hint: const Text('اختر الفرع'),
                            items: branches
                                .map((branch) => DropdownMenuItem<int>(
                                    value: branch['id'] as int,
                                    child: Text('${branch['name']}')))
                                .toList(),
                            onChanged: busy
                                ? null
                                : (id) {
                                    setState(() {
                                      branchId = id;
                                      data = {};
                                      areas.clear();
                                      selected.clear();
                                    });
                                    unawaited(load());
                                  }),
                      if (cycle != null) ...[
                        AppNotice(
                            'القراءة القادمة: ${date('${cycle['reading_date']}')}\nتتاح للقارئ: ${date('${cycle['available_on']}')}'),
                        const SizedBox(height: 12),
                        AppSearchField(
                            controller: search,
                            hint: 'ابحث بالمنطقة أو اسم الطبلون ورقمه',
                            onChanged: (_) => setState(() {}),
                            onClear: () => setState(search.clear)),
                        const SizedBox(height: 12),
                        Wrap(spacing: 6, runSpacing: 6, children: [
                          for (final option in [
                            ('all', 'الكل'),
                            ('available', 'غير موزع'),
                            ('assigned', 'موزع')
                          ])
                            AppChip(option.$2, selected: status == option.$1,
                                onTap: () {
                              if (!busy) setState(() => status = option.$1);
                            })
                        ]),
                        const AppSection('منطقة 2'),
                        Wrap(spacing: 6, runSpacing: 6, children: [
                          AppChip('كل المناطق', selected: areas.isEmpty,
                              onTap: () {
                            if (!busy) setState(areas.clear);
                          }),
                          for (final area in areaNames.entries)
                            AppChip(area.value,
                                selected: areas.contains(area.key), onTap: () {
                              if (!busy)
                                setState(() {
                                  areas.contains(area.key)
                                      ? areas.remove(area.key)
                                      : areas.add(area.key);
                                });
                            }),
                        ]),
                        AppSection('الطبلونات',
                            note: '${filtered.length} نتيجة'),
                        if (canManage)
                          CheckboxListTile(
                              contentPadding: EdgeInsets.zero,
                              title: const Text('تحديد كل النتائج'),
                              value: allSelected,
                              onChanged: busy || filtered.isEmpty
                                  ? null
                                  : (_) => setState(() {
                                        final ids = filtered
                                            .map((box) => box['id'] as int);
                                        allSelected
                                            ? selected.removeAll(ids)
                                            : selected.addAll(ids);
                                      })),
                        if (canApprove && draftCount > 0)
                          Padding(
                              padding: const EdgeInsets.only(bottom: 12),
                              child: AppAction(
                                  label: 'اعتماد التوزيع ($draftCount)',
                                  busy: busy,
                                  onPressed: busy ? null : approve)),
                        if (filtered.isEmpty && !busy)
                          const AppEmpty('search', 'لا توجد طبلونات مطابقة',
                              'غيّر البحث أو حالة التوزيع أو المنطقة.'),
                        if (filtered.isNotEmpty)
                          AppRows(children: [
                            for (final box in filtered)
                              AppRow(
                                key: ValueKey('distribution-box-${box['id']}'),
                                leading: canManage
                                    ? Checkbox(
                                        value: selected.contains(box['id']),
                                        onChanged: busy
                                            ? null
                                            : (_) => setState(() {
                                                  selected.contains(box['id'])
                                                      ? selected
                                                          .remove(box['id'])
                                                      : selected.add(
                                                          box['id'] as int);
                                                }))
                                    : null,
                                title: Text('${box['name']} (${box['number']})',
                                    style: AppIdentity.body(15,
                                        weight: FontWeight.w700)),
                                subtitle: Text(
                                    '${box['sub_area_name']} · ${box['subscribers']} مشترك\n${readerName(box)}${box['status'] == 'draft' ? ' · بانتظار اعتماد التوزيع' : ''}',
                                    style: AppIdentity.body(13,
                                        color: AppIdentity.muted)),
                              )
                          ]),
                      ],
                      if (error != null)
                        Padding(
                            padding: const EdgeInsets.only(top: 12),
                            child: AppNotice(error!, warning: true)),
                      if (busy)
                        const Padding(
                            padding: EdgeInsets.all(24),
                            child: Center(child: CircularProgressIndicator())),
                      if (!busy && cycle == null && error == null)
                        const AppEmpty('bolt', 'لا توجد قراءة مجهزة',
                            'اختر الفرع أو اطلب تجهيز توزيع القراءة القادمة.'),
                    ]))),
      ])),
      bottomNavigationBar: canManage && selected.isNotEmpty
          ? SafeArea(
              child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    if (hiddenSelected > 0)
                      Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: Text(
                              '$hiddenSelected طبلون محدد خارج الفلتر الحالي')),
                    AppAction(
                        label: 'توزيع ${selected.length} طبلون',
                        busy: busy,
                        onPressed: busy ? null : chooseTarget),
                    TextButton(
                        onPressed: busy ? null : () => setState(selected.clear),
                        child: const Text('إلغاء التحديد')),
                  ])))
          : null,
    ));
  }
}
