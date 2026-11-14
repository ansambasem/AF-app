import { reportMoney, shortDate } from '@/lib/reportFormatting';
import { weekDayName } from '@/lib/weekDays';

const CELL = 'border border-gray-300 px-2 py-1.5 text-start';
const MONEY = 'border border-gray-300 px-2 py-1.5 text-end font-display tabular-nums';
const HEAD = 'border border-gray-300 bg-gray-100 px-2 py-1.5 text-start text-[11px] font-bold';

/**
 * The reports page on paper, and only that: the branch and the period with
 * its dates, then the financial summaries — the figures, how the balances
 * moved, where the payments came in and (for every branch) the branches
 * side by side. Hidden on screen; printing the page prints this sheet
 * alone, without the site's menus, buttons or the lists of lines.
 */
export default function ReportPrintSheet({ scopeName, periodName, from, to, flow, collections, branchSummary, preparedBy }) {
    const change = Number(flow.change);
    const dates = from === to ? `${weekDayName(from)} ${shortDate(from, true)}` : `من ${weekDayName(from)} ${shortDate(from, true)} إلى ${weekDayName(to)} ${shortDate(to, true)}`;
    const printedAt = new Date().toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' });

    return (
        <article className="report-print text-[12px] leading-5 text-gray-900" dir="rtl">
            <header className="mb-4 flex items-end justify-between gap-6 border-b-2 border-gray-900 pb-3">
                <div>
                    <p className="text-[11px] font-semibold text-gray-600">التقرير المالي · {periodName}</p>
                    <h1 className="mt-0.5 text-[22px] font-bold">{scopeName}</h1>
                    <p className="mt-1 font-semibold">{dates}</p>
                </div>
                <dl className="text-end text-[10px] text-gray-600">
                    <div>طُبع في <span className="font-display">{printedAt}</span></div>
                    {preparedBy && <div>بواسطة {preparedBy}</div>}
                </dl>
            </header>

            <section className="mb-4 grid grid-cols-5 gap-2">
                {[
                    ['الرصيد الافتتاحي', flow.opening],
                    ['التحميلات', flow.chargesTotal],
                    ['إجمالي المحصّل', collections.total, `نقدي ${reportMoney(collections.cash)} · تحويلات وشيكات ${reportMoney(collections.nonCash)}`],
                    ['الرصيد الختامي', flow.closing, change > 0 ? `زاد ${reportMoney(change)}` : change < 0 ? `نقص ${reportMoney(-change)}` : 'لم يتغير'],
                    ['عدد الدفعات', null, null, collections.count],
                ].map(([label, amount, note, count]) => (
                    <div key={label} className="rounded border border-gray-300 px-2 py-1.5">
                        <p className="text-[10px] text-gray-600">{label}</p>
                        <p className="font-display text-[15px] font-bold">{amount !== null ? `${reportMoney(amount)} ₪` : count}</p>
                        {note && <p className="text-[9.5px] text-gray-600">{note}</p>}
                    </div>
                ))}
            </section>

            <section className="report-print-block mb-4">
                <h2 className="mb-1.5 text-[13px] font-bold">حركة الأرصدة</h2>
                <table className="w-full border-collapse">
                    <thead>
                        <tr><th className={HEAD}>البند</th><th className={`${HEAD} w-20 text-end`}>العدد</th><th className={`${HEAD} w-32 text-end`}>المبلغ ₪</th></tr>
                    </thead>
                    <tbody>
                        <tr className="font-bold"><td className={CELL}>الرصيد الافتتاحي</td><td className={CELL} /><td className={MONEY}>{reportMoney(flow.opening)}</td></tr>
                        {flow.charges.map((line) => (
                            <tr key={line.type}><td className={CELL}>+ {line.label}</td><td className={MONEY}>{line.count}</td><td className={MONEY}>{reportMoney(line.total)}</td></tr>
                        ))}
                        <tr className="font-semibold"><td className={CELL}>مجموع التحميلات (عليه)</td><td className={CELL} /><td className={MONEY}>{reportMoney(flow.chargesTotal)}</td></tr>
                        {flow.credits.map((line) => (
                            <tr key={line.type}><td className={CELL}>− {line.label}</td><td className={MONEY}>{line.count}</td><td className={MONEY}>{reportMoney(line.total)}</td></tr>
                        ))}
                        <tr className="font-semibold"><td className={CELL}>مجموع الدفعات والخصومات (له)</td><td className={CELL} /><td className={MONEY}>{reportMoney(flow.creditsTotal)}</td></tr>
                        <tr><td className={CELL}>± الإلغاءات والقيود العكسية</td><td className={MONEY}>{flow.corrections.count}</td><td className={MONEY}>{reportMoney(flow.corrections.total)}</td></tr>
                        <tr className="bg-gray-100 font-bold"><td className={CELL}>الرصيد الختامي</td><td className={CELL} /><td className={MONEY}>{reportMoney(flow.closing)}</td></tr>
                    </tbody>
                </table>
            </section>

            {collections.count > 0 && (
                <section className="report-print-block mb-4 grid grid-cols-2 gap-4">
                    <div>
                        <h2 className="mb-1.5 text-[13px] font-bold">التحصيل حسب الحساب</h2>
                        <table className="w-full border-collapse">
                            <thead>
                                <tr><th className={HEAD}>الحساب</th><th className={`${HEAD} text-end`}>الدفعات</th><th className={`${HEAD} text-end`}>المبلغ ₪</th></tr>
                            </thead>
                            <tbody>
                                {collections.accounts.map((account) => (
                                    <tr key={account.key}><td className={CELL}>{account.label}</td><td className={MONEY}>{account.count}</td><td className={MONEY}>{reportMoney(account.total)}</td></tr>
                                ))}
                                <tr className="bg-gray-100 font-bold"><td className={CELL}>المجموع</td><td className={MONEY}>{collections.count}</td><td className={MONEY}>{reportMoney(collections.total)}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <h2 className="mb-1.5 text-[13px] font-bold">التحصيل حسب المحصّل</h2>
                        <table className="w-full border-collapse">
                            <thead>
                                <tr><th className={HEAD}>الموظف</th><th className={`${HEAD} text-end`}>الدفعات</th><th className={`${HEAD} text-end`}>نقدي</th><th className={`${HEAD} text-end`}>المجموع ₪</th></tr>
                            </thead>
                            <tbody>
                                {collections.collectors.map((collector) => (
                                    <tr key={collector.name}><td className={CELL}>{collector.name}</td><td className={MONEY}>{collector.count}</td><td className={MONEY}>{reportMoney(collector.cash)}</td><td className={MONEY}>{reportMoney(collector.total)}</td></tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {branchSummary.length > 0 && (
                <section>
                    <h2 className="mb-1.5 text-[13px] font-bold">مقارنة الفروع</h2>
                    <table className="w-full border-collapse">
                        <thead>
                            <tr><th className={HEAD}>الفرع</th><th className={`${HEAD} text-end`}>إجمالي المحصّل</th><th className={`${HEAD} text-end`}>نقدي</th><th className={`${HEAD} text-end`}>الدفعات</th><th className={`${HEAD} text-end`}>الرصيد الختامي</th></tr>
                        </thead>
                        <tbody>
                            {[...branchSummary].sort((first, second) => Number(second.collections.total) - Number(first.collections.total)).map((row) => (
                                <tr key={row.id}><td className={CELL}>{row.name}</td><td className={MONEY}>{reportMoney(row.collections.total)}</td><td className={MONEY}>{reportMoney(row.collections.cash)}</td><td className={MONEY}>{row.collections.count}</td><td className={MONEY}>{reportMoney(row.flow.closing)}</td></tr>
                            ))}
                            <tr className="bg-gray-100 font-bold">
                                <td className={CELL}>المجموع</td>
                                <td className={MONEY}>{reportMoney(collections.total)}</td>
                                <td className={MONEY}>{reportMoney(collections.cash)}</td>
                                <td className={MONEY}>{collections.count}</td>
                                <td className={MONEY}>{reportMoney(flow.closing)}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            )}
        </article>
    );
}
