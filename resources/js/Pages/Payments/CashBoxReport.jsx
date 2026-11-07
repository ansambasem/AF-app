import BankReceivedDate from '@/Components/BankReceivedDate';
import TableExportActions from '@/Components/DataTable/TableExportActions';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { reportColumns, reportCurrency, reportFilters, reportMoney, reportRowTotals, reportDetails, reportDateTime } from '@/lib/cashBoxReport';
import PageHeader from '@/Components/PageHeader';

export default function CashBoxReport({ report }) {
    const details = reportDetails(report);
    const summary = Object.entries(report.totals).map(([currency, total]) => [
        `الصندوق: ${reportMoney(total.amount, currency)}`,
        ...(report.isBank ? [`وصل البنك: ${reportMoney(total.bankAmount, currency)}`, `الفرق: ${reportMoney(total.difference, currency)}`, `غير مدقّقة: ${total.unknown}`] : []),
    ].join(' · ')).join(' | ');

    return <AuthenticatedLayout header={<PageHeader title="تقرير تفاصيل الصندوق" />}>
        <Head title="تقرير تفاصيل الصندوق" />
        <section data-excel-source="custom" className="space-y-5 rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Link href="/payments/cash-boxes" className="text-sm font-semibold text-brand-600">العودة إلى الصناديق</Link>
                <TableExportActions title="تقرير تفاصيل الصندوق" />
            </div>
            <div className="flex flex-wrap gap-2" aria-label="تصفية الحركات">
                {Object.entries(reportFilters).map(([key, label]) => <Link key={key} href={`/payments/cash-boxes/report?box=${report.boxId ?? ''}&filter=${key}`} className={`rounded-control border px-3 py-2 text-sm ${key === report.filter ? 'bg-gray-900 text-white' : 'text-gray-600'}`}>{label} · {report.counts[key]}</Link>)}
            </div>
            {!report.boxId && <p className="text-sm text-gray-500">لا توجد صناديق متاحة. يمكنك تجهيز القالب الآن واستخدامه لاحقًا.</p>}
            <dl className="grid grid-cols-2 gap-4 rounded-card border border-gray-100 p-4 lg:grid-cols-3">
                {details.map((field) => <div key={field.key}><dt className="text-xs text-gray-500">{field.label}</dt><dd className="mt-1 font-semibold">{field.value}</dd></div>)}
            </dl>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead><tr>{reportColumns.map((label) => <th key={label} className="whitespace-nowrap bg-gray-50 p-3 text-start">{label}</th>)}</tr></thead>
                    <tbody>{report.rows.map((row) => <tr key={row.id} data-print-totals={JSON.stringify(reportRowTotals(row))} data-print-currency={reportCurrency[row.currency] ?? row.currency} className="border-b border-gray-100">
                        <td className="p-3">{row.subscriber ?? '—'}</td>
                        <td className="p-3">{reportDateTime(row.date)}<BankReceivedDate value={row.bankReceivedOn} />{row.paidOn && <div className="mt-1 whitespace-normal text-xs leading-5">تسجيل متأخر · الدفع الفعلي: <bdi dir="ltr">{row.paidOn}</bdi><span className="block">سبب التأخير: {row.lateReason}</span></div>}</td>
                        <td className="p-3" dir="ltr">{row.reference ?? '—'}</td>
                        <td className="p-3">{reportMoney(row.amount, row.currency)}</td>
                        <td className="p-3">{report.isBank ? row.bankAmount == null ? 'لم تُدقّق' : Number(row.bankAmount) === 0 ? `${reportMoney(0, row.currency)} · لم يصل شيء` : reportMoney(row.bankAmount, row.currency) : 'لا ينطبق'}</td>
                        <td className="p-3">{reportMoney(row.difference, row.currency)}</td>
                        <td className={`p-3 ${row.status === 'غير موجودة' ? 'text-red-700' : ''}`}>{row.status}{row.bankAmount != null && !row.auditFinal ? ' · مبدئي' : ''}</td>
                        <td className="p-3">{row.reason || '—'}</td>
                    </tr>)}</tbody>
                </table>
            </div>
            <p className="data-table-totals whitespace-pre-line text-sm font-semibold">{summary ? `${summary}\n` : ''}الإجماليات للحركات السارية المعروضة فقط، ولا تشمل المستردّة أو الملغاة.{report.isBank ? ' مبالغ البنك والفرق للحركات المدقّقة فقط. قرارات التدقيق غير المعتمدة مبدئية.' : ''}</p>
        </section>
    </AuthenticatedLayout>;
}
