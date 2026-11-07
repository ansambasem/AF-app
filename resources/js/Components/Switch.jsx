import InfoTip from '@/Components/InfoTip';

/**
 * An on/off switch with a visible label. It turns green when on (as an
 * "active" status does everywhere; burgundy stays for actions and errors)
 * and its knob slides toward the end (left in Arabic). `ariaLabel` gives
 * screen readers the full name when the visible label is short, e.g.
 * "المشتركون: عرض" for a switch labelled "عرض". `hint` is what turning it
 * off or on means, kept behind an "!" beside the label.
 */
export default function Switch({ checked, onChange, label, ariaLabel, hint = null, disabled = false }) {
    const control = (
        <label
            className={`inline-flex select-none items-center gap-2.5 text-sm font-semibold text-gray-700 ${disabled ? 'opacity-50' : 'cursor-pointer'}`}
        >
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={ariaLabel}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors duration-200 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:cursor-not-allowed ${
                    checked ? 'bg-emerald-600' : 'bg-gray-300'
                }`}
            >
                <span
                    aria-hidden="true"
                    className={`h-5 w-5 rounded-full bg-white shadow-sm ring-1 ring-black/5 transition-transform duration-200 ${
                        checked ? 'translate-x-5 rtl:-translate-x-5' : 'translate-x-0'
                    }`}
                />
            </button>
            {label}
        </label>
    );

    return hint ? (
        <span className="inline-flex items-center gap-1">
            {control}
            <InfoTip label={`عن ${ariaLabel ?? label}`}>{hint}</InfoTip>
        </span>
    ) : (
        control
    );
}
