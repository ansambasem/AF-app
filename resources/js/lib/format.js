// Arabic with the usual month names (سبتمبر, أكتوبر) and Western digits, as the app writes them.
export const DATE_LOCALE = 'ar-EG-u-nu-latn';

const DAY_FORMAT = new Intl.DateTimeFormat(DATE_LOCALE, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
const SHORT_DAY_FORMAT = new Intl.DateTimeFormat(DATE_LOCALE, { day: 'numeric', month: 'long', timeZone: 'UTC' });
const MONTH_NAME_FORMAT = new Intl.DateTimeFormat(DATE_LOCALE, { month: 'long', timeZone: 'UTC' });
const RELATIVE_FORMAT = new Intl.RelativeTimeFormat('ar', { numeric: 'always' });

const HONORIFICS = new Set(['mr', 'mrs', 'ms', 'dr', 'prof']);

/**
 * Up to two letters for an avatar tile: the first letters of the name's
 * first two words, skipping a leading title ("Dr. Joany Upton" → "JU",
 * "karrada.admin" → "KA"), or one letter for a single word. Latin
 * letters are upper-cased.
 */
export function initials(name) {
    let words = (name ?? '')
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length > 2 && HONORIFICS.has(words[0].toLowerCase())) {
        words = words.slice(1);
    }

    return words
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

/** A whole number with thousands separators: 12170 → "12,170". */
export function formatNumber(value) {
    return Math.round(Number(value ?? 0)).toLocaleString('en-US');
}

/**
 * An amount of money with thousands separators, and two decimals only when
 * it has any: 12170 → "12,170", 58.234 → "58.23", 1255.5 → "1,255.50".
 */
export function formatMoney(amount) {
    const cents = Math.round(Number(amount ?? 0) * 100);
    const digits = cents % 100 === 0 ? 0 : 2;

    return ((cents || 0) / 100).toLocaleString('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/** A part of a whole as a whole percentage (96 of 159 → 60), or 0 when the whole is empty. */
export function percentOf(part, whole) {
    return whole > 0 ? Math.round((part / whole) * 100) : 0;
}

/**
 * A typed number as plain digits, for a money field: Arabic-Indic digits
 * become 0-9, the Arabic decimal mark a point, thousands separators and
 * anything else are dropped, and at most `decimals` digits stay after the
 * first point ("١٬٢٥٠٫٥٧٩" → "1250.57").
 */
export function normalizeDecimalInput(text, decimals = 2) {
    const [whole, ...fraction] = String(text ?? '')
        .replace(/[\u0660-\u0669\u06f0-\u06f9]/g, (digit) => String(digit.charCodeAt(0) % 16))
        .replace(/\u066b/g, '.')
        .replace(/[^0-9.]/g, '')
        .split('.');

    return fraction.length > 0 ? `${whole}.${fraction.join('').slice(0, decimals)}` : whole;
}

/** A calendar date (Y-m-d) at midnight UTC, so formatting never shifts it by a day. */
function calendarDate(day) {
    return new Date(`${day}T00:00:00Z`);
}

/** The viewer's local calendar date (Y-m-d) of a moment. */
export function localDay(date = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * A date the server sent as people read it, day first: '2026-10-29' →
 * '29/10/2026', and '2026-10-29 18:58' → '29/10/2026 18:58' (whatever follows the date stays). Anything else
 * (empty, or already written for people) comes back as it is.
 */
export function formatDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})((?: .*)?)$/.exec(String(value ?? ''));

    return match ? `${match[3]}/${match[2]}/${match[1]}${match[4]}` : (value ?? '');
}

/** The usual name of a day's month, e.g. "أبريل" for '2026-04-12'. */
export function formatMonthName(day) {
    return MONTH_NAME_FORMAT.format(new Date(`${day}T00:00:00Z`));
}

/** "السبت، 26 سبتمبر" for a Y-m-d date. */
export function formatDayLabel(day) {
    return DAY_FORMAT.format(calendarDate(day));
}

/** "26 سبتمبر" for a Y-m-d date. */
export function formatShortDay(day) {
    return SHORT_DAY_FORMAT.format(calendarDate(day));
}

/** "5:24 م" for a 24-hour "17:24" wall-clock time, as the server recorded it. */
export function formatClock(time) {
    const [hours, minutes] = time.split(':').map(Number);

    return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'ص' : 'م'}`;
}

/** "قبل 5 ساعات" or "قبل يومين" for an ISO timestamp, or "—" when there is none. */
export function timeAgo(timestamp, now = new Date()) {
    if (!timestamp) {
        return '—';
    }

    // Always in the past: a clock slightly ahead of the viewer's still reads "a minute ago".
    const minutes = Math.min(Math.round((new Date(timestamp) - now) / 60000), -1);

    if (minutes > -60) {
        return RELATIVE_FORMAT.format(minutes, 'minute');
    }

    if (minutes > -60 * 24) {
        return RELATIVE_FORMAT.format(Math.round(minutes / 60), 'hour');
    }

    return RELATIVE_FORMAT.format(Math.round(minutes / (60 * 24)), 'day');
}
