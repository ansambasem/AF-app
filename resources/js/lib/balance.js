import { formatMoney } from './format.js';

/**
 * How a balance is shown everywhere, by the company's rule: positive is
 * what the subscription owes (in the company's favour), negative is credit
 * in the subscription's favour, shown with its minus sign. Only balances
 * carry a sign; payment and transaction amounts are always shown positive.
 */

/** What each kind of balance means, shown on hover. */
export const BALANCE_HINTS = {
    owes: 'عليه — لصالح الشركة',
    credit: 'له — لصالح المشترك',
    settled: 'مسدّد',
};

/**
 * What each kind of balance is called on paper, where neither colour nor
 * hover can say it: printed beside the signed balance ("-30.50 ₪ له").
 */
export const BALANCE_WORDS = {
    owes: 'عليه',
    credit: 'له',
    settled: 'مسدّد',
};

/** The colour of a balance where it is coloured: red owed, green credit, grey settled. */
export const BALANCE_TEXT_CLASSES = {
    owes: 'text-red-600 dark:text-red-400',
    credit: 'text-emerald-600 dark:text-emerald-400',
    settled: 'text-gray-500',
};

/** The same colours as a soft chip. */
export const BALANCE_CHIP_CLASSES = {
    owes: 'bg-red-500/10 text-red-700 dark:text-red-400',
    credit: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    settled: 'bg-gray-100 text-gray-600',
};

/** A balance in cents, whether given as a number or as text already shown with thousands separators ("-100,338"). */
function cents(value) {
    return Math.round(Number(String(value ?? 0).replaceAll(',', '')) * 100) || 0;
}

/** Owed, credit or settled, to the cent. */
export function balanceTone(value) {
    const amount = cents(value);

    return amount > 0 ? 'owes' : amount < 0 ? 'credit' : 'settled';
}

/** The balance as a number with its sign: "120", "-30.50", "0". */
export function signedBalance(value) {
    const amount = cents(value);

    return `${amount < 0 ? '-' : ''}${formatMoney(Math.abs(amount) / 100)}`;
}

/** Whether a unit is a word ("شيكل"), read after the number, rather than a symbol ("₪") kept beside it. */
export function isWordUnit(unit) {
    return /[\u0600-\u06FF]/.test(unit ?? '');
}

/**
 * The balance with its unit for running text ("الرصيد بعد: ‎-30 ₪"),
 * isolated left to right so the minus stays before the number inside
 * Arabic text. A word unit stays outside the isolate so it reads after
 * the number.
 */
export function balanceInText(value, unit = '₪') {
    return isWordUnit(unit) ? `\u2066${signedBalance(value)}\u2069 ${unit}` : `\u2066${signedBalance(value)} ${unit}\u2069`;
}

/** "عليه", "له" or "مسدّد" for the balance. */
export function balanceWord(value) {
    return BALANCE_WORDS[balanceTone(value)];
}

/** The balance as printed: signed, with its unit and its word ("-30.50 ₪ له"). */
export function balanceOnPaper(value, unit = '₪') {
    return `${balanceInText(value, unit)} ${balanceWord(value)}`;
}

/** How many weeks of their minimum a subscriber may owe before their row is marked as far behind. */
export const FAR_BEHIND_WEEKS = 4;

/** Whether a subscriber owes more than FAR_BEHIND_WEEKS weeks of their weekly minimum payment. */
export function isFarBehind(balance, weeklyMinimum) {
    const owed = Number(balance ?? 0);
    const minimum = Number(weeklyMinimum ?? 0);

    return minimum > 0 && owed > minimum * FAR_BEHIND_WEEKS;
}
