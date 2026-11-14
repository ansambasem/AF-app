/** The primary look, for a link that acts as the screen's main action. */
export const primaryButtonClass =
    'inline-flex items-center justify-center gap-2 rounded-control bg-brand-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-glow transition ' +
    'hover:brightness-110 active:translate-y-px focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 ' +
    'disabled:cursor-not-allowed disabled:opacity-40';

/**
 * The main action on a screen (one per screen): burgundy gradient with a soft glow.
 */
export default function PrimaryButton({ className = '', disabled, children, ...props }) {
    return (
        <button {...props} disabled={disabled} className={`${primaryButtonClass} ${className}`}>
            {children}
        </button>
    );
}
