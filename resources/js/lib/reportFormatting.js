/** Format report amounts with two decimals, as on the financial reports. */
export function reportMoney(amount) {
    return Number(amount ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Format a YYYY-MM-DD report date, optionally including its year. */
export function shortDate(isoDate, withYear = false) {
    const [year, month, day] = isoDate.split('-');

    return withYear ? `${day}/${month}/${year}` : `${day}/${month}`;
}
