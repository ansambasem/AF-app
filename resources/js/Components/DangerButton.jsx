/**
 * Destructive actions: a soft burgundy button that fills in on hover.
 */
export default function DangerButton({ className = '', disabled, children, ...props }) {
    return (
        <button
            {...props}
            disabled={disabled}
            className={
                'inline-flex items-center justify-center gap-2 rounded-control border border-brand-500/40 bg-brand-500/10 px-4 py-2.5 text-sm font-semibold text-brand-600 transition ' +
                'hover:bg-brand-500 hover:text-white active:translate-y-px focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ' +
                'disabled:cursor-not-allowed disabled:opacity-40 ' +
                className
            }
        >
            {children}
        </button>
    );
}
