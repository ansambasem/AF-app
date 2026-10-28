import BankReceivedDate from '@/Components/BankReceivedDate';
import ExcelButton, { tableExportButtonClass } from '@/Components/ExcelButton';
import { BranchFilterBanner, splitBranchFilter } from '@/Components/BranchBanner';
import { Fragment, useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/DataTable/Pagination';
import Icon from '@/Components/Icon';
import LatePaymentDetails from '@/Components/LatePaymentDetails';
import SearchableSelect from '@/Components/SearchableSelect';
import { useDataTable } from '@/hooks/useDataTable';
import { useRowClick } from '@/hooks/useRowClick';
import { useStatementWindow } from '@/hooks/useStatementWindow';
import { BALANCE_HINTS, BALANCE_WORDS, balanceTone, signedBalance } from '@/lib/balance';
import { formatClock, formatDayLabel, formatMoney, formatNumber, formatShortDay } from '@/lib/format';
import { printUrl } from '@/lib/print';
import SplitPaymentBadge from '@/Pages/Payments/SplitPaymentBadge';
import StatementModal from '@/Pages/Subscriptions/StatementModal';
import './Ledger.css';
import PageHeader from '@/Components/PageHeader';
import InfoTip from '@/Components/InfoTip';
import DateInput from '@/Components/DateInput';

const PERIODS = [['today', 'اليوم'], ['yesterday', 'أمس'], ['7', '7 أيام'], ['30', '30 يوم'], ['month', 'هذا الشهر'], ['custom', 'مخصص']];
const BANK_LOGOS = {
    'بنك فلسطين': '/images/banks/bank-of-palestine.webp',
    'جوال باي': '/images/banks/jawwal-pay.webp',
    'محفظة بالباي': '/images/banks/palpay.webp',
    'البنك الإسلامي الفلسطيني': '/images/banks/palestine-islamic-bank.webp',
    'البنك الإسلامي العربي': '/images/banks/arab-islamic-bank.webp',
    'بنك القدس': '/images/banks/quds-bank.webp',
};

/**
 * An amount, always shown positive (its side is in the line's type), or with
 * `balance` a balance with its sign: negative is credit in the
 * subscription's favour. A balance is coloured unless `colored` is false, as
 * in the column of each line's running balance.
 */
function Money({ amount, balance = false, colored = balance, paperWord = false }) {
    if (!balance) {
        return <bdi className="num" dir="ltr">{formatMoney(Math.abs(Number(amount)))} <span>₪</span></bdi>;
    }

    const tone = balanceTone(amount);

    return (
        <>
            <bdi className={`num ${colored ? `balance-${tone}` : ''}`} dir="ltr" title={BALANCE_HINTS[tone]}>{signedBalance(amount)} <span>₪</span></bdi>
            {paperWord && <span className="balance-paper-word"> {BALANCE_WORDS[tone]}</span>}
        </>
    );
}

function shortDate(value) {
    return value ? value.split('-').reverse().join('/') : 'البداية';
}

function DateRange({ period, range, today, onChange, errors }) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState(range.from ?? today);
    const [to, setTo] = useState(range.to);
    const [error, setError] = useState('');
    const root = useRef(null);
    const trigger = useRef(null);
    const firstInput = useRef(null);

    useEffect(() => {
        if (!open) { return; }
        firstInput.current?.focus();
        function dismiss(event) {
            if (event.type === 'keydown' && event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            } else if (event.type === 'pointerdown' && !root.current?.contains(event.target) && !event.target.closest?.('[data-date-picker]')) {
                setOpen(false);
            }
        }
        document.addEventListener('pointerdown', dismiss);
        document.addEventListener('keydown', dismiss);
        return () => {
            document.removeEventListener('pointerdown', dismiss);
            document.removeEventListener('keydown', dismiss);
        };
    }, [open]);

    function toggle() {
        if (!open) {
            setFrom(range.from ?? today);
            setTo(range.to);
            setError('');
        }
        setOpen(!open);
    }

    function quickRange(preset) {
        const end = new Date(`${today}T12:00:00Z`);
        const start = new Date(end);
        if (preset === '7') {
            start.setUTCDate(start.getUTCDate() - 6);
        } else if (preset === 'month') {
            start.setUTCDate(1);
        } else {
            start.setUTCDate(1);
            end.setUTCDate(0);
            start.setUTCMonth(start.getUTCMonth() - 1);
        }
        setFrom(start.toISOString().slice(0, 10));
        setTo(end.toISOString().slice(0, 10));
        setError('');
    }

    function apply(event) {
        event.preventDefault();
        if (!from || !to || from > to) {
            setError('اختر تاريخ بداية ونهاية، بحيث تكون البداية قبل النهاية أو مساوية لها.');
            return;
        }
        onChange('custom', { from, to });
        setOpen(false);
        trigger.current?.focus();
    }

    return (
        <div className="per">
            <div className="pt2" role="group" aria-label="الفترة">
                {PERIODS.map(([value, label]) => (
                    <button type="button" key={value} aria-pressed={period === value} onClick={() => value === 'custom' ? toggle() : onChange(value)}>{label}</button>
                ))}
            </div>
            <div className="range" ref={root}>
                <button type="button" className="rbtn" ref={trigger} onClick={toggle} aria-expanded={open} aria-controls="ledger-date-range">
                    <Icon name="calendar" /><small>الفترة</small><bdi className="num" dir="ltr">{shortDate(range.from)} — {shortDate(range.to)}</bdi>
                </button>
                {open && (
                    <form id="ledger-date-range" className="rpop" onSubmit={apply} aria-labelledby="ledger-range-title">
                        <div className="range-heading">
                            <span className="range-heading-icon"><Icon name="calendar" /></span>
                            <div><h3 id="ledger-range-title">فترة مخصصة</h3><p>حدّد الفترة لعرض حركات السجل المالي.</p></div>
                            <button type="button" className="range-close" aria-label="إغلاق اختيار الفترة" onClick={() => { setOpen(false); trigger.current?.focus(); }}><Icon name="close" /></button>
                        </div>
                        <div className="two">
                            <label htmlFor="ledger-from">تاريخ البداية<DateInput id="ledger-from" className="range-date-input" buttonRef={firstInput} required max={to || undefined} value={from} onChange={setFrom} /></label>
                            <label htmlFor="ledger-to">تاريخ النهاية<DateInput id="ledger-to" className="range-date-input" required min={from || undefined} value={to} onChange={setTo} /></label>
                        </div>
                        <span className="range-presets-label">اختيار سريع</span>
                        <div className="qk">
                            <button type="button" onClick={() => quickRange('7')}>آخر 7 أيام</button>
                            <button type="button" onClick={() => quickRange('month')}>هذا الشهر</button>
                            <button type="button" onClick={() => quickRange('previous')}>الشهر الماضي</button>
                        </div>
                        {(error || errors.from || errors.to) && <p className="err" role="alert"><Icon name="info" />{error || errors.from || errors.to}</p>}
                        <div className="ft">
                            <button type="submit" className="btn pr"><Icon name="check" />تطبيق الفترة</button>
                            <button type="button" className="btn" onClick={() => { setOpen(false); trigger.current?.focus(); }}>إلغاء</button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

function SummaryCards({ totals, summary, side }) {
    const netSide = totals.net > 0 ? 'للشركة' : totals.net < 0 ? 'للمشترك' : 'مسدّد';
    return (
        <div className="kp">
            <section className="k hero" aria-label="إجمالي التحميل">
                <small>إجمالي التحميل (عليه)
                    {side === 'debit' && summary.changePct !== null && (
                        <span className={`chg ${summary.changePct >= 0 ? 'up' : 'dn'}`} title="مقارنة بالفترة السابقة"><bdi dir="ltr">{summary.changePct >= 0 ? '+' : '−'}{Math.abs(summary.changePct)}%</bdi></span>
                    )}
                </small>
                <b><Money amount={totals.charged} /></b>
                <p>{formatNumber(totals.debitCount)} قيد تحميل · ضمن الفترة والفلاتر</p>
            </section>
            <section className="k cr" aria-label="المحصّل">
                <small><span className="dot bg-emerald-600 dark:bg-emerald-400" />المحصّل (له)</small>
                <b><Money amount={totals.credited} /></b>
                <p>{formatNumber(totals.creditCount)} قيد · دفعات وخصم ومقاصة</p>
            </section>
            <section className="k" aria-label="الصافي">
                <small>الصافي (عليه − له)</small>
                <b><Money amount={totals.net} balance /> <span>{netSide}</span></b>
                <p>لنفس الفترة والفلاتر</p>
            </section>
            <section className="k cx" aria-label="القيود الملغاة">
                <small><Icon name="close" />الملغاة</small>
                <b><Money amount={totals.cancelled} /></b>
                <p>{formatNumber(totals.cancelledCount)} قيد · خارج المجاميع</p>
            </section>
        </div>
    );
}

function DayHeader({ day, totals, today }) {
    return (
        <tr className="data-table-group day-row"><td colSpan={9}>
            <div className="day"><b>{formatDayLabel(day)}</b>{day === today && <span className="tod">اليوم</span>}
                {totals && <span className="sum"><span>{formatNumber(totals.count)} قيد</span><span>عليه <em><Money amount={totals.charged} /></em></span><span>له <em className="g"><Money amount={totals.credited} /></em></span></span>}
            </div>
        </td></tr>
    );
}

function TransactionRow({ entry, grouped, onOpen, rowClick }) {
    const initials = entry.subscriptionName.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('');
    const balance = Number(entry.balanceAfter ?? 0);
    const time = grouped ? formatClock(entry.time) : `${formatShortDay(entry.day)} · ${formatClock(entry.time)}`;
    const payment = entry.bankName || entry.paymentMethodLabel;
    return (
        <tr className={`row ${entry.isCancelled ? 'cx' : ''}`} {...rowClick(onOpen)}>
            <td className="t"><bdi className="desktop-time">{time}</bdi>
                <LatePaymentDetails paidOn={entry.paidOn} recordedAt={`${entry.day} ${entry.time}`} reason={entry.lateReason} />
                <BankReceivedDate value={entry.bankReceivedOn} />
                <div className="meta">
                    <span><Icon name="clock" />{time}</span>
                    {entry.voucherNumber && <span>سند <bdi>{entry.voucherNumber}</bdi>{entry.isManualVoucher && ' · يدوي'}</span>}
                    {payment && <span>{payment}</span>}
                    {entry.referenceNumber && <span>مرجع <bdi>{entry.referenceNumber}</bdi></span>}
                    {entry.splitPayment && <SplitPaymentBadge split={entry.splitPayment} compact />}
                    <span>سجّله: {entry.recordedByName ?? '—'}</span>
                </div>
            </td>
            <td className="who-c">
                <div className="who"><span className="av" aria-hidden="true">{initials}</span><div>
                    <b>{onOpen ? <button type="button" className="name-btn" onClick={onOpen} aria-label={`فتح كشف حساب ${entry.subscriptionName}`}>{entry.subscriptionName}</button> : entry.subscriptionName}</b>
                    <small><bdi>{entry.subscriptionAccountNumber}</bdi> · {entry.branchName}</small>
                </div></div>
            </td>
            <td className="ty-c"><span className={`ty ${entry.isCancelled ? 'rv' : entry.isCredit ? 'cr' : 'dr'}`}>{entry.typeLabel}</span>{entry.isCancelled && entry.type !== 'reversal' && <div><span className="cxb">ملغاة</span></div>}</td>
            <td className="vch-c"><bdi className="vch">{entry.voucherNumber ?? '—'}</bdi>{entry.isManualVoucher && <span className="mb">يدوي</span>}</td>
            <td className="mth-c">{payment ? <div className="mth">{BANK_LOGOS[entry.bankName] ? <img src={BANK_LOGOS[entry.bankName]} alt="" /> : <span className="ci"><Icon name={entry.paymentMethod === 'cash' ? 'wallet' : 'bank'} /></span>}<span title={payment}>{payment}</span></div> : '—'}</td>
            <td className="ref" title={entry.referenceNumber ?? undefined}>
                <div className="flex min-w-0 flex-col items-start gap-1">
                    <bdi dir="ltr">{entry.referenceNumber ?? '—'}</bdi>
                    {entry.splitPayment && <SplitPaymentBadge split={entry.splitPayment} compact />}
                </div>
            </td>
            <td className="by" title={entry.recordedByName ?? undefined}>{entry.recordedByName ?? '—'}</td>
            <td className="am-c"><span className={`am ${entry.isCredit ? 'cr' : 'dr'}`}><Money amount={entry.amount} />
                {entry.currency && entry.currency !== 'ILS' && <small><bdi dir="ltr">{formatMoney(entry.currencyAmount)} {entry.currency}{entry.exchangeRate && ` × ${formatMoney(entry.exchangeRate)}`}</bdi></small>}
            </span></td>
            <td className="bal-c"><span className="blc">{entry.balanceAfter === null ? '—' : <Money amount={balance} balance colored={false} paperWord />}</span></td>
        </tr>
    );
}

export default function Index({ entries, period, dateBasis = 'recorded', side, summary, ledgerTotals, dateRange, dayTotals, today, scopeLabel, branchScope, filters, filterOptions, statement }) {
    const { can, errors = {} } = usePage().props;
    const extraParams = { period, date_basis: dateBasis, ...(period === 'custom' ? dateRange : {}) };
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/ledger', filters, extraParams);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const rowClick = useRowClick();
    const statementWindow = useStatementWindow(statement);
    const grouped = filters.sort !== 'amount';
    // An auditor of several branches picks the branch in the banner above the log, not among the filters; clearing those keeps it.
    const branchFilter = splitBranchFilter(branchScope, filterOptions, filterValues);
    const activeFilters = Object.values(branchFilter.values).filter((value) => value !== '' && value !== null && value !== undefined).length;
    const clearPanelFilters = () => branchFilter.clear(setFilters, clearFilters);

    function changePeriod(next, dates = {}) {
        router.get('/ledger', { search, sort: filters.sort, direction: filters.direction, per_page: filters.per_page, filter: filterValues, date_basis: dateBasis, period: next, ...dates }, { preserveState: true, preserveScroll: true, replace: true });
    }

    /** The subscriber's statement over the page, opened at this transaction. */
    function openStatement(entry) {
        statementWindow.open({ id: entry.subscriptionId, fullName: entry.subscriptionName, accountNumber: entry.subscriptionAccountNumber, status: entry.subscriptionStatus, statusLabel: entry.subscriptionStatusLabel, branchName: entry.branchName }, null, entry.id);
    }

    function sortHeading(column, label) {
        return <th scope="col" aria-sort={filters.sort === column ? filters.direction === 'asc' ? 'ascending' : 'descending' : 'none'}><button type="button" onClick={() => sort(column)}>{label}{filters.sort === column && <Icon name="chevron-down" className={filters.direction === 'asc' ? 'rotate-180' : ''} />}</button></th>;
    }

    return (
        <AuthenticatedLayout
            scope={branchScope?.name ?? null}
            header={
                <PageHeader
                    eyebrow={branchScope ? null : scopeLabel}
                    title="السجل المالي"
                    actions={
                        <>
                            <ExcelButton title="السجل المالي" />
                            {can?.printAuditReports !== false && <a className={`${tableExportButtonClass} tone-print`} href={printUrl('السجل المالي')} target="_blank" rel="noopener noreferrer"><Icon name="printer" className="h-4 w-4" />طباعة</a>}
                        </>
                    }
                />
            }
        >
            <Head title="السجل المالي" />
            {branchScope && <BranchFilterBanner scope={branchScope} eyebrow="يعرض السجل المالي لـ" group={branchFilter.branch} value={filterValues.branch_id} onChange={(value) => setFilter('branch_id', value)} />}
            <div className="ledger-page">
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <div className="w-56"><div className="mb-1.5 flex items-center gap-1"><label htmlFor="ledger-date-basis" className="block text-sm font-semibold text-gray-700">تصفية الفترة حسب</label><InfoTip label="عن تصفية الفترة">{dateBasis === 'paid' ? 'عرض حسب تاريخ الدفع الفعلي للدفعات المتأخرة، وتاريخ التسجيل لبقية الحركات. الأرصدة وترتيب الحركات يبقيان حسب التسجيل.' : 'الأرصدة والفترات تُحتسب حسب تاريخ التسجيل. تاريخ الدفع السابق محفوظ للتدقيق.'}</InfoTip></div><SearchableSelect id="ledger-date-basis" clearable={false} searchable={false} value={dateBasis} options={[{value: 'recorded', label: 'تاريخ التسجيل بالنظام'}, {value: 'paid', label: 'تاريخ الدفع الفعلي'}]} onChange={(value) => changePeriod(period, { ...(period === 'custom' ? dateRange : {}), date_basis: value })} /></div>
                    <DateRange period={period} range={dateRange} today={today} onChange={changePeriod} errors={errors} />
                </div>
                {!errors.from && !errors.to ? null : <p role="alert" className="err">{errors.from || errors.to}</p>}
                <SummaryCards totals={ledgerTotals} summary={summary} side={side} />
                <section className="pn" aria-label="القيود المالية">
                    <div className="tb">
                        <div className="srch">
                            <Icon name="search" className="search-icon" />
                            <label htmlFor="ledger-search" className="sr-only">البحث في السجل المالي</label>
                            <input id="ledger-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="ابحث بالاسم، الهاتف، رقم الحساب، رقم السند أو الرقم المرجعي…" autoComplete="off" aria-describedby="ledger-search-hint" />
                            <div className="hint" id="ledger-search-hint">البحث يشمل: <span>الاسم</span><span>الهاتف</span><span>الحساب</span><span>السند</span><span>المرجع</span></div>
                        </div>
                        <button type="button" className="btn fbtn" onClick={() => setFiltersOpen(!filtersOpen)} aria-expanded={filtersOpen} aria-controls="ledger-filters"><Icon name="filter" />الفلاتر{activeFilters > 0 && <span className="num">{activeFilters}</span>}</button>
                    </div>
                    <div className={`fb ${filtersOpen ? 'open' : ''}`} id="ledger-filters">
                        {branchFilter.groups.map((group) => (
                            <div className="flex w-full min-w-0 flex-col gap-1.5 sm:w-44" key={group.key}>
                                <label htmlFor={`ledger-filter-${group.key}`} className={`flex items-center gap-1.5 text-xs font-semibold ${filterValues[group.key] ? 'text-gray-900' : 'text-gray-500'}`}>
                                    {group.label}
                                    {filterValues[group.key] && <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true" />}
                                </label>
                                <SearchableSelect
                                    id={`ledger-filter-${group.key}`}
                                    value={filterValues[group.key] ?? ''}
                                    onChange={(value) => setFilter(group.key, value)}
                                    options={group.options}
                                    placeholder="الكل"
                                    searchPlaceholder={`بحث في ${group.label}...`}
                                    emptyLabel="لا توجد نتائج"
                                    active={Boolean(filterValues[group.key])}
                                />
                            </div>
                        ))}
                        <label className="inline-flex h-11 cursor-pointer items-center gap-2 rounded-control border border-gray-200 bg-surface px-3 text-sm font-semibold text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-brand-600 focus:ring-brand-500" checked={filterValues.show_cancelled !== '0'} onChange={(event) => setFilter('show_cancelled', event.target.checked ? '' : '0')} />إظهار الملغاة</label>
                        {activeFilters > 0 && <button type="button" className="clr" onClick={clearPanelFilters}>مسح الفلاتر</button>}
                    </div>
                    <table data-excel-source="custom" className="ledger-table" aria-label="السجل المالي">
                        <thead><tr>{sortHeading('created_at', 'الوقت')}<th scope="col">المشترك</th><th scope="col">النوع</th><th scope="col">السند</th><th scope="col">الطريقة والبنك</th><th scope="col">المرجع</th><th scope="col">سجّله</th>{sortHeading('amount', 'المبلغ')}<th scope="col">الرصيد بعده</th></tr></thead>
                        <tbody>{entries.data.length === 0 ? <tr className="empty"><td colSpan={9}><b>لا توجد قيود مطابقة</b>جرّب فترة أخرى أو غيّر البحث والفلاتر.</td></tr> : entries.data.map((entry, index) => <Fragment key={entry.id}>{grouped && entry.day !== entries.data[index - 1]?.day && <DayHeader day={entry.day} totals={dayTotals[entry.day]} today={today} />}<TransactionRow entry={entry} grouped={grouped} rowClick={rowClick} onOpen={can?.viewSubscriptions ? () => openStatement(entry) : null} /></Fragment>)}</tbody>
                    </table>
                    <div className="foot data-table-totals">
                        <div>إجمالي التحميل<b><Money amount={ledgerTotals.charged} /></b></div>
                        <div>المحصّل<b className="g"><Money amount={ledgerTotals.credited} /></b></div>
                        <div>الصافي<b><Money amount={ledgerTotals.net} balance /> <span>{ledgerTotals.net > 0 ? 'للشركة' : ledgerTotals.net < 0 ? 'للمشترك' : 'مسدّد'}</span></b></div>
                        <span className="r">{formatNumber(entries.total)} قيد مطابق · الملغاة خارج المجاميع</span>
                    </div>
                    <div className="ledger-pagination">
                        <div className="w-44">
                            <label htmlFor="ledger-per-page" className="sr-only">عدد القيود في الصفحة</label>
                            <SearchableSelect
                                id="ledger-per-page"
                                value={filters.per_page}
                                onChange={setPerPage}
                                options={[15, 25, 50, 100].map((count) => ({ value: count, label: `${count} قيد / صفحة` }))}
                                clearable={false}
                                searchable={false}
                            />
                        </div>
                        <Pagination meta={entries} filters={filters} baseUrl="/ledger" extraParams={extraParams} />
                    </div>
                </section>
            </div>
            {statementWindow.subscription && <StatementModal key={statementWindow.subscription.id} subscription={statementWindow.subscription} statement={statementWindow.statement} initialForm={statementWindow.form} focusLineId={statementWindow.focusLineId} onSwitch={(header) => statementWindow.open(header)} onClose={statementWindow.close} />}
        </AuthenticatedLayout>
    );
}
