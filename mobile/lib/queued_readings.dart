import 'package:flutter/material.dart';
import 'app_identity.dart';
import 'search_text.dart';

Map<String, dynamic> readingReviewData(
    Map<String, dynamic> reading, List<Map<String, dynamic>> subscribers) {
  if (reading['review'] is Map)
    return Map<String, dynamic>.from(reading['review'] as Map);
  final subscriber = subscribers
      .where((item) => item['id'] == reading['subscription_id'])
      .firstOrNull;
  return {
    if (subscriber != null) ...subscriber,
    if (reading['review'] is Map)
      ...Map<String, dynamic>.from(reading['review'] as Map)
  };
}

String? readingValidation(Map<String, dynamic> reading) {
  final value = double.tryParse('${reading['current_reading']}');
  if (value == null || !value.isFinite || value < 0 || value > 9999999999.99)
    return 'أدخل قراءة صحيحة بين صفر و9,999,999,999.99.';
  if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch('${reading['current_reading']}'))
    return 'القراءة تقبل منزلتين عشريتين كحد أقصى.';
  final review = reading['review'];
  final previous =
      review is Map ? double.tryParse('${review['previous_reading']}') : null;
  if (previous != null && value < previous)
    return 'القراءة الحالية أقل من السابقة. صححها قبل الرفع.';
  return null;
}

class ReadingUploadReport {
  const ReadingUploadReport(
      {this.sent = 0,
      this.held = 0,
      this.failed = 0,
      this.message,
      this.reviewNotes = const [],
      this.interrupted = false});
  final int sent;
  final int held;
  final int failed;
  final String? message;
  final List<String> reviewNotes;
  final bool interrupted;
  bool get warning => interrupted || held > 0 || failed > 0;
  String get summary => [
        if (sent > 0) 'تم رفع ${AppIdentity.readingsCount(sent)}.',
        if (held > 0)
          '${AppIdentity.readingsCount(held)} تحتاج مراجعة المسؤول.',
        if (failed > 0)
          '${AppIdentity.readingsCount(failed)} لم تُرفع. راجع التوضيح بجانبها.',
        if (message != null) message!,
        ...reviewNotes,
      ].join('\n');
}

/// Uploads require explicit review; reconnecting only refreshes data.
class ReadingReview extends StatefulWidget {
  const ReadingReview(
      {required this.queue,
      required this.subscribers,
      required this.canUpload,
      required this.canEdit,
      required this.online,
      required this.busy,
      required this.onUpload,
      required this.onRefresh,
      required this.onReenter,
      required this.onDiscard,
      super.key});
  final List<Map<String, dynamic>> queue;
  final List<Map<String, dynamic>> subscribers;
  final bool canUpload;
  final bool Function(Map<String, dynamic>) canEdit;
  final bool online;
  final bool busy;
  final Future<ReadingUploadReport> Function(List<Map<String, dynamic>>)
      onUpload;
  final Future<void> Function() onRefresh;
  final ValueChanged<Map<String, dynamic>> onReenter;
  final ValueChanged<Map<String, dynamic>> onDiscard;
  @override
  State<ReadingReview> createState() => _ReadingReviewState();
}

class _ReadingReviewState extends State<ReadingReview> {
  final search = TextEditingController();
  final selected = <String>{};
  String filter = 'all';
  bool confirming = false;
  ReadingUploadReport? report;
  bool get busy => widget.busy || confirming;
  String id(Map<String, dynamic> reading) =>
      '${reading['mobile_operation_id']}';
  String? error(Map<String, dynamic> reading) =>
      reading['sync_error'] as String? ?? readingValidation(reading);
  List<Map<String, dynamic>> get ready =>
      widget.queue.where((reading) => error(reading) == null).toList();
  @override
  void didUpdateWidget(covariant ReadingReview oldWidget) {
    super.didUpdateWidget(oldWidget);
    final valid = ready.map(id).toSet();
    selected.removeWhere((value) => !valid.contains(value));
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> review(List<Map<String, dynamic>> readings) async {
    if (busy || readings.isEmpty || !widget.canUpload || !widget.online) return;
    setState(() => confirming = true);
    try {
      final confirmed = await showAppSheet<bool>(context,
          title:
              'مراجعة ${AppIdentity.readingsCount(readings.length)} قبل الرفع',
          message:
              'تأكد من السابقة والحالية والفرق لكل قراءة. لن تُرفع إلا القراءات المعروضة هنا بعد تأكيدك.',
          children: [
            SizedBox(
                height: 240,
                child: ListView.separated(
                    itemCount: readings.length,
                    separatorBuilder: (_, __) =>
                        Divider(color: AppIdentity.line),
                    itemBuilder: (_, index) => ReadingReviewRow(
                        reading: readings[index],
                        subscribers: widget.subscribers))),
            const SizedBox(height: 16),
            Builder(
                builder: (context) => AppAction(
                    key: const ValueKey('confirm-reading-upload'),
                    label:
                        'تأكيد رفع ${AppIdentity.readingsCount(readings.length)}',
                    icon: 'cloud',
                    onPressed: () => Navigator.pop(context, true))),
            const SizedBox(height: 8),
            Builder(
                builder: (context) => AppAction(
                    label: 'العودة للمراجعة',
                    primary: false,
                    onPressed: () => Navigator.pop(context, false))),
          ]);
      if (confirmed != true || !mounted) return;
      final result = await widget.onUpload(readings);
      if (mounted) {
        setState(() => report = result);
        if (result.summary.isNotEmpty)
          showAppToast(context, result.summary.split('\n').first,
              icon: result.warning ? 'info' : 'check');
      }
    } finally {
      if (mounted) setState(() => confirming = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final matching = widget.queue.where((reading) {
      final data = readingReviewData(reading, widget.subscribers);
      return (filter == 'all' ||
              (filter == 'ready'
                  ? error(reading) == null
                  : error(reading) != null)) &&
          matchesSearch(search.text.trim(), [
            data['name'] ?? data['full_name'],
            data['account_number'],
            data['meter_box_number'],
            data['sub_area_name'],
            reading['week_start']
          ]);
    }).toList();
    final selectable =
        matching.where((reading) => error(reading) == null).toList();
    final allSelected = selectable.isNotEmpty &&
        selectable.every((reading) => selected.contains(id(reading)));
    final chosen =
        ready.where((reading) => selected.contains(id(reading))).toList();
    final visibleIds = matching.map(id).toSet();
    final hiddenSelected =
        chosen.where((reading) => !visibleIds.contains(id(reading))).length;
    return Column(children: [
      Expanded(
          child: RefreshIndicator(
              onRefresh: widget.onRefresh,
              color: AppIdentity.brand,
              child: ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 18),
                  itemCount: matching.length + 1,
                  separatorBuilder: (_, index) =>
                      SizedBox(height: index == 0 ? 16 : 2),
                  itemBuilder: (context, index) {
                    if (index > 0) {
                      final reading = matching[index - 1];
                      final valid = error(reading) == null;
                      return Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: AppRows(children: [
                            ReadingReviewRow(
                              key: ValueKey('queued-reading-${id(reading)}'),
                              reading: reading,
                              subscribers: widget.subscribers,
                              selected: selected.contains(id(reading)),
                              onToggle: busy || !valid || !widget.canUpload
                                  ? null
                                  : () => setState(() {
                                        selected.contains(id(reading))
                                            ? selected.remove(id(reading))
                                            : selected.add(id(reading));
                                      }),
                              onUpload: busy ||
                                      !valid ||
                                      !widget.online ||
                                      !widget.canUpload
                                  ? null
                                  : () => review([reading]),
                              onEdit: busy ||
                                      !widget.canEdit(reading) ||
                                      reading['upload_uncertain'] == true
                                  ? null
                                  : () => widget.onReenter(reading),
                              onDiscard: busy ||
                                      reading['upload_uncertain'] == true ||
                                      reading['sync_error'] == null
                                  ? null
                                  : () => widget.onDiscard(reading),
                              showControls: true,
                            )
                          ]));
                    }
                    return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const AppNotice(
                              'القراءات محفوظة على الجهاز. عودة الإنترنت لا ترفعها تلقائيًا؛ راجعها ثم اختر ما تريد رفعه.'),
                          const SizedBox(height: 12),
                          Row(children: [
                            Expanded(
                                child: AppStat(
                                    'بانتظار الرفع', '${widget.queue.length}')),
                            const SizedBox(width: 8),
                            Expanded(
                                child: AppStat('تحتاج تصحيحًا',
                                    '${widget.queue.length - ready.length}',
                                    color: widget.queue.length > ready.length
                                        ? AppIdentity.bad
                                        : null))
                          ]),
                          if (report != null && report!.summary.isNotEmpty)
                            Padding(
                                padding: const EdgeInsets.only(top: 12),
                                child: AppNotice(report!.summary,
                                    warning: report!.warning)),
                          if (!widget.online)
                            const Padding(
                                padding: EdgeInsets.only(top: 12),
                                child: AppNotice(
                                    'اتصل بالإنترنت واسحب للتحديث حتى تتمكن من رفع القراءات.',
                                    warning: true)),
                          if (!widget.canUpload && widget.queue.isNotEmpty)
                            const Padding(
                                padding: EdgeInsets.only(top: 12),
                                child: AppNotice(
                                    'صلاحية رفع القراءات غير متاحة لحسابك. تواصل مع المسؤول.',
                                    warning: true)),
                          if (widget.queue.isNotEmpty) ...[
                            const SizedBox(height: 12),
                            AppSearchField(
                                controller: search,
                                hint: 'ابحث بالمشترك أو الطبلون أو منطقة 2',
                                onChanged: (_) => setState(() {}),
                                onClear: () => setState(search.clear)),
                            const SizedBox(height: 10),
                            Wrap(spacing: 6, runSpacing: 6, children: [
                              for (final option in [
                                ('all', 'الكل'),
                                ('ready', 'جاهزة للرفع'),
                                ('errors', 'تحتاج تصحيحًا')
                              ])
                                AppChip(option.$2,
                                    selected: filter == option.$1,
                                    onTap: () =>
                                        setState(() => filter = option.$1))
                            ]),
                            CheckboxListTile(
                                contentPadding: EdgeInsets.zero,
                                title: const Text('تحديد كل النتائج الجاهزة'),
                                value: allSelected,
                                onChanged: busy ||
                                        selectable.isEmpty ||
                                        !widget.canUpload
                                    ? null
                                    : (_) => setState(() {
                                          allSelected
                                              ? selected
                                                  .removeAll(selectable.map(id))
                                              : selected
                                                  .addAll(selectable.map(id));
                                        })),
                          ],
                          if (matching.isEmpty)
                            AppRows(children: [
                              AppEmpty(
                                  widget.queue.isEmpty ? 'check' : 'search',
                                  widget.queue.isEmpty
                                      ? 'لا توجد قراءات بانتظار الرفع'
                                      : 'لا توجد قراءات مطابقة',
                                  widget.queue.isEmpty
                                      ? 'تظهر هنا القراءات التي تحفظها على الجهاز لتراجعها قبل رفعها.'
                                      : 'غيّر البحث أو حالة القراءة.',
                                  good: widget.queue.isEmpty)
                            ]),
                        ]);
                  }))),
      if (ready.isNotEmpty)
        Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              if (hiddenSelected > 0)
                Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child:
                        Text('$hiddenSelected قراءة محددة خارج البحث الحالي.')),
              AppAction(
                  key: const ValueKey('upload-selected-readings'),
                  label: 'مراجعة ورفع المحدد (${chosen.length})',
                  busy: widget.busy,
                  onPressed: busy ||
                          chosen.isEmpty ||
                          !widget.online ||
                          !widget.canUpload
                      ? null
                      : () => review(chosen)),
              TextButton(
                  key: const ValueKey('upload-all-readings'),
                  onPressed: busy || !widget.online || !widget.canUpload
                      ? null
                      : () => review(ready),
                  child: Text(
                      'مراجعة ورفع كل القراءات الجاهزة (${ready.length})')),
            ])),
    ]);
  }
}

class ReadingReviewRow extends StatelessWidget {
  const ReadingReviewRow(
      {required this.reading,
      required this.subscribers,
      this.selected = false,
      this.onToggle,
      this.onUpload,
      this.onEdit,
      this.onDiscard,
      this.showControls = false,
      super.key});
  final Map<String, dynamic> reading;
  final List<Map<String, dynamic>> subscribers;
  final bool selected;
  final bool showControls;
  final VoidCallback? onToggle;
  final VoidCallback? onUpload;
  final VoidCallback? onEdit;
  final VoidCallback? onDiscard;
  @override
  Widget build(BuildContext context) {
    final data = readingReviewData(reading, subscribers);
    final id = '${reading['mobile_operation_id']}';
    final previous = double.tryParse('${data['previous_reading']}');
    final current = double.tryParse('${reading['current_reading']}');
    final difference = previous != null && current != null
        ? ((current - previous) * 100).round() / 100
        : null;
    final error = reading['sync_error'] ?? readingValidation(reading);
    final uncertain = reading['upload_uncertain'] == true;
    return AppRow(
      crossAxisAlignment: CrossAxisAlignment.start,
      leading: showControls
          ? Checkbox(
              key: ValueKey('select-reading-$id'),
              value: selected,
              onChanged: onToggle == null ? null : (_) => onToggle!())
          : null,
      title: Text(
          '${data['name'] ?? data['full_name'] ?? 'مشترك #${reading['subscription_id']}'}',
          style: AppIdentity.body(15, weight: FontWeight.w700)),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        AppRowNote([
          if (data['account_number'] != null) 'حساب ${data['account_number']}',
          if (data['meter_box_number'] != null)
            'طبلون ${data['meter_box_number']}',
          if (data['sub_area_name'] != null) '${data['sub_area_name']}'
        ].join(' · ')),
        if (reading['week_start'] != null)
          AppRowNote('أسبوع ${AppIdentity.shortDate(reading['week_start'])}'),
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: LayoutBuilder(builder: (context, constraints) {
              final values = [
                (
                  'السابقة',
                  previous == null ? '—' : AppIdentity.reading(previous)
                ),
                ('الحالية', AppIdentity.reading(current)),
                (
                  'الفرق (كيلو)',
                  difference == null ? '—' : AppIdentity.reading(difference)
                ),
              ];
              if (constraints.maxWidth < 330 ||
                  MediaQuery.textScalerOf(context).scale(1) > 1.3 ||
                  values.any((value) => value.$2.length > 9)) {
                return Column(children: [
                  for (final value in values)
                    Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(children: [
                          Expanded(
                              child: Text(value.$1,
                                  style: AppIdentity.body(13,
                                      color: AppIdentity.muted))),
                          Flexible(
                              child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  child: Text(value.$2,
                                      textDirection: TextDirection.ltr,
                                      style: AppIdentity.number(17,
                                          weight: FontWeight.w700,
                                          color: value.$1 == 'الحالية'
                                              ? AppIdentity.brand
                                              : AppIdentity.ink)))),
                        ]))
                ]);
              }
              return AppReadingCompare(
                  previous: values[0].$2,
                  current: values[1].$2,
                  currentLabel: 'الحالية',
                  consumption: values[2].$2,
                  highlightCurrent: true);
            })),
        if (data['warning'] != null)
          Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text('${data['warning']}',
                  style: AppIdentity.body(12.5, color: AppIdentity.warning))),
        if (error != null)
          Text('$error', style: AppIdentity.body(12.5, color: AppIdentity.bad)),
        if (uncertain)
          Text(
              'نتيجة الرفع غير مؤكدة. أعد المحاولة بنفس القراءة؛ التعديل متوقف حتى تصل النتيجة.',
              style: AppIdentity.body(12.5, color: AppIdentity.warning)),
        if (showControls)
          Wrap(
              spacing: 6,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TextButton.icon(
                    key: ValueKey('upload-reading-$id'),
                    onPressed: onUpload,
                    icon: const AppIcon('cloud', size: 18),
                    label: Text(uncertain ? 'إعادة الرفع' : 'رفع')),
                TextButton.icon(
                    key: ValueKey('edit-reading-$id'),
                    onPressed: onEdit,
                    icon: const AppIcon('edit', size: 18),
                    label: const Text('تعديل')),
                if (reading['sync_error'] != null)
                  TextButton.icon(
                      onPressed: onDiscard,
                      icon: const AppIcon('trash', size: 18),
                      label: const Text('حذف المرفوضة')),
              ]),
      ]),
    );
  }
}
