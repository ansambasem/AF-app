import { useId } from 'react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';

/**
 * The tinted badge behind a dialog's icon. One set for every dialog and
 * form: brand for adding and confirming, blue for editing, amber for a
 * warning, red for what cannot be undone.
 */
export const DIALOG_TONES = {
    primary: 'bg-brand-500/10 text-brand-600',
    brand: 'bg-brand-500/10 text-brand-600',
    blue: 'bg-blue-500/10 text-blue-600',
    warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    danger: 'bg-red-500/10 text-red-700 dark:text-red-300',
};

/** A dialog's top: the icon in its tone, the title (and a line under it), and the close button. */
export function DialogHeader({ titleId, title, description = null, descriptionId, icon, tone = 'primary', onClose, closeDisabled = false }) {
    return (
        <header className="flex shrink-0 items-start gap-3 border-b border-gray-100 px-5 py-5 sm:gap-4 sm:px-7">
            <span className={`hidden h-11 w-11 shrink-0 items-center justify-center rounded-control sm:flex ${DIALOG_TONES[tone] ?? DIALOG_TONES.primary}`}><Icon name={icon} className="h-[22px] w-[22px]" /></span>
            <div className="min-w-0 flex-1 self-center">
                <h2 id={titleId} className="break-words font-sans text-xl font-bold leading-snug text-gray-900">{title}</h2>
                {description && <p id={descriptionId} className="mt-1.5 break-words text-base leading-7 text-gray-600">{description}</p>}
            </div>
            <button type="button" onClick={onClose} disabled={closeDisabled} aria-label="إغلاق النافذة" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-control text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 disabled:opacity-40"><Icon name="close" className="h-5 w-5" /></button>
        </header>
    );
}

/** A readable message layout using the site's modal, typeface, colors and controls. */
export default function DialogFrame({ show, onClose, title, description, icon = 'check', tone = 'primary', maxWidth = '2xl', role = 'dialog', children, footer, busy = false }) {
    const id = useId();

    return <Modal show={show} onClose={busy ? undefined : onClose} maxWidth={maxWidth} centered>
        <div role={role} aria-modal="true" aria-labelledby={`${id}-title`} aria-describedby={description ? `${id}-description` : role === 'alertdialog' ? `${id}-body` : undefined} aria-busy={busy || undefined} dir="rtl" className="flex max-h-[90dvh] min-h-0 flex-col">
            <DialogHeader titleId={`${id}-title`} title={title} description={description} descriptionId={`${id}-description`} icon={icon} tone={tone} onClose={onClose} closeDisabled={busy} />
            <div id={`${id}-body`} className="min-h-0 flex-1 space-y-5 overflow-y-auto break-words px-5 py-6 text-[18px] leading-8 text-gray-700 sm:px-7">{children}</div>
            {footer && <footer className="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:px-7 [&>button]:min-h-12 [&>button]:!text-base">{footer}</footer>}
        </div>
    </Modal>;
}
