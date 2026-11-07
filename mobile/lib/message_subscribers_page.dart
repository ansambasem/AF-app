import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_identity.dart';
import 'subscriber_message_actions.dart';

class MessageSubscribersPage extends StatefulWidget {
  const MessageSubscribersPage({required this.api, super.key});
  final ApiClient api;

  @override
  State<MessageSubscribersPage> createState() => _MessageSubscribersPageState();
}

class _MessageSubscribersPageState extends State<MessageSubscribersPage> {
  final search = TextEditingController();
  Timer? debounce;
  int version = 0;
  int page = 0;
  int lastPage = 1;
  int total = 0;
  bool busy = true;
  bool available = false;
  bool canSend = false;
  bool canViewHistory = false;
  String? error;
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

  void changed(String _) {
    debounce?.cancel();
    version++;
    setState(() {
      subscribers = [];
      busy = true;
      error = null;
      page = 0;
    });
    debounce =
        Timer(const Duration(milliseconds: 300), () => unawaited(load()));
  }

  Future<void> load({bool more = false}) async {
    if (more && (busy || page >= lastPage)) return;
    debounce?.cancel();
    final request = ++version;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api
          .messageSubscribers(search.text.trim(), page: more ? page + 1 : 1);
      if (!mounted || request != version) return;
      final rows = (result['data'] as List)
          .map((row) => Map<String, dynamic>.from(row as Map))
          .toList();
      setState(() {
        subscribers = more ? [...subscribers, ...rows] : rows;
        page = result['current_page'] as int;
        lastPage = result['last_page'] as int;
        total = result['total'] as int;
        available = result['sms_available'] == true;
        canSend = result['can_send'] == true;
        canViewHistory = result['can_view_history'] == true;
      });
    } on ApiException catch (exception) {
      if (!mounted || request != version) return;
      setState(() {
        error = exception.statusCode == 403
            ? 'ليس لديك صلاحية إرسال الرسائل. تواصل مع الإدارة.'
            : exception.message;
        if (exception.statusCode == 401 || exception.statusCode == 403) {
          subscribers = [];
          available = false;
          total = 0;
        }
      });
    } finally {
      if (mounted && request == version) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AppKeyboardBackScope(
          child: Scaffold(
              body: SafeArea(
                  child: Column(children: [
        AppHeader(
            title: 'إرسال الرسائل',
            subtitle: 'اختر المشترك لكتابة رسالة نصية',
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
                    AppSearchField(
                        controller: search,
                        hint: 'اسم المشترك أو رقم الحساب أو الطبلون',
                        maxLength: 100,
                        onChanged: changed,
                        onSubmitted: (_) => unawaited(load()),
                        onClear: () {
                          search.clear();
                          changed('');
                        }),
                    AppSection('اختر المستلم · $total مشترك'),
                    if (busy) LinearProgressIndicator(color: AppIdentity.brand),
                    if (error != null) ...[
                      AppNotice(error!, error: true),
                      TextButton(
                          onPressed: busy ? null : () => unawaited(load()),
                          child: const Text('إعادة المحاولة')),
                    ] else if (!busy && !available)
                      const AppNotice('خدمة SMS غير مفعّلة؛ تواصل مع الإدارة',
                          warning: true),
                    if (!busy && error == null && subscribers.isEmpty)
                      const AppNotice('لا يوجد مشتركون مطابقون للبحث'),
                    if (subscribers.isNotEmpty)
                      AppRows(
                          children: subscribers.map((subscriber) {
                        final phone = '${subscriber['phone'] ?? ''}'.trim();
                        final enabled = !busy &&
                            (canViewHistory ||
                                (canSend && available && phone.isNotEmpty));
                        return AppRow(
                          leading: AppIconTile(
                              'message', AppIdentity.info, AppIdentity.infoTint,
                              size: 38, iconSize: 18),
                          title: AppRowTitle('${subscriber['full_name']}'),
                          subtitle: AppRowNote(
                              'حساب ${subscriber['account_number']} · ${phone.isEmpty ? 'لا يوجد رقم هاتف لهذا المشترك' : phone}'),
                          trailing: AppIcon(enabled ? 'chev' : 'lock',
                              size: 18, color: AppIdentity.faint),
                          onTap: enabled
                              ? () => Navigator.push(
                                  context,
                                  MaterialPageRoute<void>(
                                      builder: (_) =>
                                          SubscriberMessagingDetailsPage(
                                              api: widget.api,
                                              messaging: {
                                                'can_send': canSend,
                                                'can_view_history':
                                                    canViewHistory,
                                                'sms_available': available
                                              },
                                              subscriber: subscriber)))
                              : null,
                        );
                      }).toList()),
                    if (page < lastPage && error == null)
                      TextButton(
                          onPressed:
                              busy ? null : () => unawaited(load(more: true)),
                          child: const Text('عرض مشتركين آخرين')),
                  ],
                ))),
      ]))));
}
