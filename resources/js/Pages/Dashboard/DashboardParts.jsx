import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { formatNumber } from '@/lib/format';
import { shouldPrefetchPage } from '@/lib/navigation';

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
