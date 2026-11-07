import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'field_store.dart' show newOperationId;

class SubscriberMessagePage extends StatefulWidget {
  const SubscriberMessagePage(
      {required this.api, required this.subscriber, super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;

  @override
  State<SubscriberMessagePage> createState() => _SubscriberMessagePageState();
}

class _SubscriberMessagePageState extends State<SubscriberMessagePage> {
  final body = TextEditingController();
  String operationId = newOperationId();
  String? pendingBody;
  String? error;
  String? status;
  bool busy = false;

  @override
  void dispose() {
    body.dispose();
    super.dispose();
  }

  Future<void> send() async {
    if (busy || status != null || body.text.trim().isEmpty) return;
    FocusScope.of(context).unfocus();
    setState(() {
      busy = true;
      error = null;
      pendingBody ??= body.text.trim();
    });
    try {
      final result = await widget.api.sendSubscriberMessage(
          widget.subscriber['id'] as int,
          {'mobile_operation_id': operationId, 'body': pendingBody});
      if (!mounted) return;
      setState(() {
        status = '${result['status']}';
        if (status == 'failed')
          error =
              '${result['error'] ?? 'تعذّر إرسال الرسالة. تواصل مع الإدارة.'}';
      });
    } on ApiException catch (exception) {
      if (!mounted) return;
      setState(() {
        error = exception.message;
        // A missing response may follow a successful send. Retry the exact operation.
        if (!exception.isNetwork && exception.statusCode < 500) {
          pendingBody = null;
          operationId = newOperationId();
        }
      });
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  void close() {
    if (dismissAppKeyboard(context)) return;
    if (!busy) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) => AppKeyboardBackScope(
      canPop: !busy,
      child: Scaffold(
          body: SafeArea(
              child: Column(children: [
        AppHeader(
            title: 'إرسال رسالة',
            subtitle: '${widget.subscriber['full_name']}',
            onBack: close),
        Expanded(
            child: ListView(
                keyboardDismissBehavior:
                    ScrollViewKeyboardDismissBehavior.onDrag,
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                children: [
              AppRows(children: [
                AppRow(
                    leading: AppIconTile(
                        'message', AppIdentity.info, AppIdentity.infoTint,
                        size: 38, iconSize: 18),
                    title: const AppRowTitle('رسالة نصية SMS'),
                    subtitle: AppRowNote('إلى ${widget.subscriber['phone']}'))
              ]),
              const AppLabel('نص الرسالة'),
              TextField(
                  key: const ValueKey('subscriber-message-body'),
                  controller: body,
                  readOnly: busy || pendingBody != null || status != null,
                  minLines: 5,
                  maxLines: 10,
                  maxLength: 1000,
                  keyboardType: TextInputType.multiline,
                  textInputAction: TextInputAction.newline,
                  style: AppIdentity.body(15),
                  onTapOutside: (_) => FocusScope.of(context).unfocus(),
                  onChanged: (_) => setState(() {}),
                  decoration:
                      const InputDecoration(hintText: 'اكتب رسالتك للمشترك…')),
              if (status == 'pending')
                const AppNotice(
                    'حُفظت الرسالة وهي بانتظار الإرسال عبر خدمة SMS.')
              else if (status == 'sent')
                const AppNotice('أُرسلت الرسالة عبر خدمة SMS.'),
              if (error != null) ...[
                const SizedBox(height: 12),
                AppNotice(error!, error: true),
                if (pendingBody != null && status == null)
                  const Padding(
                      padding: EdgeInsets.only(top: 8),
                      child: AppRowNote(
                          'لم يصل تأكيد الإرسال. أعد المحاولة للتحقق من الرسالة نفسها دون تكرارها.')),
              ],
              const SizedBox(height: 16),
              if (status == null)
                AppAction(
                    key: const ValueKey('subscriber-send-message'),
                    label: pendingBody != null && !busy
                        ? 'التحقق وإعادة المحاولة'
                        : 'إرسال الرسالة',
                    icon: 'message',
                    busy: busy,
                    onPressed: busy || body.text.trim().isEmpty ? null : send)
              else
                AppAction(label: 'العودة إلى المشترك', onPressed: close),
            ])),
      ]))));
}
