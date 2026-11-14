import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { primaryButtonClass } from '@/Components/PrimaryButton';

/**
 * A "+ Add …" button, in the main action's look. Renders a link when
 * given `href`, otherwise a button that calls `onClick`.
 */
export default function AddButton({ href, onClick, children }) {
    const content = (
        <>
            <Icon name="plus" className="h-4 w-4" strokeWidth={2} />
            {children}
        </>
    );

    return href ? (
        <Link href={href} prefetch className={primaryButtonClass}>
            {content}
        </Link>
    ) : (
        <button type="button" onClick={onClick} className={primaryButtonClass}>
            {content}
        </button>
    );
}
