/**
 * A row of buttons in one pill, one of them chosen: the period a report
 * covers, the order of the branch cards. `options` are `{ value, label }`;
 * `label` names the group for screen readers.
 */
export default function SegmentedTabs({ options, value, onChange, label, onDark = false }) {
    return (
        <div role="group" aria-label={label} className={`inline-flex gap-0.5 rounded-control border p-[3px] shadow-sm ${onDark ? 'w-max border-white/15 bg-white/5' : 'border-gray-100 bg-surface'}`}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={`${onDark ? 'shrink-0 whitespace-nowrap' : ''} rounded-lg px-3.5 py-1.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${
                        onDark ? (value === option.value ? 'bg-white text-graphite-900 shadow-sm' : 'text-white/70 hover:bg-white/10 hover:text-white') : (value === option.value ? 'bg-gray-900 text-surface shadow-sm' : 'text-gray-500 hover:text-gray-900')
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
