import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiTile from '@/Components/KpiTile';
import Pagination from '@/Components/DataTable/Pagination';
import StatusPill from '@/Components/DataTable/StatusPill';
import { formatMoney, formatNumber } from '@/lib/format';

/** The report's money columns, in the order of the accounts formula. */
export const MONEY_COLUMNS = [
    ['invoice', 'الفاتورة'],
    ['accumulated', 'المتراكم'],
    ['advance', 'المقدم'],
    ['net', 'صافي المتراكم'],
    ['discounts', 'الخصومات'],
    ['charges', 'المبالغ المحملة'],
    ['beforePayments', 'الرصيد قبل الدفعات'],
    ['payments', 'إجمالي الدفعات'],
    ['remaining', 'الرصيد المتبقي'],
];

const PERIOD_TONES = { open: 'green', closed: 'amber', approved: 'gray', scheduled: 'blue' };

function Money({ value, strong = false }) {
    const negative = Number(value) < 0;

    return <span className={`whitespace-nowrap tabular-nums ${strong ? 'font-bold' : ''} ${negative ? 'text-emerald-700 dark:text-emerald-400' : ''}`} dir="ltr">{formatMoney(value)}</span>;
}

function Reading({ value }) {
    return value === null || value === undefined ? <span className="text-gray-300">—</span> : <span className="tabular-nums" dir="ltr">{formatNumber(Number(value))}</span>;
}

/**
 * The remaining amounts report: for the chosen period (each branch's open
 * one by default), every subscription's reading, invoice, accumulated or
 * advance, discounts, charges, payments and what remains, with the totals
 * above and each branch's totals; a branch's row narrows the report to it.
 */
export default function RemainingAmounts({ filters, branches, periodOptions, summary, branchRows, rows }) {
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
                <label className="grid gap-1 text-sm font-semibold text-gray-700">
                    الفترة
                    <select className={select} value={filters.date} onChange={(event) => apply({ date: event.target.value })}>
                        <option value="">الفترة المفتوحة الحالية</option>
                        {periodOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                </label>
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
                            <th className="px-3 py-2 text-start font-semibold">رقم المشترك</th>
                            <th className="px-3 py-2 text-start font-semibold">اسم المشترك</th>
                            <th className="px-3 py-2 text-start font-semibold">الفرع</th>
                            <th className="px-3 py-2 text-start font-semibold">القراءة السابقة</th>
                            <th className="px-3 py-2 text-start font-semibold">القراءة الحالية</th>
                            <th className="px-3 py-2 text-start font-semibold">فرق القراءة</th>
                            <th className="px-3 py-2 text-start font-semibold">سعر الوحدة</th>
                            {MONEY_COLUMNS.map(([key, label]) => <th key={key} className="px-3 py-2 text-start font-semibold">{label}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.data.length === 0 && <tr><td colSpan={7 + MONEY_COLUMNS.length} className="px-3 py-12 text-center text-gray-500">لا يوجد مشتركون مطابقون في هذه الفترة.</td></tr>}
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
        </AuthenticatedLayout>
    );
}
