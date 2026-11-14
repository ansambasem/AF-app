/**
 * The dark card at the top of a form: the record as it is being typed in
 * (an avatar, its name, a line under it and a few chips), and how many of
 * the required fields are filled. `avatar` is the text or icon in the
 * badge, `dotClass` colours a status dot on it, and `subtitleDir="ltr"`
 * keeps a number (a phone, say) in its own direction.
 */
export default function FormPreview({ avatar, dotClass = null, title, subtitle = null, subtitleDir = undefined, chips = [], filled, total }) {
    return (
        <div className="relative overflow-hidden rounded-card bg-graphite-gradient p-5 text-white shadow-lift sm:p-6">
            <div className="pointer-events-none absolute -end-12 -top-20 h-56 w-56 rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true" />
            <div className="relative flex flex-wrap items-center justify-between gap-5">
                <div className="flex min-w-0 items-center gap-4">
                    <span className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-[18px] bg-white/10 font-display text-lg font-bold ring-1 ring-white/15">
                        {avatar}
                        {dotClass && (
                            <span className={`absolute -bottom-0.5 -start-0.5 h-3.5 w-3.5 rounded-full border-[3px] border-graphite-800 ${dotClass}`} aria-hidden="true" />
                        )}
                    </span>
                    <div className="min-w-0">
                        <p dir="auto" className="truncate text-right text-xl font-bold">{title}</p>
                        {subtitle && (
                            <p className="mt-0.5 font-display text-sm text-white/60" dir={subtitleDir} style={subtitleDir === 'ltr' ? { textAlign: 'right' } : undefined}>
                                {subtitle}
                            </p>
                        )}
                        {chips.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1.5 text-xs font-semibold">
                                {chips.map((chip) => (
                                    <span key={chip} className="rounded-full bg-white/10 px-2.5 py-0.5">
                                        {chip}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {total > 0 && (
                    <div className="w-44 shrink-0" aria-live="polite">
                        <div className="flex items-baseline justify-between gap-2 text-xs text-white/60">
                            <span>الحقول المطلوبة</span>
                            <b className="font-display text-base text-white" dir="ltr">
                                {filled}/{total}
                            </b>
                        </div>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div className="h-full rounded-full bg-emerald-400 transition-[width] duration-300" style={{ width: `${(filled / total) * 100}%` }} />
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

/** How many of a form's required fields have a value. */
export function countFilled(data, requiredFields) {
    return requiredFields.filter((field) => String(data[field] ?? '').trim() !== '').length;
}
