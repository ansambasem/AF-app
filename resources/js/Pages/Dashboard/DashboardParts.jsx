import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import { formatNumber } from '@/lib/format';
import { shouldPrefetchPage } from '@/lib/navigation';

/** "+4.2%" or "−3%" against the period before, on the graphite card; nothing when there was nothing before. */
export function ChangeBadge({ change }) {
    if (change === null || change === undefined) {
        return null;
    }

    const up = change >= 0;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-display text-xs font-bold ${up ? 'bg-emerald-400/15 text-emerald-300' : 'bg-brand-400/20 text-brand-100'}`}
            title="مقارنة بالفترة السابقة"
        >
            <Icon name={up ? 'trend-up' : 'trend-down'} className="h-3.5 w-3.5" />
            <span dir="ltr">
                {up ? '+' : '−'}
                {Math.abs(change)}%
            </span>
        </span>
    );
}

/** A bar split into coloured parts (`{ value, className, label }`), each as wide as its share. */
export function SplitBar({ parts, label, className = 'mt-4' }) {
    const total = parts.reduce((sum, part) => sum + part.value, 0);

    return (
        <div className={`flex h-2 overflow-hidden rounded-full bg-gray-100 ${className}`} role="img" aria-label={label}>
            {total > 0 &&
                parts.map((part) =>
                    part.value > 0 ? (
                        <span
                            key={part.label}
                            title={`${part.label}: ${formatNumber(part.value)}`}
                            className={part.className}
                            style={{ width: `${(part.value / total) * 100}%` }}
                        />
                    ) : null,
                )}
        </div>
    );
}

/** A dashboard card: an icon tile, a title with its guidance behind an "!", an action at the far end, then the body. */
export function Panel({ icon, title, info, action, className = '', children }) {
    return (
        <section className={`rise-in rounded-panel border border-gray-100 bg-surface p-5 shadow-card sm:p-6 ${className}`}>
            <div className="mb-5 flex flex-wrap items-center gap-x-2.5 gap-y-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500">
                    <Icon name={icon} className="h-[18px] w-[18px]" />
                </span>
                <h3 className="font-bold text-gray-900">{title}</h3>
                {info && <InfoTip label={`عن ${title}`}>{info}</InfoTip>}
                {action && <div className="ms-auto">{action}</div>}
            </div>
            {children}
        </section>
    );
}

/** "فتح" at the end of a card's title, to the page the card sums up. */
export function OpenLink({ href }) {
    return (
        <Link href={href} prefetch={shouldPrefetchPage(href)} className="inline-flex items-center gap-1 text-sm font-semibold text-gray-500 transition hover:text-gray-900">
            عرض الكل
            <Icon name="chevron-left" className="h-4 w-4" strokeWidth={2} />
        </Link>
    );
}

/** A figure tile that opens the page it sums up. */
export function TileLink({ href, children }) {
    return (
        <Link
            href={href}
            prefetch={shouldPrefetchPage(href)}
            className="block rounded-panel transition hover:-translate-y-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 [&>div]:h-full"
        >
            {children}
        </Link>
    );
}

const ATTENTION_TONES = {
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    red: 'bg-brand-500/10 text-brand-600',
    quiet: 'bg-gray-100 text-gray-500',
};

/** Something waiting on someone: its count, what it is, and a link to where it is dealt with. Quiet once nothing waits. */
export function AttentionItem({ icon, title, subtitle, count, href, tone = 'amber' }) {
    const toneClass = ATTENTION_TONES[count > 0 ? tone : 'quiet'];

    return (
        <Link
            href={href}
            prefetch={shouldPrefetchPage(href)}
            className="rise-in flex items-center gap-3 rounded-card border border-gray-100 bg-surface p-4 shadow-card transition hover:-translate-y-0.5 hover:border-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
        >
            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-control ${toneClass}`}>
                <Icon name={icon} className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold text-gray-900">{title}</span>
                <span className="block truncate text-xs text-gray-500">{subtitle}</span>
            </span>
            <span className={`rounded-full px-2.5 py-0.5 font-display text-sm font-bold ${toneClass}`}>{formatNumber(count)}</span>
        </Link>
    );
}

/** A pulsing placeholder while a figure loads after the page. */
export function PendingTile() {
    return (
        <div className="animate-pulse rounded-panel border border-gray-100 bg-surface p-6 shadow-card" aria-hidden="true">
            <div className="flex items-center gap-2.5">
                <span className="h-9 w-9 rounded-xl bg-gray-100" />
                <span className="h-3 w-24 rounded bg-gray-100" />
            </div>
            <div className="mt-5 h-8 w-32 rounded bg-gray-100" />
            <div className="mt-3 h-3 w-40 rounded bg-gray-100" />
        </div>
    );
}

/** A pulsing placeholder card while a list loads after the page. */
export function PendingPanel() {
    return (
        <div className="animate-pulse rounded-panel border border-gray-100 bg-surface p-6 shadow-card" aria-hidden="true">
            <div className="flex items-center gap-2.5">
                <span className="h-9 w-9 rounded-xl bg-gray-100" />
                <span className="h-3 w-32 rounded bg-gray-100" />
            </div>
            {[0, 1, 2, 3, 4].map((row) => (
                <div key={row} className="mt-5 flex items-center justify-between gap-3">
                    <span className="h-3 w-40 rounded bg-gray-100" />
                    <span className="h-3 w-20 rounded bg-gray-100" />
                </div>
            ))}
        </div>
    );
}

export const READING_PARTS = (readings) => [
    { label: 'معتمدة', value: readings.approved, className: 'bg-emerald-500' },
    { label: 'بانتظار الاعتماد', value: readings.pending, className: 'bg-amber-500' },
    { label: 'لم تُقرأ', value: readings.missing, className: 'bg-brand-500' },
];
