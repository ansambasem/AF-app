/**
 * Picking a billing period by the year, then the month, it was created in,
 * then the period itself (PeriodPicker on the server builds the choices).
 * Another year or month opens on its newest period, so the page always
 * shows one period's figures.
 */

/** The months of `year`, newest first; none for a year not offered. */
export function monthsOf(choices, year) {
    return choices.years.find((option) => option.value === year)?.months ?? [];
}

/** The periods created in `month` ("2026-10"), newest first. */
export function periodsOf(choices, month) {
    return monthsOf(choices, month?.slice(0, 4)).find((option) => option.value === month)?.periods ?? [];
}

/** What picking `year` shows: its newest month's newest period, or null. */
export function newestOfYear(choices, year) {
    return monthsOf(choices, year)[0]?.periods[0]?.value ?? null;
}

/** What picking `month` shows: its newest period, or null. */
export function newestOfMonth(choices, month) {
    return periodsOf(choices, month)[0]?.value ?? null;
}
