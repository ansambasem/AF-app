// Y-m-d dates for the date picker, worked out in UTC so no time zone moves a day.

const pad = (value) => String(value).padStart(2, '0');

/** A Date (read in UTC) as Y-m-d. */
export function toIsoDay(date) {
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** Whether a value is a real Y-m-d date (2026-02-30 is not). */
export function isIsoDay(value) {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && toIsoDay(new Date(`${value}T00:00:00Z`)) === value;
}

/** The day `count` days after (or before, when negative) a Y-m-d date. */
export function addDays(day, count) {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + count);

    return toIsoDay(date);
}

/** The same day `count` months later, held to the last day of a shorter month (31 Jan + 1 → 28 or 29 Feb). */
export function addMonths(day, count) {
    const [year, month, date] = day.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + count, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(date, lastDay));

    return toIsoDay(target);
}

/** Whether a day is outside the allowed range (either end may be empty). */
export function isOutOfRange(day, min, max) {
    return Boolean((min && day < min) || (max && day > max));
}

/** The nearest allowed day to `day`. */
export function clampDay(day, min, max) {
    if (min && day < min) {
        return min;
    }

    return max && day > max ? max : day;
}

/**
 * The weeks shown for the month that holds `day`: six rows of seven
 * Y-m-d dates, Saturday first as the local week runs, the days of the
 * months around it filling the first and last rows.
 */
export function monthGrid(day) {
    const [year, month] = day.split('-').map(Number);
    const first = new Date(Date.UTC(year, month - 1, 1));
    // Days since the Saturday on or before the 1st (getUTCDay: 0 = Sunday … 6 = Saturday).
    const lead = (first.getUTCDay() + 1) % 7;
    const start = addDays(toIsoDay(first), -lead);

    return Array.from({ length: 6 }, (_, week) => Array.from({ length: 7 }, (_, weekday) => addDays(start, week * 7 + weekday)));
}

/** Whether two Y-m-d dates fall in the same month. */
export function sameMonth(a, b) {
    return a.slice(0, 7) === b.slice(0, 7);
}
