import Icon from '@/Components/Icon';

/**
 * One figure on a card: an icon and a label, the figure itself with its
 * unit (₪…), and a quiet line of context under it. `hero` is the
 * graphite card a page leads with (one per page), with room for a `badge`
 * such as a change against the period before. `children` go under it all
 * (a small breakdown of the figure).
 */
export default function KpiTile({ icon, label, value, unit, hint, badge, hero = false, compact = false, className = '', style, children }) {
    if (hero) {
        return (
            <div
                className={`rise-in relative overflow-hidden rounded-panel bg-graphite-gradient ${compact ? 'border border-white/10 p-5' : 'p-6'} text-white shadow-lift ${className}`}
                style={style}
            >
                <div className="pointer-events-none absolute -end-12 -top-20 h-60 w-60 rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true" />
                {compact ? <div className="relative flex min-h-9 items-center gap-2.5 text-sm font-semibold text-white/80">
                    {icon && <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/10"><Icon name={icon} className="h-[18px] w-[18px]" /></span>}
                    <p>{label}</p>{badge && <span className="ms-auto">{badge}</span>}
                </div> : <div className="relative flex items-start justify-between gap-3">
                    <p className="text-sm font-semibold text-white/75">{label}</p>
                    {badge}
                </div>}
                <p className="relative mt-4 flex flex-wrap items-baseline gap-x-2">
                    <span className={`font-display font-bold tabular-nums ${compact ? 'text-3xl' : 'text-5xl'}`}>{value}</span>
                    {unit && <span className={`${compact ? 'text-sm' : 'text-base'} text-white/70`}>{unit}</span>}
                </p>
                {hint && <p className={`relative text-xs text-white/60 ${compact ? 'mt-2 leading-6' : 'mt-3'}`}>{hint}</p>}
                {children && <div className="relative">{children}</div>}
                <div className="brand-spectrum absolute inset-x-6 bottom-0" aria-hidden="true" />
            </div>
        );
    }

    return (
        <div className={`rise-in rounded-panel border border-gray-100 bg-surface ${compact ? 'p-5' : 'p-6'} shadow-card ${className}`} style={style}>
            <div className="flex items-center gap-2.5 text-sm font-semibold text-gray-700">
                {icon && (
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500">
                        <Icon name={icon} className="h-[18px] w-[18px]" />
                    </span>
                )}
                {label}
            </div>
            <p className={`${compact ? 'mt-4 gap-x-2' : 'mt-5 gap-x-1.5'} flex flex-wrap items-baseline`}>
                <span className="font-display text-3xl font-bold tabular-nums text-gray-900">{value}</span>
                {unit && <span className="text-sm text-gray-500">{unit}</span>}
            </p>
            {hint && <p className={`${compact ? 'mt-2 leading-6' : 'mt-1.5'} text-xs text-gray-500`}>{hint}</p>}
            {children}
        </div>
    );
}
