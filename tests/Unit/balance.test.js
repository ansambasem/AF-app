import { test } from 'node:test';
import assert from 'node:assert/strict';
import { balanceInText, balanceOnPaper, balanceTone, balanceWord, signedBalance, isFarBehind } from '../../resources/js/lib/balance.js';

test('a positive balance is owed, a negative one is credit, zero is settled', () => {
    assert.equal(balanceTone(120), 'owes');
    assert.equal(balanceTone('-30.5'), 'credit');
    assert.equal(balanceTone('0.001'), 'settled');
    assert.equal(balanceTone(null), 'settled');
});

test('only a negative balance carries a sign', () => {
    assert.equal(signedBalance('120.00'), '120');
    assert.equal(signedBalance(-30.5), '-30.50');
    assert.equal(signedBalance(-1234), '-1,234');
    assert.equal(signedBalance('-0.004'), '0');
});

test('in running text the signed balance is isolated left to right', () => {
    assert.equal(balanceInText(-30), '⁦-30 ₪⁩');
});

test('a balance already shown with thousands separators keeps its value', () => {
    assert.equal(signedBalance('-100,338'), '-100,338');
    assert.equal(balanceInText(signedBalance('-100321.50')), '⁦-100,321.50 ₪⁩');
    assert.equal(balanceTone('1,250.75'), 'owes');
});

test('on paper the balance carries its word', () => {
    assert.equal(balanceWord(120), 'عليه');
    assert.equal(balanceWord('-30.5'), 'له');
    assert.equal(balanceWord('0.004'), 'مسدّد');
    assert.equal(balanceOnPaper('-30.5'), '\u2066-30.50 ₪\u2069 له');
    assert.equal(balanceOnPaper(120, 'شيكل'), '\u2066120\u2069 شيكل عليه');
});

test('a subscriber is far behind once they owe more than four weeks of their minimum', () => {
    assert.equal(isFarBehind(81, 20), true);
    assert.equal(isFarBehind(80, 20), false);
    assert.equal(isFarBehind('-50', 20), false);
    assert.equal(isFarBehind(500, 0), false);
});
