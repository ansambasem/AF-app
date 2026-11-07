import { test } from 'node:test';
import assert from 'node:assert/strict';
import { periodLabel } from '../../resources/js/lib/closingPeriods.js';

const weekly = { frequency: 'weekly', week_starts_on: 6, cutoff_time: '00:00' };
// Gaza is UTC+3 in October: Saturday 10 October 00:00 there is 9 October 21:00 UTC.
const period = (from, until) => ({ coverage_from: from, coverage_until: until, workflow_settings: weekly });

test('a full week is named by its number', () => {
    assert.equal(periodLabel(period('2026-10-09T21:00:00Z', '2026-10-16T21:00:00Z')), 'الأسبوع 41');
});

test('a week closed early keeps its number, and the period after it runs to the next cutoff under the next number', () => {
    // Cutoff Friday midnight, closed the same Friday at 17:00: the next period holds a little over a week.
    assert.equal(periodLabel(period('2026-10-09T21:00:00Z', '2026-10-16T14:00:00Z')), 'الأسبوع 41');
    assert.equal(periodLabel(period('2026-10-16T14:00:00Z', '2026-10-23T21:00:00Z')), 'الأسبوع 42');
});

test('a week closed days early still keeps its number, and the next period is named by where it closes', () => {
    assert.equal(periodLabel(period('2026-10-09T21:00:00Z', '2026-10-14T09:00:00Z')), 'الأسبوع 41');
    assert.equal(periodLabel(period('2026-10-14T09:00:00Z', '2026-10-23T21:00:00Z')), 'الأسبوع 42');
});

test('a week ending at a cutoff in the evening counts up to that time', () => {
    const evening = { frequency: 'weekly', week_starts_on: 6, cutoff_time: '18:00' };
    assert.equal(periodLabel({ coverage_from: '2026-10-10T15:00:00Z', coverage_until: '2026-10-17T15:00:00Z', workflow_settings: evening }), 'الأسبوع 41');
});

test('a period without its weekly settings is named by the week it began in', () => {
    assert.equal(periodLabel({ coverage_from: '2026-10-09T21:00:00Z' }), 'الأسبوع 41');
    assert.equal(periodLabel(null), '—');
});
