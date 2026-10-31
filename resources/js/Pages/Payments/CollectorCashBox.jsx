import { Link, router, useForm } from '@inertiajs/react';
import { excelUrl } from '@/lib/tableExcel';
import Pagination from '@/Components/DataTable/Pagination';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import ColumnWidths, { fixedTableStyle } from '@/Components/DataTable/ColumnWidths';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusPill from '@/Components/DataTable/StatusPill';
import { formatMoney } from '@/lib/format';
import { periodLabel, periodRange } from '@/lib/closingPeriods';
import { boxStatus, entryStatus } from '@/lib/financialBoxes';
import { printUrl } from '@/lib/print';
import { ledgerColumns, ledgerOf, ledgerTableClass, BoxNumberCell, LedgerLeadCells, LedgerTrailCells } from './LedgerColumns';

export const currencies = { ILS: 'شيكل', USD: 'دولار', JOD: 'دينار' };
export const boxTypes = { cash: 'الصندوق النقدي', bank_transfer: 'صندوق الحوالات البنكية', clearing: 'صندوق المقاصات' };
export const dateTime = (value) => value ? new Intl.DateTimeFormat('ar-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Gaza' }).format(new Date(value)) : '—';

/**
 * Each type of box at a glance: its icon, short name and colour (a tinted
 * tile and chip). Written out whole so Tailwind keeps every class.
 */
export const boxTypeStyles = {
    cash: { icon: 'banknotes', label: 'نقدي', tile: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', chip: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
    bank_transfer: { icon: 'bank', label: 'حوالات بنكية', tile: 'bg-sky-500/10 text-sky-600 dark:text-sky-400', chip: 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-400' },
    clearing: { icon: 'scale', label: 'مقاصة', tile: 'bg-violet-500/10 text-violet-600 dark:text-violet-400', chip: 'border-violet-500/25 bg-violet-500/10 text-violet-700 dark:text-violet-400' },
};

/** The box's type as a tinted chip with its icon: نقدي، حوالات بنكية or مقاصة. */
/** A box's amounts by currency, one line each: shekels always, another currency only when it has some. */
export function Amounts({ amounts }) {
    const shown = Object.keys(currencies).filter((currency) => currency === 'ILS' || Number(amounts?.[currency] ?? 0) !== 0);

    return shown.map((currency) => (
        <span key={currency} className="block whitespace-nowrap">
            <b dir="ltr" className="font-display">{formatMoney(amounts?.[currency] ?? 0)}</b> <span className="text-xs text-gray-500">{currencies[currency]}</span>
        </span>
    ));
}

export function BoxTypeChip({ type }) {
    const style = boxTypeStyles[type];

    return (
        <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold ${style.chip}`}>
            <Icon name={style.icon} className="h-3.5 w-3.5" />
            {style.label}
        </span>
    );
}

/** The box type's icon on its tinted tile. */
export function BoxTypeIcon({ type, className = 'h-11 w-11' }) {
    const style = boxTypeStyles[type];

    return (
        <span className={`flex shrink-0 items-center justify-center rounded-[13px] ${style.tile} ${className}`} aria-hidden="true">
            <Icon name={style.icon} className="h-5 w-5" />
        </span>
    );
}

export function BoxStage({ box }) {
    const [label, tone] = boxStatus(box);
    return <div className="flex flex-wrap gap-2"><StatusPill tone={tone} label={label} />{box.rejectedCount > 0 && <StatusPill tone="red" label={`حركات مرفوضة: ${box.rejectedCount}`} />}</div>;
}

export function boxAction(box) {
    return box.stage === 'awaiting_confirmation' ? 'confirm' : null;
}

export function actionLabel() {
    return 'تأكيد التسليم';
}

/** The currencies a box shows: shekels always, a foreign currency only when the box holds or was counted with some. */
function shownCurrencies(box) {
    return Object.keys(currencies).filter(
        (currency) => currency === 'ILS' || (box.type === 'cash' && [box.totals, box.expectedCounts, box.receivedCounts].some((amounts) => Number(amounts?.[currency] ?? 0) !== 0)),
    );
}

export function BoxTotals({ box }) {
    return <div className="flex flex-wrap gap-4 font-display text-sm text-gray-700">{shownCurrencies(box).map((currency) => <span key={currency}>{currencies[currency]}: <b dir="ltr">{formatMoney(box.totals?.[currency] ?? 0)}</b></span>)}</div>;
}

const decisionLabels = { accepted: ['قبله الفرع', 'green'], debt: ['سُجّل دينًا عليك', 'red'] };

/**
 * The branch's count of a received cash box against what its payments say
 * it held, with each difference and what the branch decided about it. Only
 * boxes the branch counted under the old two-step receipt had differences;
 * a box confirmed in one step matches, and shows nothing here.
 */
export function CountSummary({ box }) {
    if (!box.receivedCounts || !Object.values(box.differences ?? {}).some((amount) => Number(amount) !== 0)) return null;

    return (
        <div className="mt-4 overflow-x-auto rounded-control border border-gray-100">
            <table className="w-full text-start text-xs">
                <thead className="bg-gray-50 text-gray-500">
                    <tr>{['العملة', 'المتوقع', 'عدّ الفرع', 'الفرق', 'القرار'].map((label) => <th key={label} className="whitespace-nowrap p-3 text-start">{label}</th>)}</tr>
                </thead>
                <tbody>
                    {shownCurrencies(box).map((currency) => {
                        const difference = Number(box.differences?.[currency] ?? 0);
                        const decision = difference !== 0 ? decisionLabels[box.decisions?.[currency]] : null;

                        return (
                            <tr key={currency} className="border-t border-gray-100 text-gray-700">
                                <td className="p-3">{currencies[currency]}</td>
                                <td dir="ltr" className="p-3 text-start font-display">{formatMoney(box.expectedCounts?.[currency] ?? 0)}</td>
                                <td dir="ltr" className="p-3 text-start font-display">{formatMoney(box.receivedCounts[currency] ?? 0)}</td>
                                <td className={`p-3 font-semibold ${difference < 0 ? 'text-red-600' : difference > 0 ? 'text-emerald-600' : 'text-gray-400'}`}>
                                    {difference === 0 ? 'مطابق' : <>{difference < 0 ? 'عجز' : 'زيادة'} <bdi dir="ltr" className="font-display">{formatMoney(Math.abs(difference))}</bdi></>}
                                </td>
                                <td className="p-3">{decision ? <StatusPill tone={decision[1]} label={decision[0]} /> : '—'}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/**
 * The collector's one action on a box: confirming the handover after the
 * branch counted and received their cash box. Closing, counting and
 * receiving belong to the branch.
 */
export function CashBoxAction({ box, onClose, onSuccess }) {
    const form = useForm({ branch_id: box.branchId });
    const close = () => { if (!form.processing) onClose(); };

    function submit(event) {
        event.preventDefault();
        form.post(box.urls.confirm, { preserveScroll: true, onSuccess: () => { onSuccess?.(); onClose(); } });
    }

    return (
        <Modal show onClose={close} centered maxWidth="lg">
            <form onSubmit={submit} className="space-y-5 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h3 className="text-xl font-bold text-gray-900">تأكيد تسليم صندوقي</h3>
                        <p dir="ltr" className="mt-1 text-start font-display text-xs text-gray-500">{box.number}</p>
                    </div>
                    <button type="button" onClick={close} disabled={form.processing} aria-label="إغلاق" className="rounded-control p-2 text-gray-500 hover:bg-gray-100"><Icon name="close" className="h-5 w-5" /></button>
                </div>
                <div>
                    <p className="text-sm leading-7 text-gray-600">عدّ الفرع الصندوق واستلمه بتاريخ {dateTime(box.receivedAt)}. راجع العدّ أدناه، ثم أكّد أنك سلّمت الصندوق.</p>
                    <CountSummary box={box} />
                    {box.reason && <p className="mt-3 text-sm text-gray-600">سبب الفرق: {box.reason}</p>}
                </div>
                <InputError message={form.errors.action ?? form.errors.branch_id} />
                <div className="flex flex-wrap justify-end gap-3 border-t border-gray-100 pt-4">
                    <SecondaryButton type="button" onClick={close} disabled={form.processing}>إلغاء</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{form.processing ? 'جارٍ الحفظ…' : 'تأكيد التسليم'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** A box's report actions as the row buttons: print it (or save as PDF) in the print designer, or download it for Excel. */
export function BoxReportActions({ box, onView }) {
    return (
        <RowActionsMenu onView={onView}>
            <button onClick={() => window.open(printUrl('تقرير تفاصيل الصندوق', `/payments/cash-boxes/report?box=${box.id}`), '_blank', 'noopener')}>طباعة وتخصيص</button>
            <button onClick={() => window.open(excelUrl('تقرير تفاصيل الصندوق', `/payments/cash-boxes/report?box=${box.id}`), '_blank', 'noopener')}>Excel</button>
        </RowActionsMenu>
    );
}

/**
 * One of the collector's boxes of a type, open now or not yet: its total,
 * entries and when it opened, with its details ("عرض", the box's page)
 * and report. `onSelect` (the boxes page) adds a button that lists its
 * entries on the same page.
 */
export function BoxCard({ box, type, selected = false, onSelect }) {
    const others = box ? Object.keys(currencies).filter((currency) => currency !== 'ILS' && Number(box.totals?.[currency] ?? 0) !== 0) : [];

    return (
        <article className={`flex flex-col gap-4 rounded-panel border bg-surface p-5 shadow-card transition ${selected ? 'border-gray-900 ring-1 ring-gray-900' : 'border-gray-100'}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <BoxTypeIcon type={type} />
                    <div className="min-w-0">
                        <h3 className="font-bold text-gray-900">{boxTypes[type]}</h3>
                        <p className="mt-0.5 text-xs text-gray-500">{box ? <>صندوق رقم <bdi dir="ltr" className="font-display">{box.number}</bdi></> : 'لا يوجد صندوق مفتوح'}</p>
                    </div>
                </div>
                <StatusPill tone={box ? 'blue' : 'gray'} label={box ? 'مفتوح' : 'لا حركات'} />
            </div>
            <div>
                <p className="flex items-baseline gap-1.5">
                    <span dir="ltr" className="font-display text-3xl font-bold text-gray-900">{formatMoney(box?.totals?.ILS ?? 0)}</span>
                    <span className="text-sm text-gray-500">₪</span>
                </p>
                {others.map((currency) => <p key={currency} className="mt-1 text-sm text-gray-600"><b dir="ltr" className="font-display">{formatMoney(box.totals[currency])}</b> {currencies[currency]}</p>)}
            </div>
            <p className="text-xs leading-6 text-gray-500">{box ? `${box.paymentCount} حركات · فُتح ${dateTime(box.openedAt)}` : 'يُفتح تلقائيًا عند تسجيل أول حركة من هذا النوع.'}</p>
            {box && (
                <div className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-4">
                    {onSelect ? (
                        <button type="button" onClick={onSelect} aria-pressed={selected} className={`inline-flex items-center gap-2 rounded-control px-3 py-2 text-sm font-semibold transition ${selected ? 'bg-gray-900 text-surface' : 'border border-gray-200 text-gray-700 hover:bg-gray-50'}`}>
                            <Icon name="list" className="h-4 w-4" />
                            {selected ? 'حركاته معروضة أدناه' : 'عرض الحركات'}
                        </button>
                    ) : <span />}
                    <BoxReportActions box={box} onView={() => router.visit(box.urls.show)} />
                </div>
            )}
        </article>
    );
}

/**
 * A box's entries in the site's table, each as the subscriber's statement
 * shows it (voucher, reference, banks, date, description, amount, currency,
 * type, method, rate and who recorded it) with the box's number, the
 * subscriber, its state, and each payment's receipt to print. `pagination`
 * is passed to the table's Pagination.
 */
export function BoxEntriesTable({ type, boxNumber, entries, pagination }) {
    const columns = [['الصندوق', 72], ...ledgerColumns.before, ['المشترك', 150], ...ledgerColumns.after, ['الحالة', 140], ['', 48]];
    const widths = columns.map(([, width]) => width);

    return (
        <>
            <div className="data-table-container financial-ledger-container">
                <table className={ledgerTableClass} style={fixedTableStyle(widths)}>
                    <ColumnWidths widths={widths} />
                    <thead>
                        <tr>{columns.map(([label], index) => index === columns.length - 1 ? <ActionsTh key={index} /> : <th key={index}>{label}</th>)}</tr>
                    </thead>
                    <tbody>
                        {entries.data.length === 0 ? (
                            <tr><td colSpan={columns.length} className="text-center text-gray-500">لا توجد حركات في هذا الصندوق.</td></tr>
                        ) : entries.data.map((entry, index) => {
                            const [label, tone] = entryStatus(entry);
                            const cancelled = entry.transactionStatus !== 'active';
                            const ledger = ledgerOf(entry);

                            return (
                                <tr key={entry.id} className={cancelled ? 'opacity-60' : undefined}>
                                    <BoxNumberCell number={boxNumber} position={entries.from + index} />
                                    <LedgerLeadCells ledger={ledger} />
                                    <td title={entry.subscriber} style={{ whiteSpace: 'normal' }}>
                                        {entry.statementUrl ? (
                                            <Link href={entry.statementUrl} prefetch title="فتح كشف الحساب عند هذه الحركة" className="block font-semibold text-gray-900 hover:text-brand-600 hover:underline">{entry.subscriber}</Link>
                                        ) : (
                                            <span className="block font-semibold text-gray-900">{entry.subscriber}</span>
                                        )}
                                        <span className="block font-display text-xs text-gray-400"><bdi dir="ltr">{entry.accountNumber}</bdi></span>
                                    </td>
                                    <LedgerTrailCells ledger={ledger} amount={entry.amount} currency={entry.currency} cancelled={cancelled} />
                                    <td style={{ whiteSpace: 'normal' }}><StatusPill tone={tone} label={label} />{entry.isLate && <div className="mt-1"><StatusPill tone="amber" label="متأخرة" /></div>}</td>
                                    <td className="text-end">{(entry.receiptUrl || entry.statementUrl) && (
                                        <RowActionsMenu>
                                            {entry.statementUrl && <button onClick={() => router.visit(entry.statementUrl)}>كشف الحساب</button>}
                                            {entry.receiptUrl && <button onClick={() => window.open(entry.receiptUrl, '_blank', 'noopener')}>طباعة السند</button>}
                                        </RowActionsMenu>
                                    )}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <Pagination meta={entries} {...pagination} />
        </>
    );
}

/** The shortfalls the branch recorded as the collector's debt, still unpaid. */
export function CollectorDebts({ debts }) {
    if (!debts?.length) return null;

    return (
        <section className="space-y-3 rounded-panel border border-red-500/20 bg-red-500/5 p-5" aria-labelledby="collector-debts">
            <div>
                <h3 id="collector-debts" className="font-bold text-gray-900">ديون عليك</h3>
                <p className="mt-1 text-xs leading-6 text-gray-500">عجز في صناديقك قرّر الفرع تسجيله عليك. تسدّده للفرع، وهو يسجّل ما يستلمه منك.</p>
            </div>
            <ul className="divide-y divide-red-500/10">
                {debts.map((debt) => (
                    <li key={debt.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <div className="space-y-1">
                            {debt.box ? <Link href={debt.box.url} prefetch dir="ltr" className="font-display text-xs text-brand-600">{debt.box.number}</Link> : <span className="text-gray-500">—</span>}
                            <p className="text-xs text-gray-500">سُجّل {dateTime(debt.createdAt)} · أصل الدين <bdi dir="ltr" className="font-display">{formatMoney(debt.amount)}</bdi> {currencies[debt.currency]}</p>
                        </div>
                        <p className="text-gray-600">المتبقي <b dir="ltr" className="font-display text-base text-red-600">{formatMoney(debt.remaining)}</b> {currencies[debt.currency]}</p>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** A reply form posting `body` to `note.replyUrl`. */
export function AuditNoteReply({ note, onSaved }) {
    const form = useForm({ body: '' });

    function submit(event) {
        event.preventDefault();
        form.post(note.replyUrl, { preserveScroll: true, onSuccess: () => onSaved?.() });
    }

    return (
        <form onSubmit={submit} className="space-y-2">
            <label htmlFor={`audit-note-${note.id}`} className="text-xs font-semibold text-gray-600">ردّك</label>
            <textarea
                id={`audit-note-${note.id}`}
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                rows={3}
                maxLength={3000}
                required
                className="block w-full rounded-control border-gray-200 text-sm"
            />
            <InputError message={form.errors.body ?? form.errors.action} />
            <div className="flex justify-end">
                <PrimaryButton type="submit" disabled={form.processing || !form.data.body.trim()}>{form.processing ? 'جارٍ الإرسال…' : 'إرسال الرد'}</PrimaryButton>
            </div>
        </form>
    );
}

/** The audit notes the branch passed to the collector to answer. */
export function CollectorAuditNotes({ notes, onSaved }) {
    if (!notes?.length) return null;

    return (
        <section className="space-y-3 rounded-panel border border-amber-500/25 bg-amber-500/5 p-5" aria-labelledby="collector-audit-notes">
            <h3 id="collector-audit-notes" className="font-bold text-gray-900">ملاحظات تدقيق تنتظر ردّك</h3>
            {notes.map((note) => (
                <article key={note.id} className="space-y-3 border-t border-amber-500/15 pt-3">
                    <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        {note.box && <Link href={note.box.url} prefetch dir="ltr" className="font-display text-brand-600">{note.box.number}</Link>}
                        {note.closing?.coverage_from && <span><b className="font-semibold text-gray-700">{periodLabel(note.closing)}</b> · {periodRange(note.closing)}</span>}
                    </div>
                    {note.entry && <p className="text-xs text-gray-600"><b className="text-gray-900">{note.entry.subscriber}</b> · {note.entry.typeLabel}{note.entry.voucherNumber && <> · سند <bdi dir="ltr" className="font-display">{note.entry.voucherNumber}</bdi></>} · <bdi dir="ltr" className="font-display">{formatMoney(note.entry.amount)}</bdi> {currencies[note.entry.currency]}</p>}
                    <p className="text-sm leading-7 text-gray-800">{note.body}</p>
                    {note.question && <p className="rounded-control bg-surface p-3 text-sm leading-7 text-gray-700"><b className="text-gray-900">سؤال الفرع: </b>{note.question}</p>}
                    {note.differences && (
                        <div className="flex flex-wrap gap-3 text-xs text-gray-600">
                            {Object.entries(note.differences).filter(([, amount]) => Number(amount) !== 0).map(([currency, amount]) => (
                                <span key={currency}>{Number(amount) < 0 ? 'عجز' : 'زيادة'} <bdi dir="ltr" className="font-display">{formatMoney(Math.abs(Number(amount)))}</bdi> {currencies[currency]}</span>
                            ))}
                        </div>
                    )}
                    <AuditNoteReply note={note} onSaved={onSaved} />
                </article>
            ))}
        </section>
    );
}
