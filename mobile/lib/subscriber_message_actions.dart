import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'subscriber_message_page.dart';
import 'subscriber_message_history_page.dart';

class SubscriberMessageActions extends StatelessWidget {
  const SubscriberMessageActions(
      {required this.api,
      required this.subscriber,
      required this.messaging,
      super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;
  final Map messaging;

  @override
  Widget build(BuildContext context) {
    final canSend = messaging['can_send'] == true;
    final canView = messaging['can_view_history'] == true;
    final hasPhone = '${subscriber['phone'] ?? ''}'.trim().isNotEmpty;
    final available = messaging['sms_available'] == true;
    final sendEnabled = canSend && hasPhone && available;
    Widget action(
            {required String key,
            required String title,
            required String icon,
            required bool enabled,
            required String note,
            required Widget page}) =>
        Expanded(
            child: InkWell(
          key: ValueKey(key),
          child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      AppIconTile(
                          icon,
                          enabled ? AppIdentity.info : AppIdentity.faint,
                          enabled ? AppIdentity.infoTint : AppIdentity.sunken,
                          size: 36,
                          iconSize: 18),
                      const Spacer(),
                      AppIcon(enabled ? 'chev' : 'lock',
                          size: 16, color: AppIdentity.faint)
                    ]),
                    const SizedBox(height: 10),
                    Text(title,
                        style: AppIdentity.body(14, weight: FontWeight.w700)),
                    const SizedBox(height: 5),
                    Text(note,
                        style: AppIdentity.body(12, color: AppIdentity.muted)),
                  ])),
          onTap: enabled
              ? () => Navigator.push(
                  context, MaterialPageRoute<void>(builder: (_) => page))
              : null,
        ));
    return AppRows(
        key: const ValueKey('subscriber-message-actions'),
        children: [
          IntrinsicHeight(
              child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                action(
                    key: 'subscriber-messaging',
                    title: 'إرسال رسالة',
                    icon: 'message',
                    enabled: sendEnabled,
                    note: !canSend
                        ? 'تحتاج صلاحية إرسال الرسائل من الإدارة'
                        : !hasPhone
                            ? 'لا يوجد رقم هاتف لهذا المشترك'
                            : !available
                                ? 'خدمة SMS غير مفعّلة؛ تواصل مع الإدارة'
                                : 'رسالة نصية إلى المشترك عبر SMS',
                    page: SubscriberMessagePage(
                        api: api, subscriber: subscriber)),
                VerticalDivider(
                    width: 1, thickness: 1, color: AppIdentity.lineSoft),
                action(
                    key: 'subscriber-message-history',
                    title: 'سجل الرسائل',
                    icon: 'hist',
                    enabled: canView,
                    note: canView
                        ? 'الرسائل السابقة وأنواعها وحالة الإرسال'
                        : 'تحتاج صلاحية عرض الرسائل من الإدارة',
                    page: SubscriberMessageHistoryPage(
                        api: api, subscriber: subscriber)),
              ]))
        ]);
  }
}

class SubscriberMessagingDetailsPage extends StatelessWidget {
  const SubscriberMessagingDetailsPage(
      {required this.api,
      required this.subscriber,
      required this.messaging,
      super.key});
  final ApiClient api;
  final Map<String, dynamic> subscriber;
  final Map messaging;

  @override
  Widget build(BuildContext context) => Scaffold(
          body: SafeArea(
              child: Column(children: [
        AppHeader(
            title: 'تفاصيل المشترك',
            subtitle: '${subscriber['full_name']}',
            onBack: () => Navigator.pop(context)),
        Expanded(
            child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                children: [
              AppRows(children: [
                AppRow(
                    title: AppRowTitle('${subscriber['full_name']}'),
                    subtitle: AppRowNote(
                        'حساب ${subscriber['account_number']} · ${subscriber['phone'] ?? 'لا يوجد رقم هاتف'}'),
                    leading: AppAvatar('${subscriber['full_name']}'))
              ]),
              const AppSection('رسائل المشترك'),
              SubscriberMessageActions(
                  api: api, subscriber: subscriber, messaging: messaging),
            ])),
      ])));
}
