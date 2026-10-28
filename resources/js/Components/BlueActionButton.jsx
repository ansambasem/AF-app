export const blueActionSurfaceClass = 'inline-flex rounded-control bg-[linear-gradient(135deg,#3b74e6,#1f4fb8)] text-sm font-semibold text-white shadow-[0_10px_22px_-10px_rgb(47_102_214/0.85)] ring-1 ring-inset ring-white/15';
export const blueActionControlClass = 'inline-flex items-center gap-2 px-4 py-2.5 transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:opacity-50';

/** The blue action style shared with the subscribers' bulk-actions control. */
export default function BlueActionButton({ children, className = '', type = 'button', ...props }) {
    return <button type={type} {...props} className={`${blueActionSurfaceClass} ${blueActionControlClass} justify-center ${className}`}>{children}</button>;
}
