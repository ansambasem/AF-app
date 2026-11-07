import Icon from '@/Components/Icon';
import { STATEMENT_VIEWS } from '@/lib/accountStatement';

/** Statement views use the same active marker and surfaces as the financial box tabs. */
export default function StatementViewTabs({ value, onChange }) {
    return (
        <div role="group" aria-label="طريقة العرض" className="inline-flex max-w-full flex-wrap gap-1 rounded-[16px] border border-gray-100 bg-gray-100 p-1">
            {STATEMENT_VIEWS.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        title={option.hint}
                        onClick={() => onChange(option.value)}
                        className={`group relative inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-[14px] font-semibold transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 motion-reduce:transition-none ${
                            active
                                ? 'bg-graphite-gradient text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_12px_24px_-12px_rgb(16_24_40/0.7)] dark:bg-[linear-gradient(135deg,#3a434e,#2a3039_60%,#20252c)] dark:ring-1 dark:ring-white/10'
                                : 'bg-surface text-gray-800 shadow-sm ring-1 ring-gray-200/80 hover:-translate-y-px hover:text-gray-900 hover:shadow-card hover:ring-gray-300 motion-reduce:hover:translate-y-0'
                        }`}
                    >
                        <Icon name={option.value === 'compact' ? 'layers' : 'list'} className={`h-4 w-4 transition-colors ${active ? 'text-white' : 'text-gray-600 group-hover:text-gray-900'}`} />
                        {option.label}
                        {active && <span aria-hidden="true" className="absolute inset-x-4 -bottom-1 h-[3px] rounded-full bg-brand-500 shadow-[0_0_12px_1px_rgb(var(--brand-500)/0.75)]" />}
                    </button>
                );
            })}
        </div>
    );
}
