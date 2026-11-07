import 'package:flutter/material.dart';
import 'app_identity.dart';

String bankReceivedDateValue(DateTime date) =>
    '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

class BankReceivedDateField extends StatelessWidget {
  const BankReceivedDateField(
      {required this.value,
      required this.onChanged,
      this.enabled = true,
      super.key});
  final DateTime? value;
  final ValueChanged<DateTime?> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const AppLabel('تاريخ وصول الحوالة إلى البنك', hint: 'اختياري'),
          AppAction(
            key: const ValueKey('bank-received-date'),
            label: value == null
                ? 'اختيار تاريخ الوصول'
                : bankReceivedDateValue(value!).split('-').reversed.join('/'),
            primary: false,
            icon: 'calendar',
            onPressed: !enabled
                ? null
                : () async {
                    final today = DateUtils.dateOnly(DateTime.now());
                    final date = await showDatePicker(
                      context: context,
                      initialDate: value ?? today,
                      firstDate: DateTime(1900),
                      lastDate: today,
                      helpText: 'تاريخ وصول الحوالة إلى البنك',
                      confirmText: 'اختيار',
                      cancelText: 'إلغاء',
                    );
                    if (date != null) onChanged(date);
                  },
          ),
          if (value != null)
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(
                  onPressed: enabled ? () => onChanged(null) : null,
                  child: const Text('مسح تاريخ الوصول')),
            ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
                'اتركه فارغًا إن لم تتأكد منه. تُسجّل الحركة بتاريخ تسجيلها في النظام.',
                style: AppIdentity.body(12.5, color: AppIdentity.muted)),
          ),
        ],
      );
}
