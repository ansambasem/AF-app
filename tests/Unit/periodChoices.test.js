import { test } from 'node:test';
import assert from 'node:assert/strict';
import { monthsOf, newestOfMonth, newestOfYear, periodsOf } from '../../resources/js/lib/periodChoices.js';

const choices = {
    years: [
        { value: '2026', months: [
            { value: '2026-11', periods: [{ value: '9', label: 'b' }, { value: '8', label: 'a' }] },
            { value: '2026-10', periods: [{ value: '7', label: '30/10 – 5/11' }] },
        ] },
        { value: '2024', months: [{ value: '2024-03', periods: [{ value: '1', label: 'old' }] }] },
    ],
    year: '2026', month: '2026-11', value: '9',
};

test('a year lists only its months and a month only the periods created in it', () => {
    assert.deepEqual(monthsOf(choices, '2026').map((month) => month.value), ['2026-11', '2026-10']);
    assert.deepEqual(periodsOf(choices, '2026-10').map((period) => period.value), ['7']);
    assert.deepEqual(monthsOf(choices, '2025'), []);
    assert.deepEqual(periodsOf(choices, null), []);
});

test('another year or month opens on its newest period', () => {
    assert.equal(newestOfYear(choices, '2024'), '1');
    assert.equal(newestOfYear(choices, '2026'), '9');
    assert.equal(newestOfMonth(choices, '2026-10'), '7');
    assert.equal(newestOfMonth(choices, '2025-01'), null);
});
