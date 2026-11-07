/**
 * A status shown as a word with a dot, never by color alone. Tints are
 * translucent so the pill reads well in both themes.
 */
const TONE_CLASSES = {
    green: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    gray: 'border-gray-200 bg-gray-100 text-gray-500',
    amber: 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    red: 'border-brand-500/25 bg-brand-500/10 text-brand-600',
    blue: 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-400',
};

const DOT_CLASSES = {
    green: 'bg-emerald-500',
    gray: 'bg-gray-400',
    amber: 'bg-amber-500',
    red: 'bg-brand-500',
    blue: 'bg-sky-500',
};

export default function StatusPill({ tone = 'gray', label }) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold ${TONE_CLASSES[tone]}`}
        >
            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${DOT_CLASSES[tone]}`} />
            {label}
        </span>
    );
}
