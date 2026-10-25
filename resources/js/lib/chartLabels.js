/** The room one day label needs under a chart (the widest, "28 تشرين الأول" at 12px, is 71px), so labels never run into each other. */
export const LABEL_WIDTH = 88;

/**
 * Where a day's label starts, in pixels from the chart's left edge: a
 * LABEL_WIDTH-wide slot centred under the day's column, moved in where it
 * would spill past either edge of the chart.
 */
export function labelLeft(index, count, width) {
    const centred = ((index + 0.5) / count) * width - LABEL_WIDTH / 2;

    return Math.max(0, Math.min(centred, width - LABEL_WIDTH));
}

/**
 * Which days get a label under the chart: today (the last) and every
 * `step`-th day back from it, with the smallest step whose labels don't
 * overlap in `width` pixels.
 */
export function labelledDays(count, width) {
    for (let step = 1; step <= count; step++) {
        const days = [];

        for (let index = count - 1; index >= 0; index -= step) {
            days.push(index);
        }

        const fits = days.every((index, position) => position === 0 || labelLeft(days[position - 1], count, width) - labelLeft(index, count, width) >= LABEL_WIDTH - 0.5);

        if (fits || step === count) {
            return Array.from({ length: count }, (_, index) => days.includes(index));
        }
    }

    return [];
}
