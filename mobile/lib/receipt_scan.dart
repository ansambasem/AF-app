import 'package:flutter/services.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

enum ReceiptImageSource { gallery, camera }

class ReceiptImage {
  ReceiptImage(
      {required this.originalPath,
      required this.previewPath,
      required this.width,
      required this.height,
      required this.corners});
  final String originalPath;
  String previewPath;
  int width;
  int height;
  List<double> corners;
  final processedPaths = <String>[];

  factory ReceiptImage.fromMap(Map<String, dynamic> map) => ReceiptImage(
      originalPath: map['originalPath'] as String,
      previewPath: map['previewPath'] as String,
      width: (map['width'] as num).toInt(),
      height: (map['height'] as num).toInt(),
      corners: (map['corners'] as List)
          .map((value) => (value as num).toDouble())
          .toList());
}

class ReceiptScanner {
  const ReceiptScanner();
  static const _channel = MethodChannel('powercollect.receipts');

  Future<ReceiptImage?> pick(ReceiptImageSource source) async {
    final result = await _channel
        .invokeMapMethod<String, dynamic>('pick', {'source': source.name});
    return result == null ? null : ReceiptImage.fromMap(result);
  }

  Future<void> rotate(ReceiptImage image) async {
    final result = await _channel.invokeMapMethod<String, dynamic>(
        'rotate', {'previewPath': image.previewPath});
    if (result == null) throw PlatformException(code: 'image_error');
    image.width = (result['width'] as num).toInt();
    image.height = (result['height'] as num).toInt();
    image.corners = (result['corners'] as List)
        .map((value) => (value as num).toDouble())
        .toList();
  }

  Future<String> prepare(ReceiptImage image, {required bool contrast}) async {
    final result = await _channel.invokeMapMethod<String, dynamic>('prepare', {
      'previewPath': image.previewPath,
      'corners': image.corners,
      'contrast': contrast,
    });
    final path = result?['processedPath'] as String?;
    if (path == null) throw PlatformException(code: 'image_error');
    image.processedPaths.add(path);
    return path;
  }

  Future<void> discard(ReceiptImage image) =>
      _channel.invokeMethod<void>('discard', {
        'paths': [
          image.originalPath,
          image.previewPath,
          ...image.processedPaths
        ]
      });
}

String normalizeReceiptDigits(String text) {
  const arabic = '٠١٢٣٤٥٦٧٨٩';
  const persian = '۰۱۲۳۴۵۶۷۸۹';
  return text
      .split('')
      .map((character) {
        final arabicIndex = arabic.indexOf(character);
        if (arabicIndex >= 0) return '$arabicIndex';
        final persianIndex = persian.indexOf(character);
        return persianIndex >= 0 ? '$persianIndex' : character;
      })
      .join()
      .replaceAll(RegExp(r'[\u200e\u200f\u202a-\u202e\u2066-\u2069]'), '');
}

String? receiptAmount(String input) {
  var text = normalizeReceiptDigits(input)
      .trim()
      .replaceAll('٬', ',')
      .replaceAll('٫', '.');
  if (RegExp(r'^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$').hasMatch(text)) {
    text = text.replaceAll(',', '');
  } else if (RegExp(r'^\d+,\d{1,2}$').hasMatch(text)) {
    text = text.replaceAll(',', '.');
  }
  if (!RegExp(r'^\d+(?:\.\d{1,2})?$').hasMatch(text)) return null;
  final value = double.tryParse(text);
  if (value == null || !value.isFinite || value <= 0 || value > 1000000)
    return null;
  return value.toStringAsFixed(2);
}

/// Reads the text on a receipt photo on the phone itself; nothing is uploaded.
abstract class ReceiptTextReader {
  const ReceiptTextReader();

  /// The receipt's lines of text, top to bottom.
  Future<List<String>> read(String imagePath);
}

/// Google ML Kit's on-device reader. It reads digits and Latin letters
/// (amounts, transfer numbers, dates, IBANs) but not Arabic script.
class DeviceReceiptTextReader extends ReceiptTextReader {
  const DeviceReceiptTextReader();

  @override
  Future<List<String>> read(String imagePath) async {
    final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
    try {
      final recognized =
          await recognizer.processImage(InputImage.fromFilePath(imagePath));
      final lines = [
        for (final block in recognized.blocks)
          for (final line in block.lines) line,
      ]..sort((a, b) => a.boundingBox.top == b.boundingBox.top
          ? a.boundingBox.left.compareTo(b.boundingBox.left)
          : a.boundingBox.top.compareTo(b.boundingBox.top));
      return [
        for (final line in lines)
          if (line.text.trim().isNotEmpty) line.text.trim(),
      ];
    } finally {
      await recognizer.close();
    }
  }
}

/// What a collector can take from one line of a receipt: the whole line,
/// then each number or code in it (an amount, a transfer or account number).
List<String> receiptLinePieces(String line) {
  final text = normalizeReceiptDigits(line).trim();
  final pieces = <String>[text];
  for (final match in RegExp(r'[A-Za-z0-9][A-Za-z0-9.,/\-]*[A-Za-z0-9]|\d')
      .allMatches(text)) {
    final piece = match.group(0)!;
    if (RegExp(r'\d').hasMatch(piece) && !pieces.contains(piece)) {
      pieces.add(piece);
    }
  }
  return pieces;
}
