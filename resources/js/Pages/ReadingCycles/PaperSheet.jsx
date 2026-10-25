import { Fragment } from 'react';
import { Head, Link, useHttp } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import { formatNumber } from '@/lib/format';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import PageHeader from '@/Components/PageHeader';
import PrintButton from '@/Components/PrintButton';

/**
 * A paper reading sheet as printed: its number and cycle, and for each box
 * its subscribers with the reading the week starts from and an empty box for
 * the new one. Each printing is counted on the server first.
 */
export default function PaperSheet({ sheet, cycle, boxes }) {
    const printing = useHttp();
    const beforePrint = () => printing.post(`/paper-sheets/${sheet.id}/printed`);
    const grouped = new Map();
    for (const box of boxes) {
        const area = box.subAreaName ?? 'بدون منطقة 2';
        if (!grouped.has(area)) grouped.set(area, []);
        grouped.get(area).push(box);
    }
    const groups = [...grouped.entries()].sort(([first], [second]) => first.localeCompare(second, 'ar'));

    const total = boxes.reduce((count, box) => count + box.subscriptions.length, 0);

    return (
        <AuthenticatedLayout beforePrint={beforePrint}
            header={
                <>
                    <PageHeader
                        eyebrow={<><Link href="/reading-cycles" className="hover:underline">توزيع القراءات</Link> / <Link href={`/reading-cycles/${cycle.id}`} className="hover:underline">{cycle.readingDate}</Link></>}
                        title={<>كشف قراءة ورقي <span dir="ltr" className="font-display">{sheet.number}</span></>}
                        meta={sheet.printCount > 0 ? `طُبع ${formatNumber(sheet.printCount)} مرة، آخرها ${sheet.lastPrintedAt}` : 'لم يُطبع بعد'}
                    />
                    <div className="flex shrink-0 gap-2">
                        <Link href={`/meter-readings`} className={secondaryButtonClass}>
                            <Icon name="pencil" className="h-4 w-4" />
                            إدخال القراءات من الكشف
                        </Link>
                        <PrintButton title={`كشف قراءة العدادات — ${cycle.branchName}`} />
                    </div>
                </>
            }
        >
            <Head title={`كشف ${sheet.number}`} />

            <article className="paper-sheet rounded-card border border-gray-100 bg-white p-6 text-gray-900 shadow-card print:border-0 print:p-0 print:shadow-none">
                <header className="mb-4 flex flex-wrap justify-between gap-3 border-b border-gray-300 pb-3 text-sm">
                    <div>
                        <p className="text-lg font-bold">كشف قراءة العدادات — {cycle.branchName}</p>
                        <p>رقم الكشف: <b dir="ltr">{sheet.number}</b></p>
                    </div>
                    <div className="text-end">
                        <p>يوم القراءة: <b dir="ltr">{cycle.readingDate}</b></p>
                        <p>أسبوع: <span dir="ltr">{cycle.weekStart} → {cycle.weekEnd}</span></p>
                        <p>{formatNumber(boxes.length)} طبلون · {formatNumber(total)} مشترك</p>
                    </div>
                </header>

                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-sm">
                        <thead><tr className="bg-gray-100">
                            {['#', 'الطبلون', 'المشترك', 'رقم الاشتراك', 'القراءة السابقة', 'القراءة الحالية', 'ملاحظات'].map((label) => <th key={label} className="border border-gray-300 px-3 py-2 text-start">{label}</th>)}
                        </tr></thead>
                        {groups.map(([area, groupBoxes]) => <tbody key={area}>
                            <tr><td colSpan={7} className="border border-gray-300 bg-gray-100 px-3 py-3 text-base font-bold">منطقة 2: {area}</td></tr>
                            {groupBoxes.map((box) => <Fragment key={box.id}>
                                {box.movedOffPaper && <tr className="print:hidden"><td colSpan={7} className="border border-gray-300 px-3 py-2 text-red-700">الطبلون {box.label}: نُقل من الكشوف الورقية بعد إنشاء الكشف.</td></tr>}
                                {box.subscriptions.map((subscription, index) => <tr key={subscription.id} className="break-inside-avoid">
                                    <td className="border border-gray-300 px-3 py-3">{index + 1}</td>
                                    <td className="border border-gray-300 px-3 py-3">{box.label}</td>
                                    <td className="border border-gray-300 px-3 py-3"><span className="block font-semibold">{subscription.name}</span>{subscription.phone && <bdi dir="ltr" className="mt-1 block font-display text-xs">{subscription.phone}</bdi>}</td>
                                    <td className="border border-gray-300 px-3 py-3" dir="ltr">{subscription.accountNumber}</td>
                                    <td className="border border-gray-300 px-3 py-3 text-center font-display" dir="ltr">{subscription.previousReading}</td>
                                    <td className="min-w-32 border border-gray-300 px-3 py-3" />
                                    <td className="min-w-24 border border-gray-300 px-3 py-3" />
                                </tr>)}
                            </Fragment>)}
                        </tbody>)}
                    </table>
                </div>

                <footer className="mt-6 grid grid-cols-2 gap-6 text-sm">
                    <p>اسم القارئ: ............................................</p>
                    <p>التوقيع: ............................................</p>
                </footer>
            </article>
        </AuthenticatedLayout>
    );
}
