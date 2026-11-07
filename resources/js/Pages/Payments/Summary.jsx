import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import PrimaryButton from '@/Components/PrimaryButton';
import { useDataTable } from '@/hooks/useDataTable';
import { formatMoney } from '@/lib/format';
import {
    boxTypes,
    BoxCard,
    BoxStage,
    BoxTypeIcon,
    CashBoxAction,
    CollectorAuditNotes,
    CollectorDebts,
    boxAction,
    actionLabel,
} from './CollectorCashBox';
import OpenEntriesTable from './OpenEntriesTable';
import PageHeader from '@/Components/PageHeader';
import InfoTip from '@/Components/InfoTip';

/** What the user collected today, in one wide card at the top of the page: the total, and how much in cash and by transfer. */
function TodaysSummary({ today }) {
    const parts = [
        { icon: 'banknotes', label: 'نقدًا', value: today.cash },
        { icon: 'bank', label: 'تحويلات بنكية', value: today.transfers },
    ];

    return (
        <section aria-label="ما حصّلته اليوم" className="mb-6">
            <KpiTile hero label="ما حصّلته اليوم" value={formatMoney(today.total)} unit="₪" hint={`${today.count.toLocaleString('en')} ${today.count === 1 ? 'دفعة' : 'دفعات'}`}>
                <dl className="mt-5 grid gap-3 border-t border-white/10 pt-4 sm:grid-cols-2">
                    {parts.map((part) => (
                        <div key={part.label} className="flex items-center gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white/80">
                                <Icon name={part.icon} className="h-[18px] w-[18px]" />
                            </span>
                            <dt className="text-sm text-white/70">{part.label}</dt>
                            <dd className="ms-auto flex items-baseline gap-1.5 sm:ms-0">
                                <span className="font-display text-2xl font-bold">{formatMoney(part.value)}</span>
                                <span className="text-xs text-white/60">₪</span>
                            </dd>
                        </div>
                    ))}
                </dl>
            </KpiTile>
        </section>
    );
}

/** The user's latest payments today, each with its receipt. */
function TodaysPayments({ today }) {
    return (
        <section aria-labelledby="todays-payments" className="mt-8 space-y-4">
            <h3 id="todays-payments" className="text-lg font-bold text-gray-900">
                آخر دفعاتك اليوم
            </h3>

            {today.payments.length > 0 ? (
                <ul className="divide-y divide-gray-100 overflow-hidden rounded-panel border border-gray-100 bg-surface shadow-card">
                    {today.payments.map((payment) => (
                        <li key={payment.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 text-sm">
                            <span className="min-w-0 flex-1 basis-48 font-semibold text-gray-900">{payment.subscriptionName}</span>
                            <span className="text-gray-500">
                                {payment.methodLabel}
                                {payment.bankName && ` · ${payment.bankName}`}
                            </span>
                            {payment.voucherNumber && (
                                <span dir="ltr" className="font-display text-xs text-gray-500">
                                    #{payment.voucherNumber}
                                </span>
                            )}
                            <span dir="ltr" className="font-display text-xs text-gray-500">
                                {payment.time}
                            </span>
                            <b className="font-display text-base text-emerald-700 dark:text-emerald-400">{formatMoney(payment.amount)} ₪</b>
                            <a
                                href={payment.receiptUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="tone-print inline-flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-xs font-semibold transition"
                            >
                                <Icon name="printer" className="h-4 w-4" />
                                السند
                            </a>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="rounded-panel border border-dashed border-gray-200 bg-surface px-5 py-8 text-center text-sm text-gray-500">لم تسجّل أي دفعة اليوم بعد.</p>
            )}
        </section>
    );
}

/**
 * The collector's summary: what they collected today, what waits on them
 * (entries the branch sent back, a handover to confirm, audit notes,
 * debts), the boxes open now, every entry in them in one table, searched
 * and narrowed to one type of box (a box's card picks its type), and their
 * latest payments today with receipts.
 */
export default function Summary({ today, cashBox, current, entries, filters, counts, needsAction, pendingCount, collectionUrls }) {
    const [selected, setSelected] = useState(null);
    const [saved, setSaved] = useState(null);
    const table = useDataTable(collectionUrls.summary, filters);
    const shownType = table.filterValues.type ?? '';

    /** Shows only the entries of one type of box in the table below, or every entry again when that type is already shown. */
    function showBoxType(type) {
        if (shownType === type) {
            table.setFilter('type', '');
            return;
        }

        table.setFilter('type', type);
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        document.getElementById('open-entries')?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    }

    return <AuthenticatedLayout badges={{ '/payments/summary': pendingCount }} header={<PageHeader eyebrow={cashBox.branchName ?? 'حسابك الحالي'} title="ملخص التحصيل" />}>
        <Head title="ملخص التحصيل" />
        {saved && <p role="status" className="mb-4 rounded-control bg-emerald-500/10 p-4 text-sm text-emerald-700">{saved}</p>}
        <TodaysSummary today={today} />
        {cashBox.started ? <div className="space-y-8">
            {needsAction.length > 0 && <section className="space-y-3 rounded-panel border border-amber-500/25 bg-amber-500/5 p-5">
                <h3 className="font-bold text-gray-900">تحتاج إجراء منك</h3>
                {needsAction.map((box) => <div key={box.id} className="flex flex-wrap items-center justify-between gap-3 border-t border-amber-500/15 pt-3"><div className="flex items-center gap-3"><BoxTypeIcon type={box.type} className="h-10 w-10" /><div className="space-y-2"><Link href={box.urls.show} prefetch className="font-semibold text-gray-900">{boxTypes[box.type]} · <bdi dir="ltr" className="font-display text-xs">{box.number}</bdi></Link><BoxStage box={box} /></div></div><PrimaryButton onClick={() => setSelected({ box, action: boxAction(box) })}>{actionLabel(box)}</PrimaryButton></div>)}
            </section>}
            <CollectorAuditNotes notes={cashBox.auditNotes} onSaved={() => setSaved('أُرسل ردّك إلى الفرع.')} />
            <CollectorDebts debts={cashBox.debts} />

            <section aria-labelledby="open-boxes" className="space-y-4">
                <div className="flex items-center gap-1">
                    <h3 id="open-boxes" className="text-lg font-bold text-gray-900">صناديقي المفتوحة</h3>
                    <InfoTip label="عن صناديقي المفتوحة">لكل نوع صندوق خاص به؛ يراجعه الفرع ويؤكد إغلاقه، أو يُغلق عند القطع الأسبوعي.</InfoTip>
                </div>
                <div className="grid gap-4 lg:grid-cols-3">
                    {Object.keys(boxTypes).map((type) => <BoxCard key={type} type={type} box={current[type]} selected={shownType === type && Boolean(current[type])} onSelect={() => showBoxType(type)} />)}
                </div>
            </section>

            <section id="open-entries" aria-labelledby="open-entries-title" className="scroll-mt-24 space-y-4">
                <div className="flex items-center gap-1">
                    <h3 id="open-entries-title" className="text-lg font-bold text-gray-900">حركاتي المفتوحة</h3>
                    <InfoTip label="عن حركاتي المفتوحة">كل ما سجّلته في صناديقك المفتوحة؛ حركات الصناديق المغلقة تجدها داخل كل صندوق في صفحة الصناديق المالية.</InfoTip>
                </div>
                <OpenEntriesTable table={table} entries={entries} filters={filters} counts={counts} url={collectionUrls.summary} label="حركاتي المفتوحة" />
            </section>
        </div> : <p className="rounded-panel border border-dashed border-gray-200 bg-surface p-6 text-sm leading-7 text-gray-500">{cashBox.branchName ? 'تُفتح الصناديق تلقائيًا عند تسجيل أول دفعة أو مقاصة في فرعك.' : 'حسابك غير مرتبط بفرع، لذلك لا يوجد سجل صناديق شخصي.'}</p>}
        <TodaysPayments today={today} />
        {selected && <CashBoxAction key={`${selected.box.id}-${selected.action}`} {...selected} onClose={() => setSelected(null)} onSuccess={() => setSaved('تم تأكيد التسليم.')} />}
    </AuthenticatedLayout>;
}
