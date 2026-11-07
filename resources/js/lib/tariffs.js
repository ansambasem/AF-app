import { roundToCents } from './currency.js';

/** A price change this big (in percent, either way) is flagged before saving. */
export const LARGE_RATE_CHANGE = 20;

/**
 * What changing a kilo price from `rate` to the typed `text` does: the new
 * price, the difference in shekels and in percent, and whether it can be
 * saved (a valid price above zero that differs from the current one).
 */
export function rateChange(rate, text) {
    const current = Number(rate);
    const next = Number(text);

    if (text === '' || !Number.isFinite(next) || next <= 0) {
        return { valid: false, changed: false, rate: null, difference: 0, percent: 0, large: false };
    }

    const difference = roundToCents(next - current);
    const percent = current > 0 ? (difference / current) * 100 : 0;

    return {
        valid: true,
        changed: difference !== 0,
        rate: roundToCents(next),
        difference,
        percent,
        large: Math.abs(percent) >= LARGE_RATE_CHANGE,
    };
}

/** The price moved by a quick step (±0.10, ±0.25), never below 0.05. */
export function stepRate(text, step) {
    return Math.max(0.05, roundToCents((Number(text) || 0) + step)).toFixed(2);
}
