import { useState } from 'react';
import ExcelButton from '@/Components/ExcelButton';
import { Link } from '@inertiajs/react';
import AuditApprovalDialog from './AuditApprovalDialog';
import BankTransferAuditForm from './BankTransferAuditForm';
import ConfirmDialog from '@/Components/ConfirmDialog';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import ColumnWidths, { fixedTableStyle } from '@/Components/DataTable/ColumnWidths';
import StatusPill from '@/Components/DataTable/StatusPill';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SegmentedTabs from '@/Components/SegmentedTabs';
import SearchableSelect from '@/Components/SearchableSelect';
import { BankMark } from '@/Components/Banks';
import { formatMoney } from '@/lib/format';
import { printUrl } from '@/lib/print';
import { boxTypes, currencies, dateTime, BoxTypeIcon } from './CollectorCashBox';
import { ledgerColumns, ledgerOf, ledgerTableClass, BoxNumberCell, LedgerLeadCells, LedgerTrailCells } from './LedgerColumns';
import BoxBreakdown, { breakdownEntry } from './BoxBreakdown';
import { auditApprovalSummary, boxBankOptions, filterBoxEntries, boxStatus, entryStatus, isFlaggedEntry } from '@/lib/financialBoxes';
import './financial-boxes.css';


/** A small action in an entry's row. */
const rowButton = 'inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold transition hover:bg-gray-100';
const auditEditButton = `${rowButton} border border-brand-500/25 bg-brand-500/5 text-brand-700 hover:border-brand-500/40 hover:bg-brand-500/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:text-brand-300`;

const isActive = (entry) => !entry.transaction || entry.transaction.status === 'active';
const subscriberName = (entry) => entry.transaction?.subscription?.subscription_name || entry.transaction?.subscription?.full_name || '—';

/** Amounts added up by currency. */
function sumByCurrency(entries) {
    return Object.fromEntries(Object.keys(currencies).map((currency) => [currency, entries.filter((entry) => entry.currency === currency).reduce((sum, entry) => sum + Number(entry.amount), 0)]));
}

/** Amounts as one line, shekels always and another currency only when it has some. */
function amountsLine(amounts) {
    return Object.keys(currencies).filter((currency) => currency === 'ILS' || Number(amounts?.[currency] ?? 0) !== 0).map((currency) => `${formatMoney(amounts?.[currency] ?? 0)} ${currencies[currency]}`).join(' · ');
}

/** The foreign currencies among amounts, as one quiet line, or nothing when there are none. */
function otherCurrencies(amounts) {
    const lines = Object.keys(currencies).filter((currency) => currency !== 'ILS' && Number(amounts?.[currency] ?? 0) !== 0).map((currency) => `${formatMoney(amounts[currency])} ${currencies[currency]}`);

    return lines.length ? lines.join(' · ') : undefined;
}

/** One figure in the box's summary strip. */
function Stat({ icon, label, value, hint }) {
    return <div className="flex min-w-0 items-start gap-3 bg-surface p-3 sm:p-4">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500"><Icon name={icon} className="h-[18px] w-[18px]" /></span>
        <div className="min-w-0">
            <dt className="text-xs font-semibold text-gray-500">{label}</dt>
            <dd className="mt-1 font-display text-xl font-bold text-gray-900">{value}</dd>
            {hint && <dd className="mt-0.5 text-xs text-gray-500">{hint}</dd>}
        </div>
    </div>;
}

/** One step of a box's history: when it happened and who did it, or that it has not yet. */
function JourneyStep({ icon, label, at, by, pending, children }) {
    return <li className={`flex min-w-0 items-start gap-2.5 rounded-control border p-3 ${at ? 'border-gray-100 bg-surface' : 'border-dashed border-gray-200'}`}>
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] ${at ? 'bg-gray-900 text-surface' : 'bg-gray-100 text-gray-400'}`}><Icon name={icon} className="h-4 w-4" /></span>
        <span className="min-w-0 leading-5">
            <span className="block text-xs font-semibold text-gray-500">{label}</span>
            {at ? <><b className="block text-sm text-gray-900">{dateTime(at)}</b>{by && <span className="block truncate text-xs text-gray-600">{by}</span>}</> : <span className="block text-sm text-gray-500">{pending}</span>}
            {children}
        </span>
    </li>;
}

/** When the box was opened and closed, and by whom. */
function Journey({ box }) {
    return <ol aria-label="مسار الصندوق" className="grid gap-2 sm:grid-cols-2">
        <JourneyStep icon="clock" label="فُتح" at={box.opened_at} by={box.user?.name} />
        <JourneyStep icon="lock" label="أُغلق" at={box.status === 'received' ? box.closedAt ?? box.closed_at : null} by={box.closedBy} pending="لم يُغلق بعد" />
    </ol>;
}

/**
 * One box, large: who it belongs to, its totals, when it was opened, closed
 * and by whom, and every entry in it as the subscriber's statement
 * shows it. The branch confirms an open box closed from the footer once the
 * collector has corrected anything it raised with them. The branch does not check transfers or clearings one by
 * one: the audit does.
 */
export default function BoxDetails({ box, onClose, perform, initialSearch = '' }) {
    const [search, setSearch] = useState(initialSearch);
    const [filter, setFilter] = useState('all');
    const [bank, setBank] = useState('');
    const [confirmingClose, setConfirmingClose] = useState(false);
    const [confirmingAudit, setConfirmingAudit] = useState(false);
    const [auditingEntry, setAuditingEntry] = useState(null);
    const type = box.type ?? 'cash';
    const cash = type === 'cash';
    const clearing = type === 'clearing';
    const entries = box.payments ?? [];
    const active = entries.filter(isActive);
    const totals = sumByCurrency(active);
    const editable = ['open', 'pending'].includes(box.status);
    const confirmable = box.canCount && ['open', 'pending'].includes(box.status);
    const bankAudit = !editable && type === 'bank_transfer';
    const [statusLabel, statusTone] = boxStatus(box);
    const counted = cash && box.head_counts && box.expected_counts && Object.keys(currencies).some((currency) => Number(box.head_counts[currency] ?? 0) !== Number(box.expected_counts[currency] ?? 0));
    const groups = {
        all: ['الكل', () => true],
        active: ['سارية', (entry) => isActive(entry) && !isFlaggedEntry(entry)],
        ...(cash ? {} : { rejected: [clearing ? 'مرفوضة' : 'غير موجودة', (entry) => isActive(entry) && isFlaggedEntry(entry)] }),
        cancelled: ['ملغاة', (entry) => !isActive(entry)],
    };
    const filterOptions = Object.entries(groups).map(([value, [label, matches]]) => ({ value, label: `${label} · ${entries.filter(matches).length}`, count: entries.filter(matches).length })).filter((option) => option.value === 'all' || option.count > 0);
    const banks = type === 'bank_transfer' ? boxBankOptions(entries) : [];
    const shown = filterBoxEntries(entries, { bank, state: filter, search });
    const columns = [['الصندوق', 72], ...ledgerColumns.before, ['المشترك', 150], ...ledgerColumns.after, ['الحالة', 140], ...(bankAudit ? [['تدقيق البنك', 220]] : []), ['', 110]];
    const widths = columns.map(([, width]) => width);

    return <Modal show onClose={onClose} maxWidth="full" centered panelClassName="financial-box-detail financial-surface flex flex-col">
        <header className="flex shrink-0 flex-wrap items-start justify-between gap-4 border-b border-gray-100 p-6">
            <div className="flex items-center gap-3">
                <BoxTypeIcon type={type} className="h-12 w-12" />
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{boxTypes[type]} · صندوق <bdi dir="ltr" className="font-display">{box.number}</bdi></h2>
                    <p className="mt-1 text-sm text-gray-500">{box.user?.name ?? '—'}</p>
                </div>
            </div>
            <div className="flex items-center gap-3">
                {box.canPrint !== false && <><ExcelButton title="تقرير تفاصيل الصندوق" base={`/payments/cash-boxes/report?box=${box.id}`} /><a href={printUrl('تقرير تفاصيل الصندوق', `/payments/cash-boxes/report?box=${box.id}`)} target="_blank" rel="noopener noreferrer" className="tone-print inline-flex items-center gap-2 rounded-control border px-3 py-2 text-sm font-semibold"><Icon name="printer" className="h-4 w-4" />طباعة وتخصيص</a></>}
                <StatusPill tone={statusTone} label={statusLabel} />
                {!editable && <StatusPill tone={box.audit ? 'green' : 'amber'} label={box.audit ? 'اكتمل التدقيق' : 'بانتظار اكتمال التدقيق'} />}
                <button type="button" onClick={onClose} aria-label="إغلاق النافذة" className="rounded-control p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900"><Icon name="close" className="h-5 w-5" /></button>
            </div>
        </header>

        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto bg-gray-50/60 p-4 sm:p-6">
            <dl className="financial-box-detail-stats grid grid-cols-2 gap-px overflow-hidden rounded-card border border-gray-100 bg-gray-100 xl:grid-cols-4">
                <Stat icon="wallet" label="إجمالي الحركات السارية" value={<>{formatMoney(totals.ILS)} <span className="text-sm font-normal text-gray-500">شيكل</span></>} hint={otherCurrencies(totals)} />
                <Stat icon="list" label="الحركات" value={active.length.toLocaleString('en')} hint={entries.length > active.length ? `و${entries.length - active.length} ملغاة` : 'لا حركات ملغاة'} />
                <Stat icon={editable ? 'unlock' : 'lock'} label="تصحيح الحركات" value={<span className="text-base">{editable ? 'متاح للمحصّل' : 'مقفل'}</span>} hint={editable ? 'حتى تأكيد إغلاق الصندوق' : 'أُكّد إغلاق الصندوق'} />
                <Stat icon="calendar" label="فُتح ← أُغلق" value={<span className="text-sm">{dateTime(box.opened_at)}</span>} hint={box.closed_at ? dateTime(box.closed_at) : 'لا يزال مفتوحًا'} />
            </dl>

            <Journey box={box} />

            {!editable && <section aria-label="تدقيق الصندوق" className="space-y-3 rounded-card border border-gray-100 bg-surface p-4 sm:p-5">
                <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-bold text-gray-900">مراجعة قسم التدقيق</h3>{box.audit ? <p className="text-sm text-gray-600">اعتمدها {box.audit.auditor?.name} · {dateTime(box.audit.audited_at)}</p> : box.canAudit && <PrimaryButton disabled={!box.canCompleteAudit} onClick={() => setConfirmingAudit(true)}>اعتماد الصندوق كاملًا</PrimaryButton>}</div>
                {bankAudit && <p className="text-sm leading-7 text-gray-600">حُفظت مراجعة <b>{box.auditSummary?.reviewed ?? 0}</b> من <b>{box.auditSummary?.total ?? active.length}</b> حوالة. المثبت بحسب المراجعات: <b>{formatMoney(box.auditSummary?.verified ?? 0)} شيكل</b> · صافي الفروق على المشتركين: <b dir="ltr">{formatMoney(box.auditSummary?.difference ?? 0)} شيكل</b>. إجمالي الصندوق التاريخي محفوظ كما أُغلق.</p>}
                {bankAudit && (box.canReviewTransfers ?? box.canAudit) && <p className="text-sm leading-7 text-gray-600">سجّل الحوالات التي فيها مشاكل فقط. عند اعتماد الصندوق تُرحّل فروق المراجعات المحفوظة ماليًا وتُعتمد بقية الحوالات كمطابقة تلقائيًا.</p>}
            </section>}

            <BoxBreakdown type={type} entries={active.map(breakdownEntry)} />

            {confirmable && <p className="rounded-control bg-sky-500/10 px-4 py-3 text-sm leading-7 text-sky-700 dark:text-sky-400">اطّلع على حركات الصندوق ثم أكّد إغلاقه. إن رأيت خطأ في حركة فتواصل مع المحصّل ليعدّلها بنفسه قبل التأكيد؛ بعد التأكيد تُقفل الحركات وينتقل الصندوق إلى «المغلقة» ويظهر تلقائيًا لقسم التدقيق ضمن فترة إغلاقه الأسبوعية.</p>}


            <section aria-label="حركات الصندوق" className="space-y-4">
                <div className="data-table-toolbar flex flex-wrap items-center justify-between gap-3">
                    <div className="flex w-full min-w-0 flex-wrap items-end gap-3 sm:w-auto sm:flex-1">
                        <div className="relative min-w-0 w-full sm:max-w-sm sm:flex-1">
                            <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400" />
                            <input type="text" aria-label="ابحث في حركات الصندوق" placeholder="ابحث بالمشترك أو رقم الحساب أو السند أو الحوالة" value={search} onChange={(event) => setSearch(event.target.value)} className="block w-full py-2.5 pe-4 ps-10 text-sm" />
                        </div>
                        {type === 'bank_transfer' && <div className="w-full sm:w-80">
                            <label htmlFor="box-entry-bank" className="mb-1.5 block text-xs font-semibold text-gray-600">البنك أو المحفظة المستقبلة</label>
                            <SearchableSelect id="box-entry-bank" value={bank} onChange={setBank} clearable={false} portal searchPlaceholder="ابحث عن بنك أو محفظة" options={[
                                { value: '', label: 'كل البنوك والمحافظ', hint: String(entries.length), icon: <Icon name="bank" className="h-6 w-6 text-gray-500" /> },
                                ...banks.map((option) => ({ value: option.value, label: option.value, hint: String(option.count), icon: <BankMark bank={option.value} className="h-6 w-6 rounded-md" /> })),
                            ]} />
                        </div>}
                    </div>
                    <SegmentedTabs label="حالة الحركة" value={filter} onChange={setFilter} options={filterOptions} />
                </div>
                <div className="data-table-container financial-ledger-container">
                    <table className={ledgerTableClass} style={fixedTableStyle(widths)}>
                        <ColumnWidths widths={widths} />
                        <thead><tr>{columns.map(([label], index) => index === columns.length - 1 ? <ActionsTh key={index} /> : <th key={index}>{label}</th>)}</tr></thead>
                        <tbody>
                            {shown.length === 0 ? (
                                <tr><td colSpan={columns.length} className="text-center text-gray-500">{entries.length ? 'لا توجد حركات تطابق البحث والفلاتر المحددة.' : 'لا توجد حركات في هذا الصندوق.'}</td></tr>
                            ) : shown.map((entry) => {
                                const [label, tone] = entryStatus(entry);
                                const live = isActive(entry);
                                const ledger = ledgerOf(entry);

                                return <tr key={entry.id} className={live ? undefined : 'opacity-60'}>
                                    <BoxNumberCell number={box.number} position={entries.indexOf(entry) + 1} />
                                    <LedgerLeadCells ledger={ledger} />
                                    <td title={subscriberName(entry)} style={{ whiteSpace: 'normal' }}>
                                        {entry.statement_url ? <Link href={entry.statement_url} prefetch className="font-semibold text-gray-900 hover:text-brand-600">{subscriberName(entry)}</Link> : <span className="font-semibold text-gray-900">{subscriberName(entry)}</span>}
                                        <span className="block font-display text-xs text-gray-400"><bdi dir="ltr">{entry.transaction?.subscription?.account_number ?? '—'}</bdi></span>
                                    </td>
                                    <LedgerTrailCells ledger={ledger} amount={entry.amount} currency={entry.currency} cancelled={!live} />
                                    <td style={{ whiteSpace: 'normal' }}><StatusPill tone={tone} label={label} /></td>
                                    {bankAudit && <td style={{ whiteSpace: 'normal' }}>{entry.audit ? <div className="space-y-1"><StatusPill tone={entry.audit.outcome === 'missing' ? 'red' : entry.audit.outcome === 'matched' ? 'green' : 'amber'} label={{ matched: 'مطابقة', missing: 'غير موجودة', short: 'نقص في المبلغ', over: 'زيادة لصالح المشترك' }[entry.audit.outcome]} /><p className="text-xs font-semibold text-gray-600">{entry.audit.posted_at || entry.audit.adjustment_transaction_id ? 'معتمدة نهائيًا' : 'مسودة · لم تُرحّل ماليًا'}</p><p className="text-xs text-gray-600">المثبت: {formatMoney(entry.audit.bank_amount)} {ledger.currencyLabel}</p><p className="text-xs text-gray-500">{entry.audit.auditor?.name} · {dateTime(entry.audit.audited_at)}</p>{entry.audit.reason && <p className="text-xs text-gray-600">{entry.audit.reason}</p>}</div> : <StatusPill tone="gray" label={live ? 'تُطابق عند اعتماد الصندوق' : 'حركة غير سارية'} />}</td>}
                                    <td style={{ whiteSpace: 'normal' }}><div className="flex flex-wrap items-center gap-1">
                                        {bankAudit && (box.canReviewTransfers ?? box.canAudit) && live && !entry.audit?.posted_at && !entry.audit?.adjustment_transaction_id && <button type="button" onClick={() => setAuditingEntry(entry)} className={auditEditButton}><Icon name="pencil" className="h-3.5 w-3.5" />تعديل الحوالة</button>}
                                        {box.canPrint !== false && entry.receipt_url && <a href={entry.receipt_url} target="_blank" rel="noopener noreferrer" className={`${rowButton} tone-print border`}><Icon name="printer" className="h-3.5 w-3.5" />السند</a>}
                                    </div></td>
                                </tr>;
                            })}
                        </tbody>
                    </table>
                </div>
                <p className="data-table-totals text-sm text-gray-600" aria-live="polite" aria-atomic="true">
                    <span>المعروض <b className="font-display text-gray-900">{shown.length}</b> من <b className="font-display text-gray-900">{entries.length}</b> حركة</span>
                    <span>مجموع الحركات السارية المعروضة: <b className="text-gray-900">{amountsLine(sumByCurrency(shown.filter(isActive)))}</b></span>
                </p>
            </section>

            {counted && <BoxCountSummary box={box} />}
            {auditingEntry && <BankTransferAuditForm key={auditingEntry.id} entry={auditingEntry} onClose={() => setAuditingEntry(null)} />}
        </div>

        <footer className="shrink-0 border-t border-gray-100 bg-surface p-4 sm:px-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600">الإجمالي: <b className="text-gray-900">{amountsLine(totals)}</b> · {active.length} حركة سارية</p>
                <div className="flex flex-wrap items-center gap-2">
                    <SecondaryButton onClick={onClose}>إغلاق النافذة</SecondaryButton>
                    {confirmable && <PrimaryButton disabled={entries.length === 0} onClick={() => setConfirmingClose(true)}><Icon name="check" className="h-4 w-4" />تأكيد إغلاق الصندوق</PrimaryButton>}
                </div>
            </div>
        </footer>
        <ConfirmDialog
            show={confirmingClose}
            onCancel={() => setConfirmingClose(false)}
            onConfirm={() => { setConfirmingClose(false); perform('receive', box); }}
            title="إغلاق الصندوق؟"
            message="تُقفل الحركات بعد الإغلاق، ويصبح الصندوق متاحًا للتدقيق."
            confirmLabel="نعم، أغلق الصندوق"
            icon="lock"
        >
            <dl className="grid grid-cols-2 gap-x-5 gap-y-4 rounded-control bg-gray-50 p-5">
                <div><dt className="text-base text-gray-600">الصندوق</dt><dd className="mt-1 font-semibold text-gray-900">{boxTypes[type]} · <bdi dir="ltr">{box.number}</bdi></dd></div>
                <div><dt className="text-base text-gray-600">المحصّل</dt><dd className="mt-1 font-semibold text-gray-900">{box.user?.name ?? '—'}</dd></div>
                <div><dt className="text-base text-gray-600">الإجمالي</dt><dd className="mt-1 font-display text-xl font-bold text-gray-900">{amountsLine(totals)}</dd></div>
                <div><dt className="text-base text-gray-600">الحركات السارية</dt><dd className="mt-1 font-display text-xl font-bold text-gray-900">{active.length}</dd></div>
            </dl>
        </ConfirmDialog>
        {confirmingAudit && <AuditApprovalDialog title="اعتماد تدقيق الصندوق؟" scope={`${boxTypes[type]} · صندوق ${box.number} · ${box.user?.name ?? '—'}`} summary={auditApprovalSummary([box])} url={box.completeAuditUrl} onClose={() => setConfirmingAudit(false)} />}
    </Modal>;
}

/** A cash box the branch counted under the old two-step receipt: each currency's expected, counted and received amounts. */
function BoxCountSummary({ box }) {
    if (!box.expected_counts) return null;
    const money = (amount) => formatMoney(amount ?? 0);

    return <div className="overflow-x-auto rounded-panel border border-gray-100 bg-surface">
        <table className="w-full text-start text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500"><tr>{['العملة', 'المتوقع', 'عدّ المحصّل', 'عدّ المستلم', 'الفرق', 'القرار'].map((title) => <th key={title} className="whitespace-nowrap p-3 text-start">{title}</th>)}</tr></thead>
            <tbody>{Object.entries(currencies).map(([currency, label]) => <tr key={currency} className="border-t border-gray-100 text-gray-700">
                <td className="p-3">{label}</td>
                <td className="p-3 font-display" dir="ltr">{money(box.expected_counts[currency])}</td>
                <td className="p-3 font-display" dir="ltr">{box.collector_counts ? money(box.collector_counts[currency]) : '—'}</td>
                <td className="p-3 font-display" dir="ltr">{box.head_counts ? money(box.head_counts[currency]) : '—'}</td>
                <td className="p-3 font-display" dir="ltr">{box.head_counts ? money(Number(box.head_counts[currency]) - Number(box.expected_counts[currency])) : '—'}</td>
                <td className="p-3">{box.decisions?.[currency] === 'debt' ? 'دين على المحصّل' : box.decisions?.[currency] === 'accepted' ? 'مقبول' : '—'}</td>
            </tr>)}</tbody>
        </table>
        {box.reason && <p className="border-t border-gray-100 p-3 text-xs text-gray-500">السبب: {box.reason}</p>}
    </div>;
}
