import 'package:flutter/material.dart';

import 'app_identity.dart';

/// The website's balance rule, rounded to cents before choosing a sign or tone.
int balanceCents(double balance) =>
    balance.isFinite ? (balance * 100 + .5).floor() : 0;

String balanceText(double balance) {
  final cents = balanceCents(balance);
  final grouped = AppIdentity.grouped(cents.abs() / 100);
  final amount = cents % 100 == 0 ? grouped.split('.').first : grouped;
  final word = cents > 0
      ? 'عليه'
      : cents < 0
          ? 'له'
          : 'مسدّد';
  return '\u2066${cents < 0 ? '-' : ''}$amount ₪\u2069 $word';
}

Color balanceColor(double balance, {bool onHero = false}) {
  final cents = balanceCents(balance);
  if (cents > 0) return onHero ? AppIdentity.heroBad : AppIdentity.bad;
  if (cents < 0) return onHero ? AppIdentity.heroGood : AppIdentity.good;
  return onHero ? AppIdentity.heroFaint : AppIdentity.faint;
}
