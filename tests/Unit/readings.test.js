import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hasLatestWeekReading, lastReadingBalanceChange, lastReadingNeedsUnusualConfirmation, readingDiscount, steppedReading, weekOptionsFor, weeklyCharges } from '../../resources/js/lib/readings.js';

// The example week: 5 kilos at 30 shekels a kilo is a 150 shekel bill; the minimum is 20 a week.
test('a week is billed consumption × kilo price, less its standing discount', () => {
    assert.deepEqual(weeklyCharges(5, '30.00', '20.00'), { readingFee: 150, discountAmount: 0, amountDue: 150, minimumApplies: false });
    assert.deepEqual(weeklyCharges(5, '30.00', '20.00', { method: 'kilowatt', value: '3' }), {
        readingFee: 150,
        discountAmount: 90,
        amountDue: 60,
        minimumApplies: false,
    });
    assert.deepEqual(weeklyCharges(5, '30.00', '20.00', { method: 'percentage', value: '10' }), {
        readingFee: 150,
        discountAmount: 15,
        amountDue: 135,
        minimumApplies: false,
    });
    assert.deepEqual(weeklyCharges(5, '30.00', '20.00', { method: 'shekel', value: '5' }), {
        readingFee: 150,
        discountAmount: 25,
        amountDue: 125,
        minimumApplies: false,
    });
});

test('the weekly minimum is due when the week comes to less, unless the subscription has a standing discount', () => {
    assert.deepEqual(weeklyCharges(0.5, '30.00', '20.00'), { readingFee: 15, discountAmount: 0, amountDue: 20, minimumApplies: true });
    assert.deepEqual(weeklyCharges(2, '30.00', '20.00', { method: 'kilowatt', value: '3' }), {
        readingFee: 60,
        discountAmount: 60,
        amountDue: 0,
        minimumApplies: false,
    });
    assert.deepEqual(weeklyCharges(0.5, '30.00', '20.00', { method: 'percentage', value: '50' }), {
        readingFee: 15,
        discountAmount: 7.5,
        amountDue: 7.5,
        minimumApplies: false,
    });
});

test('a standing discount never takes off more than the reading, nor anything from a negative one', () => {
    assert.equal(readingDiscount({ method: 'kilowatt', value: '10' }, 2.5, '0.60'), 1.5);
    assert.equal(readingDiscount({ method: 'shekel', value: '40' }, 2, '30.00'), 60);
    assert.equal(readingDiscount({ method: 'percentage', value: '10' }, 3.33, '0.45'), 0.15);
    assert.equal(readingDiscount({ method: 'kilowatt', value: '3' }, -2, '30.00'), 0);
});

test('free kilowatts leave only the kilos above them to pay, whatever the minimum: 3 kilos at 30 with 2 free comes to 30', () => {
    assert.deepEqual(weeklyCharges(3, '30.00', '53.54', { method: 'kilowatt', value: '2' }), {
        readingFee: 90,
        discountAmount: 60,
        amountDue: 30,
        minimumApplies: false,
    });
});

test('the + and - steps move a reading by one kilo and never below the last reading', () => {
    assert.equal(steppedReading('6905', '6901', 1), '6906');
    assert.equal(steppedReading('6905', '6901', -1), '6904');
    assert.equal(steppedReading('6901', '6901', -1), '6901');
    assert.equal(steppedReading('6901.5', 6901, 1), '6902.5');
    // From an empty field they start at the last reading.
    assert.equal(steppedReading('', '6901', 1), '6902');
    assert.equal(steppedReading('', '6901', -1), '6901');
    assert.equal(steppedReading('abc', '6901', 1), '6902');
});

// A last reading steps between where its own week started and the reading of the week after it.
test('a last reading never steps past the reading of the week after it', () => {
    assert.equal(steppedReading('6904.5', '6901', 1, '6905'), '6905');
    assert.equal(steppedReading('6905', '6901', 1, 6905), '6905');
    assert.equal(steppedReading('6905', '6901', -1, '6905'), '6904');
    assert.equal(steppedReading('6901.5', '6901', -1, '6905'), '6901');
});

// Each branch reads on its own weekly reading day, so a subscription's weeks are its branch's.
test("a subscription's weeks are the ones listed for its branch", () => {
    const byBranch = { 3: [{ value: '2026-09-18' }], 7: [{ value: '2026-09-13' }] };
    const subscription = { branch_id: 7, meterReadings: [{ weekStart: '2026-09-13' }] };

    assert.deepEqual(weekOptionsFor(subscription, byBranch), [{ value: '2026-09-13' }]);
    assert.deepEqual(weekOptionsFor({ branch_id: 9 }, byBranch), []);
    assert.deepEqual(weekOptionsFor(null, byBranch), []);
    assert.equal(hasLatestWeekReading(subscription, weekOptionsFor(subscription, byBranch)), true);
    assert.equal(hasLatestWeekReading({ ...subscription, branch_id: 3 }, weekOptionsFor({ branch_id: 3 }, byBranch)), false);
});

test('an edited last reading changes the balance by what the billed weeks now come to', () => {
    const lastWeek = { isApproved: true, unusual: false, before: { amountDue: '100.00' }, after: { amountDue: '80.00' } };
    const thisWeek = { isApproved: true, unusual: false, before: { amountDue: '60.00' }, after: { amountDue: '80.10' } };

    assert.equal(lastReadingBalanceChange([lastWeek, thisWeek]), 0.1);
    assert.equal(lastReadingBalanceChange([lastWeek, { ...thisWeek, isApproved: false }]), -20);
    assert.equal(lastReadingBalanceChange(undefined), 0);
    assert.equal(lastReadingBalanceChange([lastWeek, { ...thisWeek, after: null }]), null);
});

test('only an approved week made unusual asks the editor to confirm it', () => {
    assert.equal(lastReadingNeedsUnusualConfirmation([{ isApproved: true, unusual: false }, { isApproved: false, unusual: true }]), false);
    assert.equal(lastReadingNeedsUnusualConfirmation([{ isApproved: true, unusual: false }, { isApproved: true, unusual: true }]), true);
});
