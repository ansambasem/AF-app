import { useEffect, useId, useRef, useState } from 'react';
import { LABEL_WIDTH, labelLeft, labelledDays } from '@/lib/chartLabels';
import { formatNumber, formatShortDay } from '@/lib/format';

const TICKS = 4;

/** A round top for the axis (1, 2 or 5 × 10ⁿ per step) at or above `value`, so the ticks read cleanly. */
function niceMax(value) {
    if (value <= 0) {
        return TICKS;
    }

    // Whole-number steps at least, since the charts count lines and shekels.
    const magnitude = Math.max(1, 10 ** Math.floor(Math.log10(value / TICKS)));
    const step = [1, 2, 5, 10].map((factor) => factor * magnitude).find((candidate) => candidate * TICKS >= value);

    return step * TICKS;
}

/**
 * One column per day, oldest on the left, today (the last) in full
 * burgundy and the rest a lighter tint. Hovering a column — or focusing
 * the chart and moving with the arrow keys — shows its day, value and
 * how many lines it counts; a hidden table carries the same figures for
 * screen readers. `data` is `[{ date: 'Y-m-d', value, count? }]`.
 * `formatValue` writes a value; `countLabel` names what `count` counts.
 */
export default function BarChart({ data, label, formatValue = formatNumber, countLabel = null, height = 170 }) {
    const [active, setActive] = useState(null);
    const tooltipId = useId();
    const max = niceMax(Math.max(0, ...data.map((point) => point.value)));
    const ticks = Array.from({ length: TICKS + 1 }, (_, index) => (max / TICKS) * (TICKS - index));
    const point = active !== null ? data[active] : null;
    // The labels follow the chart's width: fewer on a phone, so they never overlap or spill past the card.
    const labelsRef = useRef(null);
    const [labelsWidth, setLabelsWidth] = useState(7 * LABEL_WIDTH);
    const labelled = labelledDays(data.length, labelsWidth);

    useEffect(() => {
        const element = labelsRef.current;

        if (!element || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(([entry]) => setLabelsWidth(Math.round(entry.contentRect.width)));
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    function onKeyDown(event) {
        const step = { ArrowRight: 1, ArrowLeft: -1, Home: -data.length, End: data.length }[event.key];

        if (step !== undefined) {
            event.preventDefault();
            setActive((current) => Math.min(data.length - 1, Math.max(0, (current ?? data.length - 1) + step)));
        }
    }

    return (
        <figure className="relative" dir="ltr">
            <div className="flex gap-2.5">
                <div
                    className="flex shrink-0 flex-col justify-between text-end font-display text-[12px] tabular-nums text-gray-400"
                    style={{ height }}
                    aria-hidden="true"
                >
                    {ticks.map((tick) => (
                        <span key={tick} className="-my-2 leading-4">
                            {formatNumber(tick)}
                        </span>
                    ))}
                </div>

                <div className="relative min-w-0 flex-1">
                    <div className="pointer-events-none absolute inset-x-0 top-0 flex flex-col justify-between" style={{ height }} aria-hidden="true">
                        {ticks.map((tick) => (
                            <span key={tick} className={`border-t ${tick === 0 ? 'border-gray-300' : 'border-gray-100'}`} />
                        ))}
                    </div>

                    <div
                        role="img"
                        tabIndex={0}
                        aria-label={label}
                        aria-describedby={point ? tooltipId : undefined}
                        onKeyDown={onKeyDown}
                        onFocus={() => setActive((current) => current ?? data.length - 1)}
                        onBlur={() => setActive(null)}
                        onPointerLeave={() => setActive(null)}
                        className="relative flex items-end rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-4 focus-visible:ring-offset-surface"
                        style={{ height }}
                    >
                        {data.map((day, index) => {
                            const isToday = index === data.length - 1;
                            const isActive = index === active;

                            return (
                                // The whole day's slot answers the pointer, not just the painted column.
                                <div
                                    key={day.date}
                                    onPointerEnter={() => setActive(index)}
                                    className="flex h-full flex-1 items-end justify-center px-px"
                                >
                                    {day.value > 0 && (
                                        <span
                                            className={`block w-full max-w-6 rounded-t-[4px] transition-colors ${
                                                isToday || isActive ? 'bg-brand-500' : 'bg-brand-300/80 dark:bg-brand-300'
                                            } ${isActive && !isToday ? 'brightness-110' : ''}`}
                                            style={{ height: `max(2px, ${(day.value / max) * 100}%)` }}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {point && (
                        <div
                            id={tooltipId}
                            role="tooltip"
                            className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 -translate-y-[calc(100%+6px)] whitespace-nowrap rounded-control border border-gray-100 bg-surface px-3 py-2 text-xs shadow-lift"
                            style={{ left: `${Math.min(92, Math.max(8, ((active + 0.5) / data.length) * 100))}%` }}
                            dir="rtl"
                        >
                            <b className="block font-display text-sm text-gray-900">{formatValue(point.value)}</b>
                            <span className="text-gray-500">
                                {formatShortDay(point.date)}
                                {countLabel && point.count !== undefined && ` · ${formatNumber(point.count)} ${countLabel}`}
                            </span>
                        </div>
                    )}

                    <div ref={labelsRef} className="relative mt-2 h-5 text-[12px] text-gray-400" aria-hidden="true">
                        {data.map((day, index) => {
                            if (!labelled[index]) {
                                return null;
                            }

                            // Centred under its column; the ones at the ends are moved in so they stay inside the chart.
                            return (
                                <span
                                    key={day.date}
                                    className="absolute top-0 whitespace-nowrap text-center"
                                    style={{ left: labelLeft(index, data.length, labelsWidth), width: LABEL_WIDTH }}
                                    dir="rtl"
                                >
                                    {formatShortDay(day.date)}
                                </span>
                            );
                        })}
                    </div>
                </div>
            </div>

            <table className="sr-only">
                <caption>{label}</caption>
                <tbody>
                    {data.map((day) => (
                        <tr key={day.date}>
                            <th scope="row">{formatShortDay(day.date)}</th>
                            <td>{formatValue(day.value)}</td>
                            {countLabel && <td>{`${formatNumber(day.count ?? 0)} ${countLabel}`}</td>}
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
