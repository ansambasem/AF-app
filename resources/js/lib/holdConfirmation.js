export const HOLD_DURATION = 3000;

export function holdProgress(elapsed, duration = HOLD_DURATION) {
    return elapsed > 0 ? Math.min(100, Math.floor((elapsed / duration) * 100)) : 0;
}

export function westernDigits(text) {
    return String(text ?? '').replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660));
}

export function typedCountMatches(typed, count) {
    const digits = westernDigits(typed).trim();
    return /^\d+$/.test(digits) && Number(digits) === count && count > 0;
}
