import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/search_text.dart';

void main() {
  test('Arabic spellings, vowel marks and digits compare as one', () {
    expect(normalizeSearch('  أَحْمَد   إسراء آمنة ٱلله  '),
        'احمد اسراء امنه الله');
    expect(normalizeSearch('مصطفى'), normalizeSearch('مصطفي'));
    expect(normalizeSearch('عـــلي'), 'علي');
    expect(normalizeSearch('١٠٤٢ ۱۲'), '1042 12');
    expect(normalizeSearch('ABC'), 'abc');
  });

  test('every word must be found, in any order and any field', () {
    expect(matchesSearch('مصطفي احمد', ['أحمد مصطفى']), isTrue);
    expect(matchesSearch('احمد A42', ['أحمد', 'A42']), isTrue);
    expect(matchesSearch('احمد خالد', ['أحمد مصطفى']), isFalse);
    expect(matchesSearch('  ', ['anything']), isTrue);
    expect(matchesSearch('x', [null]), isFalse);
  });
}
