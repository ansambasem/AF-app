import { cloneElement } from 'react';

/**
 * A field with its unit inside it, at the end: ₪ for money, ك.و.س for
 * kilowatt-hours. Wraps one input: <Affix unit="₪"><TextInput … /></Affix>.
 * An `id` given to the Affix (as a form Field gives it) goes to the input.
 */
export default function Affix({ unit, id, children }) {
    return (
        <div className="relative">
            {cloneElement(children, { id: id ?? children.props.id, className: `${children.props.className ?? ''} pe-16` })}
            <span className="pointer-events-none absolute inset-y-1.5 end-1.5 flex items-center rounded-lg bg-gray-100 px-2.5 text-xs font-semibold text-gray-500">
                {unit}
            </span>
        </div>
    );
}
