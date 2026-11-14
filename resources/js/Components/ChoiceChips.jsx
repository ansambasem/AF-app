import { useRef } from 'react';
import Icon from '@/Components/Icon';

const DOT_CLASSES = {
    green: 'bg-emerald-500',
    amber: 'bg-amber-500',
    gray: 'bg-gray-400',
};

/**
 * A few choices shown as pill buttons, picked with one click instead of a
 * drop-down (a radio group). Each option is `{ value, label, hint?, dot?, icon? }`:
 * `hint` is a quiet second part beside the label (a price, say), `dot` a
 * status color, `icon` an icon before the label, `disabled` a choice that
 * cannot be picked (with `title` saying why). The arrow keys move the
 * choice, like native radio buttons.
 *
 * `required` adds a hidden radio named `name`, so the form's own check
 * (validateFormFields) reports a missing choice under the field.
 */
export default function ChoiceChips({ id, name, value, onChange, options, label, required = false, disabled = false }) {
    const groupRef = useRef(null);
    const selectedIndex = options.findIndex((option) => String(option.value) === String(value));

    function choose(index) {
        onChange(String(options[index].value));
        groupRef.current?.querySelectorAll('[role="radio"]')[index]?.focus();
    }

    function onKeyDown(event, index) {
        // Right to left: the next choice is to the left.
        const step = { ArrowLeft: 1, ArrowDown: 1, ArrowRight: -1, ArrowUp: -1 }[event.key];

        if (step) {
            event.preventDefault();
            choose((index + step + options.length) % options.length);
        }
    }

    return (
        <div ref={groupRef} id={id} role="radiogroup" aria-label={label} aria-required={required || undefined} className="flex flex-wrap gap-2">
            {options.map((option, index) => {
                const checked = index === selectedIndex;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={checked}
                        disabled={disabled || option.disabled}
                        title={option.title}
                        tabIndex={checked || (selectedIndex === -1 && index === 0) ? 0 : -1}
                        onClick={() => onChange(String(option.value))}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={`inline-flex items-center gap-2 rounded-control border px-3.5 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:cursor-not-allowed disabled:opacity-50 ${
                            checked
                                ? 'border-gray-900 bg-gray-900 text-surface shadow-sm'
                                : 'border-gray-200 bg-surface text-gray-700 hover:border-gray-300 hover:text-gray-900'
                        }`}
                    >
                        {option.icon && <Icon name={option.icon} className="h-4 w-4 shrink-0" />}
                        {option.dot && <span className={`h-2 w-2 shrink-0 rounded-full ${DOT_CLASSES[option.dot]}`} aria-hidden="true" />}
                        {option.label}
                        {option.hint && (
                            <span className={`font-display text-xs font-medium ${checked ? 'text-surface/70' : 'text-gray-500'}`}>{option.hint}</span>
                        )}
                    </button>
                );
            })}

            {required && (
                <input
                    type="radio"
                    name={name}
                    required
                    checked={selectedIndex !== -1}
                    onChange={() => {}}
                    tabIndex={-1}
                    aria-hidden="true"
                    className="sr-only"
                    // The form focuses the first missing field; send it on to the choices.
                    onFocus={() => groupRef.current?.querySelector('[role="radio"]')?.focus()}
                />
            )}
        </div>
    );
}
