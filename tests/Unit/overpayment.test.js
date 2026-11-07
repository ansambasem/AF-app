import { test } from 'node:test';
import assert from 'node:assert/strict';
import { overpaymentLevel } from '../../resources/js/lib/overpayment.js';

test('a payment within what is owed is not an overpayment', () => {
    assert.equal(overpaymentLevel('100', 250), 'none');
    assert.equal(overpaymentLevel('250', 250), 'none');
    assert.equal(overpaymentLevel('', 250), 'none');
    assert.equal(overpaymentLevel('abc', 250), 'none');
});

test('a payment above what is owed leaves a credit and only warns', () => {
    assert.equal(overpaymentLevel('300', 250), 'above');
    assert.equal(overpaymentLevel('500', 250), 'above');
    assert.equal(overpaymentLevel('700', 250), 'above');
    assert.equal(overpaymentLevel('50', 0), 'above');
});

test('a payment far above what is owed must be confirmed', () => {
    assert.equal(overpaymentLevel('5000', 250), 'confirm');
    assert.equal(overpaymentLevel('750', 250), 'confirm');
    assert.equal(overpaymentLevel('500', 0), 'confirm');
    assert.equal(overpaymentLevel('499', 0), 'above');
});

test('it agrees with the server on the same numbers', () => {
    // Mirrors tests/Feature/OverpaymentConfirmationTest.php (debt of 250).
    const confirmed = { 100: false, 250: false, 300: false, 500: false, 700: false, 750: true, 5000: true };

    for (const [amount, needsConfirmation] of Object.entries(confirmed)) {
        assert.equal(overpaymentLevel(amount, 250) === 'confirm', needsConfirmation, `amount ${amount}`);
    }
});
