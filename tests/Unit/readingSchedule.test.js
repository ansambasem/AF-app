import assert from 'node:assert/strict';
import test from 'node:test';
import { businessClock, entryDaysFrom, isEntryOpen, nextScheduleChange, shiftEntryDays, upcomingWeeks, validEntryHours, weekDates } from '../../resources/js/lib/readingSchedule.js';

const schedule = { mode: 'automatic', open_days: [4], opens_at: '08:30', closes_at: '17:00' };

test('entry days start on the reading day and stop before its next occurrence', () => {
    assert.deepEqual(entryDaysFrom(4), [4, 5, 6, 0, 1, 2, 3]);
    assert.deepEqual(entryDaysFrom(3), [3, 4, 5, 6, 0, 1, 2]);
});

test('changing the reading day carries the selected entry days with it', () => {
    assert.deepEqual(shiftEntryDays([4, 5, 6], 4, 3), [3, 4, 5]);
    assert.deepEqual(shiftEntryDays([3, 4, 5], 3, 6), [0, 1, 6]);
    assert.deepEqual(shiftEntryDays([5, 0], 4, 3), [4, 6]);
    assert.deepEqual(shiftEntryDays([3], 3, 4), [4]);
});

test('preview uses the business date and clock when the browser date is different', () => {
    assert.deepEqual(businessClock(new Date('2026-09-23T22:30:00Z'), 'Asia/Gaza'), {
        date: '2026-09-24', time: '01:30', weekday: 4,
    });
});

test('preview includes both boundary minutes and respects days and manual overrides', () => {
    assert.equal(isEntryOpen(schedule, { weekday: 4, time: '08:29' }), false);
    assert.equal(isEntryOpen(schedule, { weekday: 4, time: '08:30' }), true);
    assert.equal(isEntryOpen(schedule, { weekday: 4, time: '17:00' }), true);
    assert.equal(isEntryOpen(schedule, { weekday: 4, time: '17:01' }), false);
    assert.equal(isEntryOpen(schedule, { weekday: 5, time: '10:00' }), false);
    assert.equal(isEntryOpen({ ...schedule, mode: 'open' }, { weekday: 5, time: '03:00' }), true);
    assert.equal(isEntryOpen({ ...schedule, mode: 'closed' }, { weekday: 4, time: '10:00' }), false);
});

test('next change handles opening today, closing hours, and next week', () => {
    assert.deepEqual(nextScheduleChange(schedule, { date: '2026-09-24', weekday: 4, time: '08:29' }), {
        date: '2026-09-24', weekday: 4, time: '08:30', open: true,
    });
    assert.deepEqual(nextScheduleChange(schedule, { date: '2026-09-24', weekday: 4, time: '08:30' }), {
        date: '2026-09-24', weekday: 4, time: '17:01', open: false,
    });
    assert.deepEqual(nextScheduleChange(schedule, { date: '2026-09-24', weekday: 4, time: '17:01' }), {
        date: '2026-10-01', weekday: 4, time: '08:30', open: true,
    });
});

test('consecutive full days do not report a false closing at midnight', () => {
    const fullDays = { ...schedule, open_days: [4, 5], opens_at: '00:00', closes_at: '23:59' };
    assert.deepEqual(nextScheduleChange(fullDays, { date: '2026-09-24', weekday: 4, time: '10:00' }), {
        date: '2026-09-26', weekday: 6, time: '00:00', open: false,
    });
    assert.equal(nextScheduleChange({ ...fullDays, open_days: [0, 1, 2, 3, 4, 5, 6] }, { date: '2026-09-24', weekday: 4, time: '10:00' }), null);
    assert.equal(nextScheduleChange({ ...schedule, mode: 'open' }, { date: '2026-09-24', weekday: 4, time: '10:00' }), null);
});

test('invalid and empty hours cannot produce an open preview or transition', () => {
    for (const hours of [
        { opens_at: '', closes_at: '' },
        { opens_at: '17:00', closes_at: '08:30' },
        { opens_at: '08:30', closes_at: '08:30' },
        { opens_at: '25:00', closes_at: '26:00' },
    ]) {
        const invalid = { ...schedule, ...hours };
        assert.equal(validEntryHours(invalid), false);
        assert.equal(isEntryOpen(invalid, { weekday: 4, time: '10:00' }), false);
        assert.equal(nextScheduleChange(invalid, { date: '2026-09-24', weekday: 4, time: '10:00' }), null);
    }
});

test('a transition week retains its dates and subsequent weeks are complete', () => {
    const weeks = upcomingWeeks({ start: '2026-09-25', end: '2026-10-03' }, 3);
    assert.deepEqual(weeks, [
        { start: '2026-09-25', end: '2026-10-03' },
        { start: '2026-10-04', end: '2026-10-10' },
        { start: '2026-10-11', end: '2026-10-17' },
    ]);
    assert.equal(weekDates(weeks[0]).length, 9);
    assert.deepEqual(weekDates(weeks[0])[0], { date: '2026-09-25', weekday: 5 });
    assert.deepEqual(weekDates(weeks[0])[8], { date: '2026-10-03', weekday: 6 });
});
