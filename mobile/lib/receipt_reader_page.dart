import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app_identity.dart';
import 'receipt_scan.dart';

/// The payment fields a collector can fill from a receipt's text.
enum ReceiptField {
  amount('المبلغ', 'amount'),
  reference('الرقم المرجعي', 'reference_number'),
  sender('اسم المحوِّل', 'sender_name');

  const ReceiptField(this.label, this.key);
  final String label;
  final String key;
}

/// Reads a receipt photo on the phone and lets the collector tap the text
/// they need into the payment form. Nothing is uploaded; the page returns
/// the chosen values keyed by [ReceiptField.key].
class ReceiptReaderPage extends StatefulWidget {
  const ReceiptReaderPage(
      {required this.image,
      required this.scanner,
      this.reader = const DeviceReceiptTextReader(),
      super.key});
  final ReceiptImage image;
  final ReceiptScanner scanner;
  final ReceiptTextReader reader;

  @override
  State<ReceiptReaderPage> createState() => _ReceiptReaderPageState();
}

class _ReceiptReaderPageState extends State<ReceiptReaderPage> {
  final picked = <ReceiptField, String>{};
  List<String>? lines;
  String? processedPath;
  String? error;
  bool contrast = true;
  bool busy = false;
  int rotation = 0;

  Future<void> rotate() async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await widget.scanner.rotate(widget.image);
      await FileImage(File(widget.image.previewPath)).evict();
      if (mounted) setState(() => rotation++);
    } on PlatformException catch (exception) {
      if (mounted) setState(() => error = exception.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> readText() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final path =
          await widget.scanner.prepare(widget.image, contrast: contrast);
      final found = await widget.reader.read(path);
      if (mounted)
        setState(() {
          processedPath = path;
          lines = found;
        });
    } on MissingPluginException {
      if (mounted)
        setState(() => error = 'قراءة الإيصالات متاحة في تطبيق Android فقط.');
    } on PlatformException catch (exception) {
      if (mounted)
        setState(() => error = exception.message ??
            'تعذر قراءة الصورة. جرّب صورة أوضح أو أدخل البيانات يدويًا.');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> chooseFrom(String line) async {
    final pieces = receiptLinePieces(line);
    final choice = await showAppSheet<(String, ReceiptField?)>(context,
        title: 'اختر الجزء المطلوب', children: [_PieceSheet(pieces: pieces)]);
    if (choice == null || !mounted) return;
    final (piece, field) = choice;
    if (field == null) {
      await Clipboard.setData(ClipboardData(text: piece));
      if (mounted) showAppToast(context, 'نُسخ: $piece');
      return;
    }
    setState(() => picked[field] =
        field == ReceiptField.amount ? receiptAmount(piece)! : piece);
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: !busy,
        child: Scaffold(
            body: SafeArea(
                child: Column(children: [
          AppHeader(
              title: lines == null ? 'قراءة الإيصال' : 'اختر من نص الإيصال',
              subtitle: lines == null
                  ? 'حدد الإيصال ثم اقرأ النص'
                  : 'اضغط على السطر ثم اختر الحقل',
              onBack: busy ? null : () => Navigator.pop(context)),
          Expanded(
              child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                  children: lines == null ? cropStep() : pickStep())),
          Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              decoration: BoxDecoration(
                  color: AppIdentity.background,
                  border: Border(top: BorderSide(color: AppIdentity.lineSoft))),
              child: lines == null
                  ? AppAction(
                      key: const ValueKey('receipt-read'),
                      label: busy ? 'جارٍ قراءة النص...' : 'قراءة النص',
                      icon: 'scan',
                      busy: busy,
                      onPressed: busy ? null : readText)
                  : AppAction(
                      key: const ValueKey('receipt-use'),
                      label: picked.isEmpty
                          ? 'اختر قيمة واحدة على الأقل'
                          : 'استخدام ${picked.length == 1 ? 'قيمة واحدة' : picked.length == 2 ? 'قيمتين' : '${picked.length} قيم'}',
                      icon: 'check',
                      onPressed: picked.isEmpty
                          ? null
                          : () => Navigator.pop(context, {
                                for (final entry in picked.entries)
                                  entry.key.key: entry.value
                              }))),
        ]))),
      );

  Widget _chip(String label, {String? icon, bool? on, VoidCallback? onTap}) =>
      Opacity(
        opacity: onTap == null ? .5 : 1,
        child: Material(
          color: on == true ? AppIdentity.selected : AppIdentity.surface,
          shape: StadiumBorder(
              side: BorderSide(
                  color: on == true ? AppIdentity.selected : AppIdentity.line,
                  width: 1.5)),
          child: InkWell(
            customBorder: const StadiumBorder(),
            onTap: onTap,
            child: Container(
              height: 36,
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                if (icon != null) ...[
                  AppIcon(icon, size: 16, color: AppIdentity.muted),
                  const SizedBox(width: 6),
                ],
                Text(label,
                    style: AppIdentity.body(13,
                        weight: FontWeight.w700,
                        color: on == true
                            ? AppIdentity.selectedInk
                            : AppIdentity.muted)),
              ]),
            ),
          ),
        ),
      );

  List<Widget> cropStep() => [
        const AppNotice(
            'حرّك الزوايا حول الإيصال. تُقرأ الصورة على الجوال ولا تُرفع إلى النظام.'),
        const SizedBox(height: 12),
        ClipRRect(
            borderRadius: BorderRadius.circular(20), child: cropPreview()),
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            _chip('تدوير', icon: 'rotate', onTap: busy ? null : rotate),
            _chip('الصورة كاملة',
                onTap: busy
                    ? null
                    : () => setState(
                        () => widget.image.corners = [0, 0, 1, 0, 1, 1, 0, 1])),
            _chip('تحسين التباين',
                on: contrast,
                onTap:
                    busy ? null : () => setState(() => contrast = !contrast)),
          ]),
        ),
        if (error != null) ...[
          const SizedBox(height: 12),
          AppNotice(error!, error: true),
        ],
      ];

  List<Widget> pickStep() => [
        ClipRRect(
            borderRadius: BorderRadius.circular(20),
            child: Container(
                color: const Color(0xFFD9DDE2),
                child: Image.file(
                    File(processedPath ?? widget.image.previewPath),
                    height: 160,
                    width: double.infinity,
                    fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) => const SizedBox.shrink()))),
        if (lines!.isEmpty) ...[
          const SizedBox(height: 12),
          const AppNotice(
              'لم يُعثر على نص في الصورة. ارجع وجرّب صورة أوضح، أو أدخل البيانات يدويًا.',
              error: true),
        ] else ...[
          AppSection('النص المقروء', note: '${lines!.length} سطر'),
          Column(children: [
            for (final (index, line) in lines!.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: _lineTile(index, line),
              ),
          ]),
        ],
        const AppSection('القيم المختارة'),
        Column(children: [
          for (final field in ReceiptField.values)
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                  color: AppIdentity.raised,
                  borderRadius: BorderRadius.circular(12)),
              child: Row(children: [
                Expanded(
                    child: Text(field.label, style: AppIdentity.body(13.5))),
                Flexible(
                    flex: 2,
                    child: Text(picked[field] ?? '—',
                        key: ValueKey('receipt-picked-${field.key}'),
                        textAlign: TextAlign.end,
                        overflow: TextOverflow.ellipsis,
                        style: AppIdentity.number(13.5)
                            .copyWith(fontFamilyFallback: ['PlexArabic']))),
                if (picked[field] != null)
                  InkWell(
                      onTap: () => setState(() => picked.remove(field)),
                      child: Tooltip(
                          message: 'مسح',
                          child: Padding(
                              padding:
                                  const EdgeInsetsDirectional.only(start: 8),
                              child: AppIcon('x',
                                  size: 16, color: AppIdentity.muted)))),
              ]),
            ),
        ]),
        const SizedBox(height: 6),
        const AppNotice(
            'يقرأ الجوال الأرقام والحروف الإنجليزية فقط؛ اكتب الأسماء العربية يدويًا في الدفعة. راجع كل قيمة مع الإيصال.'),
      ];

  Widget _lineTile(int index, String line) {
    final used = picked.values.any((value) =>
        receiptLinePieces(line).contains(value) ||
        receiptAmount(line) == value ||
        line == value);
    return Material(
      color: used ? AppIdentity.goodTint : AppIdentity.surface,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        key: ValueKey('receipt-line-$index'),
        borderRadius: BorderRadius.circular(14),
        onTap: () => chooseFrom(line),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
              border: Border.all(
                  color: used ? AppIdentity.good : AppIdentity.lineSoft,
                  width: 1.5),
              borderRadius: BorderRadius.circular(14)),
          child: Row(children: [
            Expanded(
                child: Text(line,
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.right,
                    style: AppIdentity.number(13.5, weight: FontWeight.w500)
                        .copyWith(fontFamilyFallback: ['PlexArabic']))),
            const SizedBox(width: 10),
            AppIcon(used ? 'check' : 'plus',
                size: 18, color: used ? AppIdentity.good : AppIdentity.faint),
          ]),
        ),
      ),
    );
  }

  Widget cropPreview() => AspectRatio(
      aspectRatio: widget.image.width / widget.image.height,
      child: LayoutBuilder(builder: (context, constraints) {
        final size = constraints.biggest;
        return Directionality(
            textDirection: TextDirection.ltr,
            child: Stack(clipBehavior: Clip.none, children: [
              Positioned.fill(
                  child: Image.file(File(widget.image.previewPath),
                      key: ValueKey(rotation),
                      fit: BoxFit.fill,
                      errorBuilder: (_, __, ___) => Center(
                          child: AppIcon('img', color: AppIdentity.faint)))),
              Positioned.fill(
                  child: IgnorePointer(
                      child: CustomPaint(
                          painter: _ReceiptBoundary(widget.image.corners)))),
              for (var index = 0; index < 4; index++)
                Positioned(
                    left: widget.image.corners[index * 2] * size.width - 18,
                    top: widget.image.corners[index * 2 + 1] * size.height - 18,
                    child: GestureDetector(
                        onPanUpdate: busy
                            ? null
                            : (details) => setState(() {
                                  widget.image.corners[index * 2] =
                                      (widget.image.corners[index * 2] +
                                              details.delta.dx / size.width)
                                          .clamp(0.0, 1.0);
                                  widget.image.corners[index * 2 + 1] =
                                      (widget.image.corners[index * 2 + 1] +
                                              details.delta.dy / size.height)
                                          .clamp(0.0, 1.0);
                                }),
                        child: Semantics(
                            label: 'زاوية الإيصال ${index + 1}',
                            child: Container(
                                width: 36,
                                height: 36,
                                decoration: BoxDecoration(
                                    color: Colors.white,
                                    border: Border.all(
                                        color: AppIdentity.brand, width: 3),
                                    shape: BoxShape.circle))))),
            ]));
      }));
}

/// Pick which part of a line to use, then which field it goes to.
class _PieceSheet extends StatefulWidget {
  const _PieceSheet({required this.pieces});
  final List<String> pieces;

  @override
  State<_PieceSheet> createState() => _PieceSheetState();
}

class _PieceSheetState extends State<_PieceSheet> {
  late String piece =
      widget.pieces.length > 1 ? widget.pieces[1] : widget.pieces.first;

  @override
  Widget build(BuildContext context) => Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final option in widget.pieces)
                InkWell(
                  key: ValueKey('receipt-piece-$option'),
                  borderRadius: BorderRadius.circular(14),
                  onTap: () => setState(() => piece = option),
                  child: Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                    decoration: BoxDecoration(
                        color: option == piece
                            ? AppIdentity.goodTint
                            : AppIdentity.surface,
                        border: Border.all(
                            color: option == piece
                                ? AppIdentity.good
                                : AppIdentity.line,
                            width: 1.5),
                        borderRadius: BorderRadius.circular(14)),
                    child: Text(option,
                        textDirection: TextDirection.ltr,
                        overflow: TextOverflow.ellipsis,
                        style: AppIdentity.number(13.5, weight: FontWeight.w700)
                            .copyWith(fontFamilyFallback: ['PlexArabic'])),
                  ),
                ),
            ]),
            const SizedBox(height: 16),
            Text('ضعه في',
                style: AppIdentity.body(14, weight: FontWeight.w700)),
            const SizedBox(height: 8),
            for (final field in ReceiptField.values) ...[
              AppAction(
                  key: ValueKey('receipt-to-${field.key}'),
                  label: field.label,
                  primary: false,
                  height: 44,
                  onPressed: field == ReceiptField.amount &&
                          receiptAmount(piece) == null
                      ? null
                      : () => Navigator.pop(context, (piece, field))),
              const SizedBox(height: 8),
            ],
            AppAction(
                key: const ValueKey('receipt-copy'),
                label: 'نسخ',
                primary: false,
                height: 44,
                onPressed: () => Navigator.pop(context, (piece, null))),
          ]);
}

class _ReceiptBoundary extends CustomPainter {
  const _ReceiptBoundary(this.corners);
  final List<double> corners;
  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(corners[0] * size.width, corners[1] * size.height);
    for (var index = 1; index < 4; index++) {
      path.lineTo(corners[index * 2] * size.width,
          corners[index * 2 + 1] * size.height);
    }
    path.close();
    canvas.drawPath(
        path,
        Paint()
          ..color = AppIdentity.brand
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2);
  }

  @override
  bool shouldRepaint(_ReceiptBoundary oldDelegate) => true;
}
