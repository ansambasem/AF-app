import { roundToCents } from './currency.js';

/**
 * The kWh used between two meter readings, rounded to the two decimal places
 * readings are kept to. Mirrors MeterReading::consumptionBetween().
 */
export function consumptionBetween(previousReading, currentReading) {
    return Math.round((Number(currentReading) - Number(previousReading)) * 100) / 100;
}

/**
 * The reading after a + (1) or − (-1) step of one kilo from what is typed, as the text for
 * the field. From an empty (or unreadable) field it starts at `previousReading`: + gives the
 * last reading plus one, − the last reading itself. It never goes below `previousReading`,
 * nor above `nextReading` when one is given (a last reading, which the week after starts from).
 */
export function steppedReading(value, previousReading, direction, nextReading = null) {
    const typed = value !== '' && !Number.isNaN(Number(value));
    const base = typed ? Number(value) : Number(previousReading);
    const next = typed || direction > 0 ? base + direction : base;
    const ceiling = nextReading === null || nextReading === undefined ? Infinity : Number(nextReading);

    return String(Math.round(Math.min(ceiling, Math.max(Number(previousReading), next)) * 100) / 100);
}

/**
 * What a standing discount (`{ method, value }`) takes off a week's reading
 * fee, in shekels: a percentage of the fee, kilowatts of the consumption at
 * the kilo price, or shekels off the price of each kilo — never more than
 * the fee. Mirrors MeterReading::discountFor().
 */
export function readingDiscount(discount, consumption, unitPrice) {
    const kilos = Number(consumption);
    const price = Number(unitPrice);
    const value = Number(discount.value);

    if (!(kilos > 0) || !(value > 0)) {
        return 0;
    }

    const readingFee = roundToCents(kilos * price);
    const amount = {
        percentage: (readingFee * value) / 100,
        kilowatt: Math.min(value, kilos) * price,
        shekel: kilos * Math.min(value, price),
    }[discount.method];

    return Math.min(roundToCents(amount ?? 0), readingFee);
}

/**
 * What a week's consumption costs: consumption × kilo price, but never
 * less than the minimum payment. With a standing discount the subscription
 * pays the fee less the discount instead, with no minimum: only the kilos
 * the discount leaves are paid for. `discountAmount` is what the discount
 * took off and `minimumApplies` whether the minimum payment is what is due.
 * Mirrors MeterReading::chargesFor().
 */
export function weeklyCharges(consumption, unitPrice, minimumPayment, discount = null) {
    const readingFee = roundToCents(Number(consumption) * Number(unitPrice));

    if (discount) {
        const discountAmount = readingDiscount(discount, consumption, unitPrice);

        return { readingFee, discountAmount, amountDue: roundToCents(readingFee - discountAmount), minimumApplies: false };
    }

    const minimum = Number(minimumPayment);

    return { readingFee, discountAmount: 0, amountDue: Math.max(readingFee, minimum), minimumApplies: readingFee < minimum };
}

/**
 * A subscription row of the subscriptions list as the reading form's
 * `fixedSubscription`: who it is and the reading the new week starts from.
 */
export function readingOptionFor(subscription) {
    return {
        value: String(subscription.id),
        label: `${subscription.account_number} — ${(subscription.display_name ?? subscription.full_name)}`,
        lastReading: subscription.lastReading,
        lastWeekStart: subscription.lastReadingWeekStart,
    };
}

/**
 * The recent weeks of the subscription's branch, out of the lists the server
 * sends per branch id: each branch reads on its own weekly reading day.
 */
export function weekOptionsFor(subscription, weekOptionsByBranch) {
    return weekOptionsByBranch?.[subscription?.branch_id] ?? [];
}

/**
 * Whether the subscription's reading for the latest ended week (the first of
 * `weekOptions`) has been entered already.
 */
export function hasLatestWeekReading(subscription, weekOptions) {
    const latestReading = subscription.meterReadings?.[0];

    return Boolean(latestReading && latestReading.weekStart === weekOptions[0]?.value);
}

/**
 * What confirming an edited last reading does to the subscriber's balance,
 * in shekels: the change in amount due of each week already billed
 * (approved), since only those are billed again at once. Weeks still
 * waiting for approval are billed when they are approved, as usual. Null
 * while a billed week is not worked out yet.
 */
export function lastReadingBalanceChange(weeks) {
    const billed = (weeks ?? []).filter((week) => week.isApproved);

    // Not known until every billed week is worked out (this week's reading typed again).
    if (billed.some((week) => !week.after)) {
        return null;
    }

    const cents = billed.reduce((total, week) => total + Math.round(Number(week.after.amountDue) * 100) - Math.round(Number(week.before.amountDue) * 100), 0);

    return cents / 100;
}

/** Whether an approved week of the edit becomes unusual, so confirming it takes the editor's say-so. */
export function lastReadingNeedsUnusualConfirmation(weeks) {
    return (weeks ?? []).some((week) => week.isApproved && week.unusual);
}
