/// Search that forgives how an Arabic name is spelled, as the server's
/// search does: أ إ آ ٱ match ا, ى matches ي and ة matches ه, short vowels
/// and the tatweel are ignored, Arabic-Indic digits match Western ones, and
/// each word of the search may be found anywhere, in any order.
library;

const _letters = {
  'أ': 'ا',
  'إ': 'ا',
  'آ': 'ا',
  'ٱ': 'ا',
  'ى': 'ي',
  'ة': 'ه',
};

final _marks = RegExp('[ً-ٰٟـ]');
final _spaces = RegExp(r'\s+');

/// The text as it is compared: one spelling, no vowel marks, Western
/// digits, lower case.
String normalizeSearch(String text) {
  final buffer = StringBuffer();
  for (final rune in text.replaceAll(_marks, '').runes) {
    final character = String.fromCharCode(rune);
    if (rune >= 0x0660 && rune <= 0x0669) {
      buffer.write(rune - 0x0660);
    } else if (rune >= 0x06F0 && rune <= 0x06F9) {
      buffer.write(rune - 0x06F0);
    } else {
      buffer.write(_letters[character] ?? character);
    }
  }
  return buffer.toString().replaceAll(_spaces, ' ').trim().toLowerCase();
}

/// Whether every word of `query` is found in at least one of `values`.
bool matchesSearch(String query, Iterable<Object?> values) {
  final terms = normalizeSearch(query).split(' ').where((t) => t.isNotEmpty);
  if (terms.isEmpty) return true;
  final haystack = [
    for (final value in values)
      if (value != null) normalizeSearch('$value'),
  ];
  return terms.every((term) => haystack.any((value) => value.contains(term)));
}
