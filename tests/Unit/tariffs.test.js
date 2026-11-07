import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rateChange, stepRate } from '../../resources/js/lib/tariffs.js';

test('a new price reports its difference and flags a change of 20% or more', () => {
    assert.deepEqual(rateChange('3.00', '3.25'), { valid: true, changed: true, rate: 3.25, difference: 0.25, percent: 8.333333333333332, large: false });
    assert.equal(rateChange('3.00', '2.40').large, true);
    assert.equal(rateChange('3.00', '3').changed, false);
});

test('an empty, zero or negative price cannot be saved', () => {
    for (const text of ['', '0', '-1', 'abc']) {
        assert.equal(rateChange('3.00', text).valid, false);
    }
});

test('a step moves the price by cents and never below five agorot', () => {
    assert.equal(stepRate('3.00', 0.1), '3.10');
    assert.equal(stepRate('0.10', -0.25), '0.05');
});
