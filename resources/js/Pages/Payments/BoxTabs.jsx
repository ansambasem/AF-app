import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';

/** Each tab's icon, so a place is known at a glance. */
const TAB_ICONS = { open: 'unlock', closed: 'lock', sent: 'send', entries: 'list' };

/**
 * The financial boxes page's tabs beside its title, in the order work goes
 * through them: the entries of the branch's open boxes, its open boxes, its
 * closed boxes not sent yet, and those sent to audit, each with how many
 * there are. The tab shown stands out as the top bar's current section
 * does (dark, with a red line under it), so whoever closes and sends the
 * boxes always sees where they are; closed boxes waiting to be sent are
 * counted in red. The others are white cards, clear against the bar
 * behind them, that lift on hover. `tabs` come from the server with their
 * links; `current` is the key of the one shown.
 */
export default function BoxTabs({ tabs, current }) {
    return (
        <nav aria-label="الصناديق المالية" className="inline-flex max-w-full flex-wrap gap-1 rounded-[16px] border border-gray-100 bg-gray-100 p-1">
            {tabs.map((tab) => {
                const active = tab.key === current;
                const waiting = tab.key === 'closed' && tab.count > 0;

                return (
                    <Link
                        key={tab.key}
                        href={tab.url}
                        prefetch
                        preserveScroll
                        aria-current={active ? 'page' : undefined}
                        className={`group relative inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-[14px] font-semibold transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 motion-reduce:transition-none ${
                            active
                                ? 'bg-graphite-gradient text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_12px_24px_-12px_rgb(16_24_40/0.7)] dark:bg-[linear-gradient(135deg,#3a434e,#2a3039_60%,#20252c)] dark:ring-1 dark:ring-white/10'
                                : 'bg-surface text-gray-800 shadow-sm ring-1 ring-gray-200/80 hover:-translate-y-px hover:text-gray-900 hover:shadow-card hover:ring-gray-300 motion-reduce:hover:translate-y-0'
                        }`}
                    >
                        <Icon name={TAB_ICONS[tab.key] ?? 'wallet'} className={`h-4 w-4 transition-colors ${active ? 'text-white' : 'text-gray-600 group-hover:text-gray-900'}`} />
                        {tab.label}
                        <span
                            className={`min-w-6 rounded-full px-1.5 text-center font-display text-xs leading-5 ${
                                active ? 'bg-white/15 text-white' : waiting ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700'
                            }`}
                        >
                            {tab.count.toLocaleString('en')}
                        </span>
                        {active && <span aria-hidden="true" className="absolute inset-x-4 -bottom-1 h-[3px] rounded-full bg-brand-500 shadow-[0_0_12px_1px_rgb(var(--brand-500)/0.75)]" />}
                    </Link>
                );
            })}
        </nav>
    );
}
