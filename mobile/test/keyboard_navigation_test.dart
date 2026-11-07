import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/app_identity.dart';

void main() {
  testWidgets('header back hides the keyboard before leaving a resized screen',
      (tester) async {
    await tester.pumpWidget(MaterialApp(home: Builder(builder: (context) {
      return Scaffold(
          body: TextButton(
              onPressed: () =>
                  Navigator.of(context).push(MaterialPageRoute<void>(
                      builder: (_) => Scaffold(
                              body: Column(children: [
                            Builder(builder: (context) {
                              return AppHeader(
                                  title: 'Search',
                                  onBack: () => Navigator.pop(context));
                            }),
                            const TextField(),
                          ])))),
              child: const Text('Open search')));
    })));
    await tester.tap(find.text('Open search'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Subscriber search');
    tester.view.viewInsets = const FakeViewPadding(bottom: 250);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();

    expect(tester.testTextInput.isVisible, isFalse);
    expect(find.text('Search'), findsOneWidget);
    expect(find.text('Subscriber search'), findsOneWidget);

    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    expect(find.text('Open search'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
  });
}
