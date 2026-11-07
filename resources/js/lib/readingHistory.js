import { csvText } from './csv.js';
import { roundToCents } from './currency.js';

/** Periods are measured back from the subscription's latest recorded week. */
export function readingsInPeriod(readings, period) {
    const sorted = [...readings].sort((a, b) => b.weekEnd.localeCompare(a.weekEnd));

    if (period === 'all' || sorted.length === 0) {
        return sorted;
    }

    const cutoff = new Date(`${sorted[0].weekEnd}T00:00:00Z`);
    cutoff.setUTCDate(cutoff.getUTCDate() - Number(period) * 7);

    return sorted.filter((reading) => reading.weekEnd > cutoff.toISOString().slice(0, 10));
}

export function readingTotals(readings) {
    return readings.reduce((totals, reading) => ({
        consumption: roundToCents(totals.consumption + Number(reading.consumption)),
        discount: roundToCents(totals.discount + Number(reading.discountAmount)),
        due: roundToCents(totals.due + Number(reading.amountDue)),
    }), { consumption: 0, discount: 0, due: 0 });
}

export function groupReadingsByMonth(readings) {
    const groups = new Map();

    for (const reading of readings) {
        const month = reading.weekEnd.slice(0, 7);
        if (!groups.has(month)) {
            groups.set(month, { month, readings: [] });
        }
        groups.get(month).readings.push(reading);
    }

    return [...groups.values()].map((group) => ({ ...group, totals: readingTotals(group.readings) }));
}

/** UTF-8 CSV for Excel; user-authored text stays text, including formula prefixes. */
export function readingHistoryCsv(readings) {
    const rows = [
        ['بداية الأسبوع', 'نهاية الأسبوع', 'السابقة', 'الحالية', 'الاستهلاك', 'سعر الكيلو', 'الحد الأدنى', 'الخصم', 'المستحق', 'الحالة', 'سجّلها', 'وقت التسجيل', 'ملاحظات'],
        ...readings.map((reading) => [
            reading.weekStart, reading.weekEnd, Number(reading.previous_reading), reading.requiresReentry ? '' : Number(reading.current_reading),
            Number(reading.consumption), Number(reading.unitPrice), Number(reading.minimumPayment), Number(reading.discountAmount), Number(reading.amountDue),
            reading.statusLabel, reading.recordedByName ?? '', reading.recordedAt ?? '', reading.notes ?? '',
        ]),
    ];

    return csvText(rows);
}
