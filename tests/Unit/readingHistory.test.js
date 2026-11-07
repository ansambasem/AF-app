import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupReadingsByMonth, readingHistoryCsv, readingTotals, readingsInPeriod } from '../../resources/js/lib/readingHistory.js';

test('history periods use calendar weeks and preserve the subscription input order', () => {
    const readings = [
        { id: 1, weekEnd: '2026-07-02' },
        { id: 2, weekEnd: '2026-07-09' },
        { id: 3, weekEnd: '2026-09-24' },
    ];

    assert.deepEqual(readingsInPeriod(readings, '12').map((reading) => reading.id), [3, 2]);
    assert.deepEqual(readingsInPeriod(readings, '26').map((reading) => reading.id), [3, 2, 1]);
    assert.deepEqual(readingsInPeriod(readings, '52').map((reading) => reading.id), [3, 2, 1]);
    assert.deepEqual(readingsInPeriod(readings, 'all').map((reading) => reading.id), [3, 2, 1]);
    assert.deepEqual(readings.map((reading) => reading.id), [1, 2, 3]);
    assert.deepEqual(readingsInPeriod([], '12'), []);
});

test('history totals preserve recorded bills, discounts and decimal consumption', () => {
    const readings = [
        { consumption: '0.1', discountAmount: '0.2', amountDue: '20.00' },
        { consumption: '0.2', discountAmount: '0.4', amountDue: '5.55' },
    ];

    assert.deepEqual(readingTotals(readings), { consumption: 0.3, discount: 0.6, due: 25.55 });
    assert.deepEqual(readingTotals([]), { consumption: 0, discount: 0, due: 0 });
});

test('monthly groups use the week end and distinguish the same month in different years', () => {
    const readings = [
        { id: 1, weekStart: '2026-08-28', weekEnd: '2026-09-03', consumption: 1.25, discountAmount: 0, amountDue: 20 },
        { id: 2, weekStart: '2026-08-21', weekEnd: '2026-08-27', consumption: 2, discountAmount: 1, amountDue: 5 },
        { id: 3, weekStart: '2025-08-29', weekEnd: '2025-09-04', consumption: 3, discountAmount: 0, amountDue: 9 },
    ];

    const groups = groupReadingsByMonth(readings);

    assert.deepEqual(groups.map((group) => group.month), ['2026-09', '2026-08', '2025-09']);
    assert.deepEqual(groups[0].totals, { consumption: 1.25, discount: 0, due: 20 });
    assert.deepEqual(groups[1].readings.map((reading) => reading.id), [2]);
});

test('Excel export contains only the selected records and protects user text', () => {
    const reading = {
        weekStart: '2026-09-18', weekEnd: '2026-09-24', previous_reading: 100.25, current_reading: 101.5,
        consumption: 1.25, unitPrice: 30, minimumPayment: 20, discountAmount: 0, amountDue: 20, status: 'approved', statusLabel: 'معتمدة',
        recordedByName: '=1+1', recordedAt: '2026-09-24 12:00', notes: 'ملاحظة "خاصة",\nسطر ثان',
    };
    const hiddenReading = { ...reading, status: 'pending', notes: 'not exported' };
    const csv = readingHistoryCsv([reading, hiddenReading].filter((row) => row.status === 'approved'));

    assert.ok(csv.startsWith('\uFEFF'));
    assert.ok(csv.includes('"100.25","101.5","1.25","30","20","0","20"'));
    assert.ok(csv.includes('"\'=1+1"'));
    assert.ok(csv.includes('"ملاحظة ""خاصة"",\nسطر ثان"'));
    assert.ok(!csv.includes('not exported'));
});

test('Excel leaves a current reading awaiting re-entry empty instead of exporting its old number', () => {
    const csv = readingHistoryCsv([{
        weekStart: '2026-09-18', weekEnd: '2026-09-24', previous_reading: 0, current_reading: 230,
        requiresReentry: true, consumption: 0, unitPrice: 0.5, minimumPayment: 0,
        discountAmount: 0, amountDue: 0, statusLabel: 'بانتظار إدخال الحالية',
    }]);

    assert.ok(csv.includes('"0","","0","0.5"'));
    assert.ok(!csv.includes('"230"'));
});
