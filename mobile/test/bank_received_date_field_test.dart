import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/bank_received_date_field.dart';

void main() {
  testWidgets(
      'bank arrival starts blank, requires a selection, and can be cleared',
      (tester) async {
    DateTime? selected;
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        home: Scaffold(
            body: StatefulBuilder(
                builder: (context, setState) => Padding(
                    padding: const EdgeInsets.all(16),
                    child: BankReceivedDateField(
                        value: selected,
                        onChanged: (date) =>
                            setState(() => selected = date)))))));
    expect(selected, isNull);
    expect(find.text('اختيار تاريخ الوصول'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('bank-received-date')));
    await tester.pumpAndSettle();
    expect(selected, isNull);
    final picker =
        tester.widget<CalendarDatePicker>(find.byType(CalendarDatePicker));
    expect(picker.lastDate, DateUtils.dateOnly(DateTime.now()));
    final previousDay =
        DateUtils.dateOnly(DateTime.now()).subtract(const Duration(days: 1));
    picker.onDateChanged(previousDay);
    await tester.pumpAndSettle();
    await tester.tap(find.text('اختيار').last);
    await tester.pumpAndSettle();
    expect(selected, previousDay);
    expect(
        find.text(
            bankReceivedDateValue(previousDay).split('-').reversed.join('/')),
        findsOneWidget);
    await tester.tap(find.text('مسح تاريخ الوصول'));
    await tester.pumpAndSettle();
    expect(selected, isNull);
    expect(find.text('اختيار تاريخ الوصول'), findsOneWidget);
  });

  testWidgets(
      'bank arrival fits a small phone with large text and respects a frozen submission',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(320, 640));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        home: MediaQuery(
            data: const MediaQueryData(
                size: Size(320, 640), textScaler: TextScaler.linear(1.8)),
            child: Scaffold(
                body: SingleChildScrollView(
                    child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: BankReceivedDateField(
                            value: DateTime(2026, 10, 23),
                            enabled: false,
                            onChanged: (_) => fail(
                                'a submitted payment cannot change date'))))))));
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const ValueKey('bank-received-date')));
    await tester.pumpAndSettle();
    expect(find.byType(DatePickerDialog), findsNothing);
  });
}
