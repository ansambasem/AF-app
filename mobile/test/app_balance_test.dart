import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/app_balance.dart';
import 'package:power_collect/app_identity.dart';

void main() {
  test('balances use the website sign, wording and Arabic text isolation', () {
    expect(balanceText(120), '\u2066120 ₪\u2069 عليه');
    expect(balanceText(-30.5), '\u2066-30.50 ₪\u2069 له');
    expect(balanceText(-1234), '\u2066-1,234 ₪\u2069 له');
    expect(balanceText(-.004), '\u20660 ₪\u2069 مسدّد');
    expect(balanceText(.001), '\u20660 ₪\u2069 مسدّد');
    expect(balanceText(-.005), '\u20660 ₪\u2069 مسدّد');
  });

  test('owed is red, credit green and settled grey on both surface types', () {
    expect(balanceColor(20), AppIdentity.bad);
    expect(balanceColor(-20), AppIdentity.good);
    expect(balanceColor(0), AppIdentity.faint);
    expect(balanceColor(-.004), AppIdentity.faint);
    expect(balanceColor(20, onHero: true), AppIdentity.heroBad);
    expect(balanceColor(-20, onHero: true), AppIdentity.heroGood);
    expect(balanceColor(0, onHero: true), AppIdentity.heroFaint);
  });
}
