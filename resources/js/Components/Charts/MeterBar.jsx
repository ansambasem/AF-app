/**
 * A thin bar showing `value` against `max` — a branch's total beside the
 * largest one, say. The track is a light step of the same color, so the
 * whole bar reads as one measure. Decorative: the figure is written next
 * to it.
 */
export default function MeterBar({ value, max, className = '' }) {
    const share = max > 0 ? Math.min(100, (value / max) * 100) : 0;

    return (
        <div className={`h-1.5 overflow-hidden rounded-full bg-brand-500/10 ${className}`} aria-hidden="true">
            <div className="h-full rounded-full bg-brand-gradient transition-[width] duration-500" style={{ width: `${share}%` }} />
        </div>
    );
}
