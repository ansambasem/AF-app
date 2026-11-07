import { test } from 'node:test';
import assert from 'node:assert/strict';
import { amountForChargeType } from '../../resources/js/lib/subscriptionCharges.js';

const subscriber = { minimumPayment: '12.50', subscriptionFee: '100.00' };
const types = [{ value: 'penalty', usualAmount: null }, { value: 'disconnection_fee', usualAmount: 20 }];

test('selecting minimum charge uses the subscriber minimum instead of a previous typed amount', () => {
    assert.equal(amountForChargeType({ amount: '' }, 'minimum_charge', subscriber, types), '12.50');
    assert.equal(amountForChargeType({ type: 'penalty', amount: '200' }, 'minimum_charge', subscriber, types), '12.50');
    assert.equal(amountForChargeType({ amount: '' }, 'minimum_charge', { minimumPayment: '0.00' }, types), '0.00');
});

test('switching away from minimum restores the new charge’s default without carrying the minimum into it', () => {
    const minimum = { type: 'minimum_charge', amount: '12.50' };
    assert.equal(amountForChargeType(minimum, 'penalty', subscriber, types), '');
    assert.equal(amountForChargeType(minimum, 'disconnection_fee', subscriber, types), '20');
    assert.equal(amountForChargeType(minimum, 'subscription_fee', subscriber, types), '100.00');
});

test('other charge types keep an amount already entered by the user', () => {
    assert.equal(amountForChargeType({ type: 'penalty', amount: '75' }, 'disconnection_fee', subscriber, types), '75');
});
