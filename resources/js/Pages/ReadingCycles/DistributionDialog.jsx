import { useId } from 'react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';

export default function DistributionDialog({ title, subtitle, busy, onClose, children, footer }) {
    const titleId = useId();
    return (
        <Modal show centered maxWidth="4xl" panelClassName="!my-auto sm:!max-w-[880px]" onClose={() => { if (!busy) onClose(); }}>
            <section role="dialog" aria-modal="true" aria-labelledby={titleId} aria-busy={busy} dir="rtl" className="flex max-h-[calc(100dvh-4rem)] flex-col">
                <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 p-5 sm:px-7">
                    <div><h2 id={titleId} className="text-lg font-bold text-gray-900">{title}</h2><p className="mt-1 text-xs leading-6 text-gray-500">{subtitle}</p></div>
                    <button type="button" onClick={onClose} disabled={busy} aria-label="إغلاق النافذة" className="rounded-control p-2 text-gray-500 hover:bg-gray-50 disabled:opacity-40"><Icon name="close" className="h-5 w-5" /></button>
                </header>
                <div className="min-h-0 space-y-5 overflow-y-auto overscroll-contain p-5 sm:p-7">{children}</div>
                <footer className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 p-5 sm:px-7">{footer}</footer>
            </section>
        </Modal>
    );
}
