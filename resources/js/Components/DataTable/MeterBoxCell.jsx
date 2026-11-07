/** A subscription's meter box in a table: its name with its suffix, then its number. */
export default function MeterBoxCell({ name, number }) {
    if (!name && !number) {
        return '—';
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            {name && <span className="font-semibold text-gray-900">{name}</span>}
            {number && (
                <span className="data-chip" dir="ltr">
                    {number}
                </span>
            )}
        </span>
    );
}
