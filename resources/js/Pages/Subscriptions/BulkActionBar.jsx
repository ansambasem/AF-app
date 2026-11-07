import Icon from '@/Components/Icon';
import { printUrl } from '@/lib/print';
import { openQuickMessage } from '@/lib/quickMessage';

/** The most ticked subscriptions a printout carries in its address. */
export const MAX_LINKED_IDS = 400;

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';

function BarButton({ icon, label, onClick, disabled = false, hint, className = '' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={disabled ? hint : label}
            aria-describedby={undefined}
            className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-control bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40 ${FOCUS_RING} ${className}`}
        >
            <Icon name={icon} className="h-4 w-4" />
            {label}
        </button>
    );
}

/**
 * The bar shown while subscriptions are chosen: how many, the actions —
 * change the minimum charge or the status (kept in the bulk changes log),
 * write them a message in the quick message window, print them — and
 * clearing the choice. Actions the user may not take don't show.
 */
export default function BulkActionBar({ count, selection, abilities, onAction, onClear, pendingAction = null, onContinue }) {
    const ids = selection.ids ?? [];
    const tooManyToLink = !selection.all && ids.length > MAX_LINKED_IDS;
    const linkHint = `اختر ${MAX_LINKED_IDS} مشتركًا أو أقل، أو «كل النتائج المطابقة»`;

    function print() {
        const url = printUrl(document.title);
        window.open(selection.all ? url : `${url}&filter%5Bids%5D=${ids.join(',')}`, '_blank');
    }

    return (
        <div
            role="region"
            aria-label="إجراءات على المشتركين المحددين"
            className="fixed inset-x-0 bottom-4 z-40 mx-auto flex w-[calc(100%-2rem)] max-w-4xl flex-wrap items-center gap-2 rounded-2xl bg-graphite-gradient px-4 py-3 text-white shadow-lift ring-1 ring-white/10"
        >
            <span className="me-2 flex items-center gap-2 text-sm" aria-live="polite">
                <span className="flex h-7 min-w-[1.75rem] items-center justify-center rounded-full bg-white px-2 font-display text-sm font-bold text-gray-900">
                    {count.toLocaleString('en')}
                </span>
                {selection.all ? 'اشتراك (كل النتائج المطابقة)' : 'اشتراك محدد'}
            </span>
            <div className="flex flex-1 flex-wrap items-center gap-2">
                {pendingAction ? (
                    <>
                        <span className="flex-1 text-sm">{pendingAction.label}{count === 0 && ' · حدّد الاشتراكات من القائمة'}</span>
                        <BarButton icon="check" label="متابعة" onClick={onContinue} disabled={count === 0 || count > 5000} hint="حدّد من 1 إلى 5,000 اشتراك للمتابعة." />
                    </>
                ) : <>
                {abilities.minimumCharge && <BarButton icon="wallet" label="تعديل الحد الأدنى" onClick={() => onAction('minimum_charge')} disabled={count > 5000} hint="يمكن تعديل 5,000 اشتراك على الأكثر؛ ضيّق الفلاتر." />}
                {abilities.status && <BarButton icon="flag" label="الحالة" onClick={() => onAction('status')} disabled={count > 5000} hint="يمكن تعديل 5,000 اشتراك على الأكثر؛ ضيّق الفلاتر." />}
                {abilities.meterBox && <BarButton icon="grid" label="نقل إلى طبلون آخر" onClick={() => onAction('meter_box_id')} disabled={count > 5000} hint="يمكن نقل 5,000 اشتراك على الأكثر؛ ضيّق الفلاتر." />}
                {abilities.message && (
                    <BarButton
                        icon="messages"
                        label="إرسال رسالة"
                        onClick={() => openQuickMessage(ids, selection.all ? selection : null)}
                        disabled={count > 5000}
                        hint="يمكن مراسلة 5,000 اشتراك على الأكثر؛ ضيّق الفلاتر."
                    />
                )}
                <BarButton icon="printer" label="طباعة" onClick={print} disabled={tooManyToLink} hint={linkHint} className="tone-print tone-on-dark border" />
                </>}
            </div>
            <button
                type="button"
                onClick={onClear}
                aria-label="إلغاء التحديد"
                title="إلغاء التحديد"
                className={`flex h-9 w-9 items-center justify-center rounded-control text-white/80 hover:bg-white/10 hover:text-white ${FOCUS_RING}`}
            >
                <Icon name="close" className="h-5 w-5" />
            </button>
        </div>
    );
}
