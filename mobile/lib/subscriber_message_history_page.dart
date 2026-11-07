import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';

class SubscriberMessageHistoryPage extends StatefulWidget {
  const SubscriberMessageHistoryPage(
      {required this.api, required this.subscriber, super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;

  @override
  State<SubscriberMessageHistoryPage> createState() =>
      _SubscriberMessageHistoryPageState();
}

class _SubscriberMessageHistoryPageState
    extends State<SubscriberMessageHistoryPage> {
  List<Map<String, dynamic>> messages = [];
  int page = 0;
  int lastPage = 1;
  bool busy = true;
  bool retryMore = false;
  String? error;
  int version = 0;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load({bool more = false}) async {
    if (more && (busy || page >= lastPage)) return;
    retryMore = more;
    final request = ++version;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.subscriberMessageHistory(
          widget.subscriber['id'] as int,
          page: more ? page + 1 : 1);
      if (!mounted || request != version) return;
      final payload = result['messages'] as Map;
      final rows = (payload['data'] as List)
          .map((row) => Map<String, dynamic>.from(row as Map))
          .toList();
      setState(() {
        messages = more ? [...messages, ...rows] : rows;
        page = payload['current_page'] as int;
        lastPage = payload['last_page'] as int;
      });
    } on ApiException catch (exception) {
      if (!mounted || request != version) return;
      setState(() {
        error = exception.statusCode == 403
            ? 'ليس لديك صلاحية عرض الرسائل. تواصل مع الإدارة.'
            : exception.message;
        if ([401, 403, 404].contains(exception.statusCode)) messages = [];
      });
    } finally {
      if (mounted && request == version) setState(() => busy = false);
    }
  }

  Widget messageCard(Map<String, dynamic> message) {
    final status = switch (message['status']) {
      'sent' => 'مرسلة',
      'failed' => 'فشل الإرسال',
      _ => 'بانتظار الإرسال'
    };
    final kind = switch (message['kind']) {
      'weekly_reading' => 'قراءة الأسبوع',
      'balance_reminder' => 'تذكير بالرصيد',
      'disconnection_warning' => 'تنبيه بالفصل',
      'custom' => 'رسالة مخصصة',
      _ => 'غير محدد'
    };
    final creator = message['createdBy'] ?? 'غير محدد';
    final date =
        DateTime.tryParse('${message['sentAt'] ?? message['createdAt']}')
            ?.toLocal();
    final stamp = date == null
        ? ''
        : '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year} · ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';
    return Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: AppRows(children: [
          Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(spacing: 10, runSpacing: 6, children: [
                      Text(kind,
                          style: AppIdentity.body(14, weight: FontWeight.w700)),
                      Text(status,
                          style: AppIdentity.body(13,
                              color: message['status'] == 'failed'
                                  ? AppIdentity.bad
                                  : message['status'] == 'sent'
                                      ? AppIdentity.good
                                      : AppIdentity.muted)),
                      Text(
                          message['channel'] == 'whatsapp' ? 'WhatsApp' : 'SMS',
                          style: AppIdentity.body(12, color: AppIdentity.faint))
                    ]),
                    const SizedBox(height: 12),
                    SelectableText('${message['body'] ?? ''}',
                        style: AppIdentity.body(15)),
                    const SizedBox(height: 12),
                    Text('المستلم: ${message['phone'] ?? 'غير محدد'}',
                        style: AppIdentity.body(12, color: AppIdentity.muted)),
                    Text('سجّلها: $creator',
                        style: AppIdentity.body(12, color: AppIdentity.muted)),
                    if (message['sentBy'] != null)
                      Text('أرسلها: ${message['sentBy']}',
                          style:
                              AppIdentity.body(12, color: AppIdentity.muted)),
                    if (stamp.isNotEmpty)
                      Text(stamp,
                          textDirection: TextDirection.ltr,
                          style:
                              AppIdentity.number(12, color: AppIdentity.faint)),
                    if (message['error'] != null)
                      Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: AppNotice('${message['error']}', error: true)),
                  ]))
        ]));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
          body: SafeArea(
              child: Column(children: [
        AppHeader(
            title: 'سجل الرسائل',
            subtitle: '${widget.subscriber['full_name']}',
            onBack: () => Navigator.pop(context)),
        Expanded(
            child: RefreshIndicator(
                color: AppIdentity.brand,
                onRefresh: load,
                child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                    children: [
                      if (busy)
                        LinearProgressIndicator(color: AppIdentity.brand),
                      if (error != null) ...[
                        AppNotice(error!, error: true),
                        TextButton(
                            onPressed:
                                busy ? null : () => load(more: retryMore),
                            child: const Text('إعادة المحاولة'))
                      ],
                      if (!busy && error == null && messages.isEmpty)
                        const AppNotice('لا توجد رسائل سابقة لهذا المشترك'),
                      ...messages.map(messageCard),
                      if (page < lastPage && error == null)
                        TextButton(
                            onPressed: busy ? null : () => load(more: true),
                            child: const Text('عرض رسائل أقدم')),
                    ]))),
      ])));
}
