import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addDays, addMonths, clampDay, isIsoDay, isOutOfRange, monthGrid } from '../../resources/js/lib/datePicker.js';

test('the month grid starts on the Saturday on or before the 1st and runs six full weeks', () => {
    // 1 October 2026 is a Thursday: the grid opens on Saturday 26 September.
    const grid = monthGrid('2026-10-22');
    assert.equal(grid.length, 6);
    assert.ok(grid.every((week) => week.length === 7));
    assert.equal(grid[0][0], '2026-09-26');
    assert.equal(grid[0][5], '2026-10-01');
    assert.equal(grid[5][6], '2026-11-06');
});

test('a month that starts on a Saturday opens on its own 1st', () => {
    assert.equal(monthGrid('2026-08-15')[0][0], '2026-08-01');
});

test('moving by months keeps the day, held to the last day of a shorter month', () => {
    assert.equal(addMonths('2026-01-31', 1), '2026-02-28');
    assert.equal(addMonths('2028-01-31', 1), '2028-02-29');
    assert.equal(addMonths('2026-03-15', -3), '2025-12-15');
    assert.equal(addMonths('2026-10-22', 12), '2027-10-22');
});

test('moving by days crosses months and years', () => {
    assert.equal(addDays('2026-12-31', 1), '2027-01-01');
    assert.equal(addDays('2026-03-01', -1), '2026-02-28');
});

test('days outside the allowed range are blocked and pulled back to its nearest end', () => {
    assert.equal(isOutOfRange('2026-10-23', null, '2026-10-22'), true);
    assert.equal(isOutOfRange('2026-10-22', '2026-10-22', '2026-10-22'), false);
    assert.equal(clampDay('2026-10-30', '2026-10-01', '2026-10-22'), '2026-10-22');
    assert.equal(clampDay('2026-09-30', '2026-10-01', undefined), '2026-10-01');
});

test('only real Y-m-d dates count as a value', () => {
    assert.equal(isIsoDay('2026-10-22'), true);
    assert.equal(isIsoDay('2026-02-30'), false);
    assert.equal(isIsoDay(''), false);
    assert.equal(isIsoDay(null), false);
});
