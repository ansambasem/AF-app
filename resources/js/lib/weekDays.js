import { formatDate } from './format.js';

// Carbon day-of-week numbers (0 = Sunday … 6 = Saturday), listed in the
// local week order, Saturday first.
export const WEEK_DAYS = [
    { value: 6, label: 'السبت' },
    { value: 0, label: 'الأحد' },
    { value: 1, label: 'الاثنين' },
    { value: 2, label: 'الثلاثاء' },
    { value: 3, label: 'الأربعاء' },
    { value: 4, label: 'الخميس' },
    { value: 5, label: 'الجمعة' },
];

/** The weekday a Y-m-d date falls on, e.g. 'الخميس'. */
export function weekDayName(isoDate) {
    const day = new Date(`${isoDate}T00:00:00Z`).getUTCDay();

    return WEEK_DAYS.find((weekDay) => weekDay.value === day).label;
}

/** Y-m-d → 'الخميس 24/09/2026', the format used across the app's Arabic screens. */
export function formatWeekDay(isoDate) {
    return `${weekDayName(isoDate)} ${formatDate(isoDate)}`;
}
