import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatNumber } from '@/lib/format';

/**
 * Whose figures the page shows, large at its top: one branch's name, with
 * its place, phone and subscribers, a governorate's with how many branches
 * it has, or "جميع الفروع" with how many — so whoever sees more than one
 * branch never mistakes one branch's money for another's. `scope` is what App\Support\BranchScope::describe() sends.
 * With more than one `options` ({ value, label }), the branch is changed
 * here (`allValue` is the choice for every branch); a page with its own
 * pickers passes them as `controls` instead; `meta` adds a fact to
 * the line under the name (the period, say) and `children` a row under it
 * (a chip per branch when all are shown). On paper it prints as the
 * page's heading, without the colours and the controls. `className`
 * replaces its space below, for a page that spaces its parts itself.
 */
/** "فرع واحد", "فرعان", "5 فروع", "12 فرعاً": a count of branches as Arabic says it. */
function branchCount(count) {
    if (count === 1) {
        return 'فرع واحد';
    }

    if (count === 2) {
        return 'فرعان';
    }

    return `${formatNumber(count)} ${count % 100 >= 3 && count % 100 <= 10 ? 'فروع' : 'فرعاً'}`;
}

export default function BranchBanner({ scope, eyebrow = 'تعرض الآن البيانات المالية لـ', options = [], value = '', allValue = 'all', onChange = null, controls = null, meta = null, children = null, className = 'mb-5' }) {
    const facts = [
        scope.place && { icon: 'pin', text: scope.place },
        scope.all && { icon: 'building', text: branchCount(scope.branches) },
        { icon: 'users', text: `${formatNumber(scope.subscribers)} مشترك` },
        scope.phone && { icon: 'phone', text: <bdi>{scope.phone}</bdi> },
        meta && { icon: 'calendar', text: meta },
    ].filter(Boolean);

    return (
        <section
            aria-label="الفرع المعروض"
            className={`relative overflow-hidden rounded-card bg-graphite-gradient p-5 text-white shadow-card sm:p-6 print:mb-4 print:overflow-visible print:rounded-none print:border-b-2 print:border-[#a51d26] print:bg-none print:p-0 print:pb-3 print:text-[#111820] print:shadow-none ${className}`}
        >
            <span aria-hidden="true" className="absolute inset-x-0 top-0 h-[3px] bg-spectrum print:hidden" />
            <div className="flex flex-wrap items-center gap-x-5 gap-y-4">
                <span aria-hidden="true" className="hidden h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15 sm:flex print:hidden">
                    <Icon name={scope.level === 'governorate' ? 'map' : scope.all ? 'layers' : 'building'} className="h-7 w-7" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm font-semibold text-white/70 print:text-[#4c5460]">
                        <span>{eyebrow}</span>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-white print:hidden">
                            <span aria-hidden="true" className={`h-2 w-2 rounded-full ${scope.level === 'governorate' ? 'bg-sky-400' : scope.all ? 'bg-amber-400' : scope.isActive ? 'bg-emerald-400' : 'bg-gray-400'}`} />
                            {scope.level === 'governorate' ? `محافظة · ${branchCount(scope.branches)}` : scope.all ? `تقرير مجمّع · ${branchCount(scope.branches)}` : scope.isActive ? 'فرع واحد' : 'فرع واحد · متوقف'}
                        </span>
                    </p>
                    <h2 className="mt-1 text-balance font-display text-3xl font-bold leading-tight sm:text-4xl" dir="auto">
                        {scope.name}
                    </h2>
                    <ul className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-white/70 print:text-[#4c5460]">
                        {facts.map((fact) => (
                            <li key={fact.icon} className="inline-flex items-center gap-1.5">
                                <Icon name={fact.icon} className="h-4 w-4 shrink-0 print:hidden" />
                                <span className="font-display tabular-nums">{fact.text}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                {controls && <div className="flex w-full flex-wrap gap-3 sm:w-auto print:hidden">{controls}</div>}
                {onChange && options.length > 1 && (
                    <label className="flex w-full flex-col gap-1.5 sm:w-64 print:hidden">
                        <span className="text-xs font-semibold text-white/70">تغيير الفرع</span>
                        <span className="relative">
                            <select
                                value={value ?? ''}
                                onChange={(event) => onChange(event.target.value)}
                                className="h-12 w-full appearance-none rounded-control border border-white/15 bg-white/10 bg-none pe-10 ps-3.5 font-bold text-white [color-scheme:dark] focus:border-white/40 focus:ring-2 focus:ring-white/30"
                            >
                                <option value={allValue}>جميع الفروع (تقرير مجمّع)</option>
                                {options.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <Icon name="chevron-down" className="pointer-events-none absolute end-3.5 top-1/2 h-4 w-4 -translate-y-1/2" />
                        </span>
                    </label>
                )}
            </div>
            {children && <div className="mt-4 flex flex-wrap gap-2 border-t border-white/15 pt-4 print:hidden">{children}</div>}
        </section>
    );
}

/**
 * A list page's table filters, split for the banner: the `branch_id`
 * group (when the page has a banner) goes to the banner's picker, the
 * rest stay in the table's filter menu, which neither counts the branch
 * nor clears it.
 */
export function splitBranchFilter(scope, groups, values) {
    const branch = scope ? (groups ?? []).find((group) => group.key === 'branch_id') ?? null : null;

    return {
        branch,
        groups: branch ? groups.filter((group) => group !== branch) : groups,
        values: branch ? Object.fromEntries(Object.entries(values).filter(([key]) => key !== 'branch_id')) : values,
        kept: branch ? ['branch_id'] : [],
        /** Clears the menu's filters, keeping the banner's branch. */
        clear: (setFilters, clearFilters) => (branch ? setFilters(Object.fromEntries(Object.keys(values).filter((key) => key !== 'branch_id').map((key) => [key, '']))) : clearFilters()),
    };
}

/**
 * The banner of a list page whose table narrows to one branch: the branch's
 * name, with the branch picked in it. `allowAll={false}` leaves out the
 * choice of every branch, for a page that always shows one.
 */
export function BranchFilterBanner({ scope, eyebrow, group, value, onChange, allowAll = true }) {
    return (
        <BranchBanner
            scope={scope}
            eyebrow={eyebrow}
            controls={
                group && (
                    <label className="flex w-full flex-col gap-1.5 text-xs font-semibold text-white/70 sm:w-64">
                        الفرع
                        <SearchableSelect
                            value={value ?? ''}
                            onChange={onChange}
                            options={group.options}
                            placeholder={allowAll ? scope.allName : scope.name}
                            searchPlaceholder="ابحث عن فرع..."
                            active={allowAll && Boolean(value)}
                            clearable={allowAll}
                            portal
                            onDark
                        />
                    </label>
                )
            }
        />
    );
}

/**
 * The banner's row when every branch is shown: the branches with the
 * largest figure first, a few of them, then a button for the rest, so a
 * company of many branches keeps a short banner. Each item is
 * { key, href, name, figure, amount }, `amount` ordering them.
 */
export function BranchChips({ items, limit = 6 }) {
    const [showingAll, setShowingAll] = useState(false);
    const ordered = [...items].sort((first, second) => second.amount - first.amount);
    const shown = showingAll ? ordered : ordered.slice(0, limit);

    return (
        <>
            {shown.map((item) => (
                <BranchChip key={item.key} as={Link} href={item.href} name={item.name} figure={item.figure} />
            ))}
            {ordered.length > limit && (
                <BranchChip onClick={() => setShowingAll(!showingAll)} aria-expanded={showingAll} name={showingAll ? 'عرض أقل' : `+ ${formatNumber(ordered.length - limit)} فروع أخرى`} />
            )}
        </>
    );
}

/** A branch's button in the banner's row when every branch is shown, with a figure of its own (its collection, say). */
export function BranchChip({ href, onClick, name, figure = null, as: Element = 'button', ...props }) {
    return (
        <Element
            {...(Element === 'button' ? { type: 'button', onClick } : { href })}
            {...props}
            className="inline-flex h-9 items-center gap-2 rounded-control border border-white/15 bg-white/[0.06] px-3 text-sm font-semibold text-white transition hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white/60"
        >
            {name}
            {figure && <small className="font-display text-xs text-white/70 tabular-nums">{figure}</small>}
        </Element>
    );
}
