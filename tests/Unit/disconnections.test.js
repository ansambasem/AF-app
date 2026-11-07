import { test } from 'node:test';
import assert from 'node:assert/strict';
import { feeError, fillPlaceholders, holdProgress, itemTone, smsParts, totalFees, typedCountMatches } from '../../resources/js/lib/disconnections.js';

test('the hold reaches 100% only after the full three seconds', () => {
    assert.equal(holdProgress(0), 0);
    assert.equal(holdProgress(-20), 0);
    assert.equal(holdProgress(1500), 50);
    assert.equal(holdProgress(2999), 99);
    assert.equal(holdProgress(3000), 100);
    assert.equal(holdProgress(9000), 100);
});

test('typing the number of subscribers confirms, in Western or Arabic digits', () => {
    assert.equal(typedCountMatches('12', 12), true);
    assert.equal(typedCountMatches(' ١٢ ', 12), true);
    assert.equal(typedCountMatches('11', 12), false);
    assert.equal(typedCountMatches('12a', 12), false);
    assert.equal(typedCountMatches('0', 0), false);
});

test('an Arabic SMS splits at 70 characters, then every 67', () => {
    assert.equal(smsParts(''), 0);
    assert.equal(smsParts('م'.repeat(70)), 1);
    assert.equal(smsParts('م'.repeat(71)), 2);
    assert.equal(smsParts('م'.repeat(135)), 3);
    assert.equal(smsParts('a'.repeat(160)), 1);
});

test('placeholders are filled in and unknown ones are left as written', () => {
    assert.equal(fillPlaceholders('{الاسم}، القطع {موعد_القطع} {غير}', { الاسم: 'سامي', موعد_القطع: '25/10/2026' }), 'سامي، القطع 25/10/2026 {غير}');
});

test('fees add up to two decimals and overdue items stand out', () => {
    assert.equal(totalFees([{ fee: '20.10' }, { fee: '20.20' }, { fee: null }]), 40.3);
    assert.equal(itemTone({ status: 'scheduled', isOverdue: true }), 'red');
    assert.equal(itemTone({ status: 'scheduled', isOverdue: false }), 'amber');
    assert.equal(itemTone({ status: 'disconnected' }), 'gray');
});

test('the first fee error is found whichever subscriber it belongs to', () => {
    assert.equal(feeError({ confirmed: 'x', 'fees.12': 'رسوم القطع لا تقل عن 0.' }), 'رسوم القطع لا تقل عن 0.');
    assert.equal(feeError({ fees: 'افتح المعاينة قبل التأكيد.' }), 'افتح المعاينة قبل التأكيد.');
    assert.equal(feeError({ confirmed: 'x' }), undefined);
    assert.equal(feeError(undefined), undefined);
});
