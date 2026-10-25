import { useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatMoney } from '@/lib/format';

/** One line of the transfer's own details. */
function Detail({ label, children, ltr = false }) {
    return (
        <div className="min-w-0 text-start">
            <dt className="text-[13px] font-semibold text-gray-500">{label}</dt>
            <dd className="mt-0.5 break-words text-[15px] font-semibold text-gray-900">
                {ltr ? <bdi dir="ltr">{children || '—'}</bdi> : children || '—'}
            </dd>
        </div>
    );
}

/**
 * A split bank transfer, in one place: what was transferred and by whom, and
 * the payments on subscriptions it became, with whether together they still
 * make up the transfer (a cancelled or refunded part leaves it incomplete).
 */
export default function SplitPaymentDetails({ id, onClose }) {
    const http = useHttp();
    const [split, setSplit] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let current = true;

        http.get(`/split-payments/${id}`)
            .then((loaded) => current && setSplit(loaded))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <div role="dialog" aria-modal="true" aria-label="تفاصيل الدفعة المقسّمة" dir="rtl" className="flex max-h-[90vh] flex-col text-start">
                <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-5 sm:px-7">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-gradient text-white shadow-glow">
                            <Icon name="layers" />
                        </span>
                        <div className="min-w-0">
                            <h3 className="text-lg font-bold text-gray-900">دفعة مقسّمة على عدة مشتركين</h3>
                            {split && <p className="text-[13px] text-gray-500">تحويل بنكي واحد وُزّع على {split.partsCount} اشتراكات</p>}
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="إغلاق" className="shrink-0 rounded-xl p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-900">
                        <Icon name="close" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-7">
                    {failed && <p className="py-8 text-center text-sm text-brand-600">تعذّر تحميل تفاصيل الدفعة.</p>}
                    {!split && !failed && <p className="py-8 text-center text-sm text-gray-500">جارٍ التحميل…</p>}

                    {split && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-[18px] border border-gray-100 bg-gray-50 px-5 py-4">
                                <div>
                                    <p className="text-[13px] font-semibold text-gray-500">المبلغ المحوَّل</p>
                                    <p className="font-display text-3xl font-bold text-gray-900"><bdi dir="ltr">{formatMoney(split.total)} ₪</bdi></p>
                                </div>
                                {split.isComplete ? (
                                    <p className="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3.5 py-1.5 text-sm font-bold text-emerald-700 dark:text-emerald-400">
                                        <Icon name="check" className="h-4 w-4" />
                                        <span>الأجزاء كاملة = <bdi dir="ltr" className="whitespace-nowrap">{formatMoney(split.standingTotal)} ₪</bdi></span>
                                    </p>
                                ) : (
                                    <p className="flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3.5 py-1.5 text-sm font-bold text-amber-800 dark:text-amber-300">
                                        <Icon name="warning" className="h-4 w-4" />
                                        <span>ناقصة: القائم <bdi dir="ltr">{formatMoney(split.standingTotal)}</bdi> من <bdi dir="ltr" className="whitespace-nowrap">{formatMoney(split.total)} ₪</bdi></span>
                                    </p>
                                )}
                            </div>

                            <dl className="grid gap-4 sm:grid-cols-2">
                                <Detail label="البنك المستلم">{split.bankName}</Detail>
                                <Detail label="اسم المحوِّل">{split.senderName}</Detail>
                                <Detail label="الرقم المرجعي" ltr>
                                    {split.referenceNumber}
                                </Detail>
                                <Detail label="سجّلها">
                                    <span className="block">{split.recordedByName || '—'}</span>
                                    <span className="mt-1 block text-sm font-normal text-gray-600">
                                        <bdi dir="ltr" className="font-display">{split.date} {split.time}</bdi>
                                    </span>
                                </Detail>
                                <Detail label="تاريخ وصول الحوالة إلى البنك" ltr>{split.bankReceivedOn?.split('-').reverse().join('/')}</Detail>
                            </dl>

                            <div>
                                <p className="mb-2 text-[14.5px] font-semibold text-gray-700">الأجزاء</p>
                                <ul className="divide-y divide-gray-100 overflow-hidden rounded-[18px] border border-gray-100">
                                    {split.parts.map((part, index) => (
                                        <li key={part.id} className={`grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_7rem_4.75rem] ${part.isCancelled ? 'bg-gray-50 text-gray-400' : 'bg-surface'}`}>
                                            <div className="flex min-w-0 items-start gap-3">
                                                <span className="w-5 shrink-0 pt-0.5 font-display text-xs text-gray-400">{index + 1}</span>
                                                <div className="min-w-0">
                                                    <b className={`block break-words ${part.isCancelled ? 'line-through' : 'text-gray-900'}`}>{part.subscriptionName}</b>
                                                    <bdi dir="ltr" className="mt-1 block text-end font-display text-xs text-gray-500">{part.accountNumber}</bdi>
                                                    {part.isCancelled && <span className="mt-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-600">{part.cancellationLabel ?? 'ملغاة'}</span>}
                                                </div>
                                            </div>
                                            <bdi dir="ltr" className={`justify-self-end whitespace-nowrap font-display text-base font-bold ${part.isCancelled ? 'line-through' : 'text-gray-900'}`}>{formatMoney(part.amount)} ₪</bdi>
                                            {part.receiptUrl ? (
                                                <a
                                                    href={part.receiptUrl}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="tone-print col-start-2 inline-flex items-center gap-1.5 justify-self-end rounded-control border px-2.5 py-1 text-xs font-semibold transition sm:col-start-auto"
                                                >
                                                    <Icon name="printer" className="h-3.5 w-3.5" />
                                                    السند
                                                </a>
                                            ) : <span className="hidden sm:block" aria-hidden="true" />}
                                        </li>
                                    ))}
                                </ul>
                                {split.hiddenPartsCount > 0 && (
                                    <p className="mt-2 text-[13px] text-gray-500">
                                        و{split.hiddenPartsCount} {split.hiddenPartsCount === 1 ? 'جزء آخر' : 'أجزاء أخرى'} على اشتراكات فروع أخرى بقيمة {formatMoney(split.hiddenPartsAmount)} ₪.
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </div>

                <div className="flex items-center justify-end border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose}>إغلاق</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
