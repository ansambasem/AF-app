/**
 * A small trend line with a faint wash under it, no axes — e.g. a branch's
 * entries per day over two weeks, oldest on the left. Decorative: the card
 * it sits in says what it shows in words (`label` names it for screen
 * readers).
 */
export default function Sparkline({ values, label, className = 'h-9 w-28' }) {
    if (values.length < 2) {
        return null;
    }

    const max = Math.max(1, ...values);
    const x = (index) => (index / (values.length - 1)) * 100;
    const y = (value) => 28 - (value / max) * 25;
    const line = values.map((value, index) => `${x(index)},${y(value)}`).join(' ');

    return (
        <svg viewBox="0 0 100 30" preserveAspectRatio="none" className={`text-brand-500 ${className}`} role="img" aria-label={label}>
            <polygon points={`0,30 ${line} 100,30`} className="fill-brand-500/10" />
            <polyline
                points={line}
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
