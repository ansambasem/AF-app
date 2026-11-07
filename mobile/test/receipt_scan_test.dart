import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';
import 'package:power_collect/app_identity.dart';
import 'package:power_collect/payment_page.dart';
import 'package:power_collect/receipt_scan.dart';

class TestReceiptScanner extends ReceiptScanner {
  TestReceiptScanner(this.path);
  final String path;
  bool cancelled = false;
  int discarded = 0;
  @override
  Future<ReceiptImage?> pick(ReceiptImageSource source) async => cancelled
      ? null
      : ReceiptImage(
          originalPath: path,
          previewPath: path,
          width: 600,
          height: 900,
          corners: [0, 0, 1, 0, 1, 1, 0, 1]);
  @override
  Future<String> prepare(ReceiptImage image, {required bool contrast}) async =>
      path;

  @override
  Future<void> discard(ReceiptImage image) async => discarded++;
}

class TestReceiptReader extends ReceiptTextReader {
  TestReceiptReader(this.lines);
  final List<String> lines;
  final readPaths = <String>[];
  @override
  Future<List<String>> read(String imagePath) async {
    readPaths.add(imagePath);
    return lines;
  }
}

class OfflineApi extends ApiClient {
  int requests = 0;
  @override
  Future<Map<String, dynamic>> sendCollection(
      Map<String, dynamic> collection) async {
    requests++;
    throw const ApiException('offline', 0);
  }
}

const subscriber = {
  'id': 42,
  'full_name': 'Test Subscriber',
  'account_number': 'A42',
  'balance': '100.00'
};

void main() {
  String imageFile() {
    final directory = Directory.systemTemp.createTempSync('receipt-test-');
    addTearDown(() => directory.deleteSync(recursive: true));
    final file = File('${directory.path}/receipt.png');
    file.writeAsBytesSync(base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII='));
    return file.path;
  }

  Future<void> openReceipt(WidgetTester tester, OfflineApi api,
      TestReceiptScanner scanner, TestReceiptReader reader) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        theme: AppIdentity.theme,
        home: PaymentPage(
            api: api,
            subscriber: subscriber,
            receiptScanner: scanner,
            receiptReader: reader)));
    await tester.pumpAndSettle();
    final scrollable = find.byType(Scrollable).first;
    final transfer = find.byKey(const ValueKey('payment-method-bank_transfer'));
    await tester.scrollUntilVisible(transfer, 100, scrollable: scrollable);
    await tester.ensureVisible(transfer);
    await tester.pumpAndSettle();
    await tester.tap(transfer);
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
        find.byKey(const ValueKey('receipt-gallery')), 100,
        scrollable: scrollable);
    await tester.ensureVisible(find.byKey(const ValueKey('receipt-gallery')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('receipt-gallery')));
    await tester.pumpAndSettle();
  }

  Future<void> sendLine(
      WidgetTester tester, int line, String piece, String field) async {
    await tester.ensureVisible(find.byKey(ValueKey('receipt-line-$line')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(ValueKey('receipt-line-$line')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(ValueKey('receipt-piece-$piece')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(ValueKey('receipt-to-$field')));
    await tester.pumpAndSettle();
  }

  test('a receipt line offers the whole line and each number in it', () {
    expect(receiptLinePieces('Amount: 1,250.00 ILS'),
        ['Amount: 1,250.00 ILS', '1,250.00']);
    expect(receiptLinePieces('Ref No. TRX-48213 / 2026'),
        ['Ref No. TRX-48213 / 2026', 'TRX-48213', '2026']);
    expect(receiptLinePieces('المبلغ ١٥٠'), ['المبلغ 150', '150']);
    expect(receiptLinePieces('Bank of Palestine'), ['Bank of Palestine']);
  });

  testWidgets(
      'text read on the phone fills the chosen payment fields without uploading',
      (tester) async {
    final api = OfflineApi();
    final scanner = TestReceiptScanner(imageFile());
    final reader = TestReceiptReader([
      'Bank of Palestine',
      'Amount: 1,250.00 ILS',
      'Reference TRX-48213',
      'From AHMAD SALEM',
    ]);
    await openReceipt(tester, api, scanner, reader);

    expect(find.text('قراءة الإيصال'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('receipt-read')));
    await tester.pumpAndSettle();
    expect(reader.readPaths, [scanner.path]);
    expect(find.text('Reference TRX-48213'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('receipt-line-0')));
    await tester.pumpAndSettle();
    final amountButton = tester
        .widget<AppAction>(find.byKey(const ValueKey('receipt-to-amount')));
    expect(amountButton.onPressed, isNull,
        reason: 'a line with no amount cannot fill the amount');
    await tester.tapAt(const Offset(195, 60));
    await tester.pumpAndSettle();

    await sendLine(tester, 1, '1,250.00', 'amount');
    await sendLine(tester, 2, 'TRX-48213', 'reference_number');
    await sendLine(tester, 3, 'From AHMAD SALEM', 'sender_name');
    expect(find.byKey(const ValueKey('receipt-picked-amount')), findsOneWidget);
    expect(find.text('1250.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('receipt-use')));
    await tester.pumpAndSettle();

    expect(find.text('تسجيل 1250.00 شيكل'), findsOneWidget);
    expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('payment-reference')))
            .controller!
            .text,
        'TRX-48213');
    expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('payment-sender')))
            .controller!
            .text,
        'From AHMAD SALEM');
    expect(
        tester
            .widget<AppToggle>(
                find.byKey(const ValueKey('payment-sender-is-subscriber')))
            .value,
        isFalse);
    expect(api.requests, 0);
    expect(scanner.discarded, 1);
  });

  testWidgets('a photo with no readable text says so and fills nothing',
      (tester) async {
    final api = OfflineApi();
    final scanner = TestReceiptScanner(imageFile());
    await openReceipt(tester, api, scanner, TestReceiptReader([]));
    await tester.tap(find.byKey(const ValueKey('receipt-read')));
    await tester.pumpAndSettle();

    expect(find.textContaining('لم يُعثر على نص'), findsOneWidget);
    final use =
        tester.widget<AppAction>(find.byKey(const ValueKey('receipt-use')));
    expect(use.onPressed, isNull);
  });

  testWidgets('cancelling image selection keeps the existing payment form',
      (tester) async {
    final api = OfflineApi();
    final scanner = TestReceiptScanner(imageFile())..cancelled = true;
    await openReceipt(tester, api, scanner, TestReceiptReader(['unused']));

    expect(find.text('قراءة الإيصال'), findsNothing);
    expect(find.byKey(const ValueKey('payment-submit')), findsOneWidget);
    expect(api.requests, 0);
  });
}
