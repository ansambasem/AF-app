import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import StatusPill from '@/Components/DataTable/StatusPill';
import { balanceInText } from '@/lib/balance';
import { formatMoney, formatDate } from '@/lib/format';

const EVENT_ICONS = { added: 'plus', extended: 'calendar', warned: 'send', removed: 'undo', disconnected: 'power' };

/** What changed in one step, in a line. */
function eventDetails(event, reasonLabels) {
    const before = event.old ?? {};
    const after = event.new ?? {};

    switch (event.type) {
        case 'added':
            return [`السبب: ${reasonLabels[after.reason] ?? after.reason}`, `الموعد: ${formatDate(after.scheduled_for)}`, `الرصيد عند الإدراج: ${balanceInText(after.balance)}`, after.note && `ملاحظة: ${after.note}`].filter(Boolean).join(' · ');
        case 'extended':
            return `من ${formatDate(before.scheduled_for)} إلى ${formatDate(after.scheduled_for)}`;
        case 'warned':
            return after.channel === 'whatsapp' ? 'عبر واتساب' : 'رسالة SMS';
        case 'disconnected':
            return `رسوم القطع: ${formatMoney(after.fee)} ₪ · الخدمة: مفصول`;
        default:
            return '';
    }
}

/**
 * Everything that happened to one subscriber on the list, oldest first:
 * who did it and when, and what changed. The history is never edited.
 */
export default function HistoryModal({ history, loading, reasonOptions, onClose }) {
    const reasonLabels = Object.fromEntries(reasonOptions.map((option) => [option.value, option.label]));

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <div role="dialog" aria-modal="true" aria-labelledby="history-title" className="flex max-h-[90vh] flex-col">
                <div className="flex items-center justify-between border-b border-gray-100 px-7 py-5">
                    <div>
                        <h3 id="history-title" className="text-lg font-bold text-gray-900">سجل المشترك في القائمة</h3>
                        {history && (
                            <p className="text-sm text-gray-500">
                                {history.name} <span dir="ltr" className="font-display">{history.accountNumber}</span>
                            </p>
                        )}
                    </div>
                    <button type="button" onClick={onClose} aria-label="إغلاق" className="rounded-xl p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-900">
                        <Icon name="close" />
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-7 py-6">
                    {loading || !history ? (
                        <p className="py-8 text-center text-sm text-gray-500">جارٍ التحميل…</p>
                    ) : (
                        <ol className="relative space-y-5 border-s border-gray-200 ps-6">
                            {history.events.map((event) => (
                                <li key={event.id} className="relative">
                                    <span className="absolute -start-[35px] flex h-7 w-7 items-center justify-center rounded-full bg-graphite-gradient text-white">
                                        <Icon name={EVENT_ICONS[event.type] ?? 'history'} className="h-3.5 w-3.5" />
                                    </span>
                                    <p className="font-semibold text-gray-900">{event.typeLabel}</p>
                                    <p className="text-xs text-gray-500">
                                        {event.userName} · <span dir="ltr">{event.at}</span>
                                    </p>
                                    {eventDetails(event, reasonLabels) && <p className="mt-1 text-sm text-gray-700">{eventDetails(event, reasonLabels)}</p>}
                                    {event.reason && <p className="mt-1 text-sm text-gray-600">السبب: {event.reason}</p>}
                                    {event.message && (
                                        <div className="mt-2 rounded-control border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700">
                                            <p className="whitespace-pre-line">{event.message.body}</p>
                                            <p className="mt-2 flex items-center gap-2 text-xs text-gray-500">
                                                <span dir="ltr">{event.message.phone}</span>
                                                <StatusPill tone={event.message.status === 'sent' ? 'green' : event.message.status === 'failed' ? 'red' : 'amber'} label={event.message.statusLabel} />
                                                {event.message.error && <span>{event.message.error}</span>}
                                            </p>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </Modal>
    );
}
