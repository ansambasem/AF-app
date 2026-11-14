import { useEffect, useState } from 'react';
import { Head, router, useHttp } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiTile from '@/Components/KpiTile';
import StatRing from '@/Components/StatRing';
import DialogFrame from '@/Components/DialogFrame';
import SecondaryButton from '@/Components/SecondaryButton';
import Pagination from '@/Components/DataTable/Pagination';
import StatusPill from '@/Components/DataTable/StatusPill';
import PeriodCascade from '@/Components/PeriodCascade';
import { formatMoney, formatNumber } from '@/lib/format';
import { COLLECTION_INDICATORS, COLLECTION_RATES, MONEY_COLUMNS, SETTLEMENT_COLUMNS, SUBSCRIBER_COLUMNS, formatRate } from '@/lib/remainingAmounts';

const PERIOD_TONES = { open: 'green', closed: 'amber', approved: 'gray', scheduled: 'blue' };

function Money({ value, strong = false }) {
    const negative = Number(value) < 0;

    return <span className={`whitespace-nowrap tabular-nums ${strong ? 'font-bold' : ''} ${negative ? 'text-emerald-700 dark:text-emerald-400' : ''}`} dir="ltr">{formatMoney(value)}</span>;
}

function Reading({ value }) {
    return value === null || value === undefined ? <span className="text-gray-300">—</span> : <span className="tabular-nums" dir="ltr">{formatNumber(Number(value))}</span>;
}

/**
 * The period's collection, from the same rows as the totals above: the
 * four figures, the three rates, and the same for each branch in a table
 * that exports with the report.
 */
function Collection({ summary, branchRows }) {
    return (
        <section aria-label="التحصيل في الفترة" className="rise-in mb-6 space-y-4">
            <h3 className="text-lg font-bold text-gray-900">التحصيل في الفترة</h3>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {COLLECTION_INDICATORS.map(([key, label, hint]) => <KpiTile key={key} compact label={label} value={formatMoney(summary[key])} unit="₪" hint={hint} />)}
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                {COLLECTION_RATES.map(([key, label, hint]) => (
                    <div key={key} className="flex items-center gap-4 rounded-panel border border-gray-100 bg-surface p-5 shadow-card">
                        <StatRing percent={summary[key] ?? 0} size={72} />
                        <div className="min-w-0">
                            <p className="font-bold text-gray-900">{label} <span className="tabular-nums" dir="ltr">{formatRate(summary[key])}</span></p>
                            <p className="mt-1 text-xs leading-5 text-gray-500">{hint}</p>
                        </div>
                    </div>
                ))}
            </div>
            <div className="overflow-x-auto rounded-panel border border-gray-100 bg-surface shadow-card">
                <table className="w-full text-sm" aria-label="إجماليات التحصيل">
                    <thead className="bg-gray-50 text-gray-600">
                        <tr>
                            <th className="px-3 py-2 text-start font-semibold">الفرع</th>
                            {COLLECTION_INDICATORS.map(([key, label]) => <th key={key} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                            {COLLECTION_RATES.map(([key, label]) => <th key={key} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {branchRows.length > 1 && branchRows.map((branch) => (
                            <tr key={branch.id}>
                                <td className="px-3 py-2 font-semibold text-gray-900">{branch.name}</td>
                                {COLLECTION_INDICATORS.map(([key]) => <td key={key} className="px-3 py-2"><Money value={branch[key]} /></td>)}
                                {COLLECTION_RATES.map(([key]) => <td key={key} className="px-3 py-2 tabular-nums" dir="ltr">{formatRate(branch[key])}</td>)}
                            </tr>
                        ))}
                        <tr className="bg-gray-50 font-bold">
                            <td className="px-3 py-2 text-gray-900">الإجمالي</td>
                            {COLLECTION_INDICATORS.map(([key]) => <td key={key} className="px-3 py-2"><Money value={summary[key]} strong /></td>)}
                            {COLLECTION_RATES.map(([key]) => <td key={key} className="px-3 py-2 tabular-nums" dir="ltr">{formatRate(summary[key])}</td>)}
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/** One subscriber's payments of the period, each split into what settled the invoice, the accumulated debt, or became advance. */
function PaymentsDialog({ row, date, onClose }) {
    const http = useHttp();
    const [detail, setDetail] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let current = true;
        http.get(`/reports/remaining/${row.subscriptionId}/payments${date ? `?date=${date}` : ''}`)
            .then((loaded) => current && setDetail(loaded))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [row.subscriptionId, date]);

    return (
        <DialogFrame show onClose={onClose} title={`توزيع دفعات ${row.name}`} description={detail ? detail.period.label : null} icon="layers" maxWidth="4xl"
            footer={<SecondaryButton onClick={onClose}>إغلاق</SecondaryButton>}>
            {failed && <p className="py-6 text-center text-base text-brand-600">تعذّر تحميل تفاصيل الدفعات.</p>}
            {!detail && !failed && <p className="py-6 text-center text-base text-gray-500">جارٍ التحميل…</p>}
            {detail && (
                <>
                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                        {[['discountToInvoice', 'الخصم على الفاتورة'], ['advanceUsed', 'المقدم المستخدم'], ['invoiceTarget', 'مستهدف الفاتورة'], ['paidToInvoice', 'دفعات الفاتورة'], ['paidToAccumulated', 'المحصّل من المتراكم'], ['carriedAdvance', 'المقدم المرحّل للقادمة']].map(([key, label]) => (
                            <div key={key} className="rounded-control bg-gray-50 px-3 py-2"><dt className="text-gray-500">{label}</dt><dd className="font-bold"><Money value={detail.row[key]} /> ₪</dd></div>
                        ))}
                    </dl>
                    {detail.payments.length === 0 ? <p className="text-base text-gray-500">لا دفعات لهذا المشترك في الفترة.</p> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm" aria-label={`توزيع دفعات ${row.name}`}>
                                <thead className="bg-gray-50 text-gray-600">
                                    <tr>
                                        {['التاريخ', 'السند', 'الوسيلة', 'المبلغ', 'للفاتورة', 'للمتراكم', 'مقدم جديد', 'مسترد'].map((label) => <th key={label} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {detail.payments.map((payment) => (
                                        <tr key={payment.id}>
                                            <td className="px-3 py-2 tabular-nums" dir="ltr">{payment.date}</td>
                                            <td className="px-3 py-2 tabular-nums" dir="ltr">{payment.voucher ?? '—'}</td>
                                            <td className="px-3 py-2">{payment.method ?? '—'}</td>
                                            {['amount', 'toInvoice', 'toAccumulated', 'toAdvance', 'returned'].map((key) => <td key={key} className="px-3 py-2"><Money value={payment[key]} strong={key === 'amount'} /></td>)}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </DialogFrame>
    );
}

/**
 * Each subscriber's settlement on this page of the report, in its own table
 * under the subscribers one (whose columns never change), opened on demand.
 */
function Settlement({ rows, date }) {
    const [open, setOpen] = useState(false);
    const [showing, setShowing] = useState(null);

    return (
        <section className="rise-in mt-6 overflow-x-auto rounded-panel border border-gray-100 bg-surface shadow-card">
            <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h3 className="text-lg font-bold text-gray-900">تفاصيل التسوية والتحصيل</h3>
                    <p className="text-sm text-gray-500">لكل مشترك في هذه الصفحة: كيف سُوّيت فاتورته ومتراكمه، وما صار مقدمًا جديدًا.</p>
                </div>
                <SecondaryButton onClick={() => setOpen((value) => !value)} aria-expanded={open}>{open ? 'إخفاء التفاصيل' : 'عرض التفاصيل'}</SecondaryButton>
            </div>
            {open && (
                <table className="w-full text-sm" aria-label="تفاصيل التسوية والتحصيل">
                    <thead className="bg-gray-50 text-gray-600">
                        <tr>
                            <th className="px-3 py-2 text-start font-semibold">رقم المشترك</th>
                            <th className="px-3 py-2 text-start font-semibold">اسم المشترك</th>
                            <th className="px-3 py-2 text-start font-semibold">الفرع</th>
                            {SETTLEMENT_COLUMNS.map(([key, label]) => <th key={label} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => (
                            <tr key={`${row.periodId}-${row.subscriptionId}`}>
                                <td className="px-3 py-2 tabular-nums" dir="ltr">{row.accountNumber}</td>
                                <td className="px-3 py-2 font-semibold text-gray-900">{row.name}</td>
                                <td className="px-3 py-2 text-gray-600">{row.branchName}</td>
                                {SETTLEMENT_COLUMNS.map(([key, label, kind]) => <td key={label} className="px-3 py-2">{kind === 'rate' ? <span className="tabular-nums" dir="ltr">{formatRate(row[key])}</span> : <Money value={row[key]} strong={key === 'remaining'} />}</td>)}
                                <td className="px-3 py-2 text-end"><button type="button" onClick={() => setShowing(row)} className="whitespace-nowrap text-sm font-semibold text-brand-600 hover:underline">توزيع الدفعات</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {showing && <PaymentsDialog row={showing} date={date} onClose={() => setShowing(null)} />}
        </section>
    );
}

/**
 * The remaining amounts report: for the chosen period (each branch's open
 * one by default), every subscription's reading, invoice, accumulated or
 * advance, discounts, charges, payments and what remains, with the totals
 * above and each branch's totals; a branch's row narrows the report to it.
 * Under the totals, the period's collection (paid, invoice settled,
 * accumulated collected, new advance and the three rates); under the
 * subscribers, each one's settlement and the split of its payments.
 */
export default function RemainingAmounts({ filters, branches, periodChoices, summary, branchRows, rows }) {
    const [search, setSearch] = useState(filters.search);

    function apply(changes) {
        router.get('/reports/remaining', { ...filters, ...changes, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }

    useEffect(() => {
        if (search === filters.search) {
            return undefined;
        }
        const timer = setTimeout(() => apply({ search }), 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const select = 'rounded-control border-gray-300 bg-surface text-sm text-gray-900';

    return (
        <AuthenticatedLayout
            header={<PageHeader title="تقرير المبالغ المتبقية" info="لكل مشترك في الفترة: الفاتورة (فرق القراءة × سعر الكيلو، ولا تقل عن الحد الأدنى)، والمتراكم أو المقدم المرحّل من الفترات السابقة، والخصومات والمبالغ المحملة والدفعات (نقدي وبنكي ومقاصة)، والرصيد المتبقي. إجمالي كل فرع هو مجموع مشتركيه." />}
        >
            <Head title="تقرير المبالغ المتبقية" />

            <div className="rise-in mb-6 flex flex-wrap items-end gap-3 rounded-panel border border-gray-100 bg-surface p-4 shadow-card">
                {periodChoices.years.length > 0 && <PeriodCascade choices={periodChoices} onChange={(value) => apply({ date: value })} />}
                {branches.length > 1 && (
                    <label className="grid gap-1 text-sm font-semibold text-gray-700">
                        الفرع
                        <select className={select} value={filters.branch_id} onChange={(event) => apply({ branch_id: event.target.value })}>
                            <option value="">جميع الفروع</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </select>
                    </label>
                )}
                <label className="grid gap-1 text-sm font-semibold text-gray-700">
                    حالة المشترك
                    <select className={select} value={filters.status} onChange={(event) => apply({ status: event.target.value })}>
                        <option value="all">الكل</option>
                        <option value="active">فعّال</option>
                        <option value="disconnected">مفصول</option>
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-semibold text-gray-700">
                    الترتيب
                    <select className={select} value={filters.sort} onChange={(event) => apply({ sort: event.target.value })}>
                        <option value="remaining">أعلى رصيد متبقٍ</option>
                        <option value="name">الاسم</option>
                        <option value="account">رقم الحساب</option>
                    </select>
                </label>
                <label className="grid min-w-[14rem] flex-1 gap-1 text-sm font-semibold text-gray-700">
                    بحث
                    <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="اسم المشترك أو رقمه"
                        className="rounded-control border-gray-300 bg-surface text-sm text-gray-900" />
                </label>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                {MONEY_COLUMNS.map(([key, label]) => (
                    <KpiTile key={key} compact hero={key === 'remaining'} label={`إجمالي ${label.replace('إجمالي ', '')}`} value={formatMoney(summary[key])} unit="₪"
                        hint={key === 'remaining' ? `${formatNumber(summary.owing)} عليهم مبالغ · ${formatNumber(summary.ahead)} لهم مقدم` : null} />
                ))}
            </div>

            <Collection summary={summary} branchRows={branchRows} />

            {branchRows.length > 1 && (
                <section className="rise-in mb-6 overflow-x-auto rounded-panel border border-gray-100 bg-surface shadow-card">
                    <h3 className="px-5 pt-5 text-lg font-bold text-gray-900">ملخص الفروع</h3>
                    <table className="mt-3 w-full text-sm" aria-label="ملخص الفروع">
                        <thead className="bg-gray-50 text-gray-600">
                            <tr>
                                <th className="px-3 py-2 text-start font-semibold">الفرع</th>
                                <th className="px-3 py-2 text-start font-semibold">الفترة</th>
                                <th className="px-3 py-2 text-start font-semibold">المشتركون</th>
                                {MONEY_COLUMNS.map(([key, label]) => <th key={key} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {branchRows.map((branch) => (
                                <tr key={branch.id} className="cursor-pointer hover:bg-gray-50" onClick={() => apply({ branch_id: String(branch.id) })}>
                                    <td className="px-3 py-2 font-semibold text-brand-600 underline-offset-4 hover:underline">{branch.name}</td>
                                    <td className="px-3 py-2">{branch.period ? <span className="flex flex-col gap-1"><span dir="ltr">{branch.period.label}</span><StatusPill tone={PERIOD_TONES[branch.period.status]} label={branch.period.statusLabel} /></span> : <span className="text-gray-400">لا توجد فترة</span>}</td>
                                    <td className="px-3 py-2 tabular-nums">{formatNumber(branch.count)}</td>
                                    {MONEY_COLUMNS.map(([key]) => <td key={key} className="px-3 py-2"><Money value={branch[key]} strong={key === 'remaining'} /></td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}

            <section className="rise-in overflow-x-auto rounded-panel border border-gray-100 bg-surface shadow-card">
                <table className="w-full text-sm" aria-label="تفاصيل المشتركين">
                    <thead className="bg-gray-50 text-gray-600">
                        <tr>
                            {SUBSCRIBER_COLUMNS.map((label) => <th key={label} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.data.length === 0 && <tr><td colSpan={SUBSCRIBER_COLUMNS.length} className="px-3 py-12 text-center text-gray-500">لا يوجد مشتركون مطابقون في هذه الفترة.</td></tr>}
                        {rows.data.map((row) => (
                            <tr key={`${row.periodId}-${row.subscriptionId}`}>
                                <td className="px-3 py-2 tabular-nums" dir="ltr">{row.accountNumber}</td>
                                <td className="px-3 py-2 font-semibold text-gray-900">{row.name}</td>
                                <td className="px-3 py-2 text-gray-600">{row.branchName}</td>
                                <td className="px-3 py-2"><Reading value={row.previousReading} /></td>
                                <td className="px-3 py-2"><Reading value={row.currentReading} /></td>
                                <td className="px-3 py-2"><Reading value={row.consumption} /></td>
                                <td className="px-3 py-2">{row.unitPrice ? <Money value={row.unitPrice} /> : <span className="text-gray-300">—</span>}</td>
                                {MONEY_COLUMNS.map(([key]) => <td key={key} className="px-3 py-2"><Money value={row[key]} strong={key === 'remaining'} /></td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="p-4">
                    <Pagination meta={rows} filters={filters} baseUrl="/reports/remaining" />
                </div>
            </section>

            {rows.data.length > 0 && <Settlement key={`${filters.date}-${rows.current_page}`} rows={rows.data} date={filters.date} />}
        </AuthenticatedLayout>
    );
}
