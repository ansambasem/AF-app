import { Link, usePoll } from '@inertiajs/react';
import KpiTile from '@/Components/KpiTile';
import StatRing from '@/Components/StatRing';
import MeterBar from '@/Components/Charts/MeterBar';
import { formatMoney, formatNumber } from '@/lib/format';
import { COLLECTION_INDICATORS, COLLECTION_RATES, formatRate } from '@/lib/remainingAmounts';

/** The nine figures of the open period, in the order of the accounts formula. */
const CARDS = [
    ['invoice', 'إجمالي الفواتير'],
    ['accumulated', 'إجمالي المتراكم'],
    ['advance', 'إجمالي المقدم'],
    ['net', 'صافي المتراكم'],
    ['discounts', 'إجمالي الخصومات'],
    ['charges', 'إجمالي المبالغ المحملة'],
    ['beforePayments', 'الرصيد قبل الدفعات'],
    ['payments', 'الدفعات المحصّلة'],
    ['remaining', 'المبالغ المتبقية'],
];

function Count({ label, value, tone = 'text-gray-900' }) {
    return (
        <div className="rounded-panel border border-gray-100 bg-surface p-5 shadow-card">
            <p className="text-sm font-semibold text-gray-600">{label}</p>
            <p className={`mt-2 font-display text-3xl font-bold tabular-nums ${tone}`}>{formatNumber(value)}</p>
        </div>
    );
}

/**
 * The open billing period at the top of the dashboard: its nine totals,
 * its collection (paid, invoice settled, accumulated collected, new
 * advance) and the three rates, the subscriptions active, disconnected,
 * owing and ahead, what was due
 * against what was paid, each branch's collections and the five who owe
 * most. It reloads itself every minute, and with the page after any
 * payment, discount, charge or reading saved from it.
 */
export default function PeriodAccounts({ accounts }) {
    usePoll(60000, { only: ['periodAccounts'] });

    const { totals, counts, byBranch, top, periods } = accounts;
    const due = Number(totals.collectible);
    const paid = Number(totals.payments);
    const branchMax = Math.max(1, ...(byBranch ?? []).map((branch) => Number(branch.due)));
    const periodLine = periods.length === 1 ? periods[0].label : `${formatNumber(periods.length)} فروع · كل فرع في فترته المفتوحة`;

    return (
        <section aria-label="الفترة المفتوحة" className="mb-8 space-y-5">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="text-xl font-bold text-gray-900">الفترة المفتوحة <span className="text-base font-semibold text-gray-500" dir="ltr">{periodLine}</span></h3>
                <Link href="/reports/remaining" className="text-sm font-semibold text-brand-600 hover:underline">تقرير المبالغ المتبقية</Link>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                {CARDS.map(([key, label]) => (
                    <KpiTile key={key} compact hero={key === 'remaining'} label={label} value={formatMoney(totals[key])} unit="₪" />
                ))}
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {COLLECTION_INDICATORS.map(([key, label, hint]) => <KpiTile key={key} compact label={label} value={formatMoney(totals[key])} unit="₪" hint={hint} />)}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                {COLLECTION_RATES.map(([key, label, hint]) => (
                    <div key={key} className="flex items-center gap-4 rounded-panel border border-gray-100 bg-surface p-5 shadow-card">
                        <StatRing percent={totals[key] ?? 0} size={72} />
                        <div className="min-w-0">
                            <h4 className="font-bold text-gray-900">{label} <span className="tabular-nums" dir="ltr">{formatRate(totals[key])}</span></h4>
                            <p className="mt-1 text-xs leading-5 text-gray-500">{totals[key] === null ? 'لا مستحقات بعد.' : hint}</p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Count label="المشتركون الفعّالون" value={counts.active} />
                <Count label="المشتركون المفصولون" value={counts.disconnected} tone="text-gray-500" />
                <Count label="عليهم مبالغ متبقية" value={counts.owing} tone="text-brand-600" />
                <Count label="أصحاب المقدم" value={counts.ahead} tone="text-emerald-700 dark:text-emerald-400" />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
                    <h4 className="font-bold text-gray-900">المستحقات والتحصيل</h4>
                    <dl className="mt-4 space-y-4 text-sm">
                        <div>
                            <dt className="flex justify-between"><span className="text-gray-600">المستحق دفعه في الفترة</span><b className="tabular-nums">{formatMoney(due)} ₪</b></dt>
                            <dd className="mt-1.5"><MeterBar value={due} max={Math.max(due, paid, 1)} /></dd>
                        </div>
                        <div>
                            <dt className="flex justify-between"><span className="text-gray-600">المحصّل</span><b className="tabular-nums">{formatMoney(paid)} ₪</b></dt>
                            <dd className="mt-1.5"><MeterBar value={paid} max={Math.max(due, paid, 1)} /></dd>
                        </div>
                    </dl>
                </div>

                <div className="rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
                    <h4 className="font-bold text-gray-900">أعلى 5 مبالغ متبقية</h4>
                    {top.length === 0 ? <p className="mt-4 text-sm text-gray-500">لا مبالغ متبقية.</p> : (
                        <ol className="mt-3 space-y-2 text-sm">
                            {top.map((row) => (
                                <li key={row.subscriptionId} className="flex items-baseline justify-between gap-3">
                                    <span className="min-w-0 truncate"><span className="font-semibold text-gray-900">{row.name}</span> <span className="text-xs text-gray-500" dir="ltr">{row.accountNumber}</span></span>
                                    <b className="shrink-0 tabular-nums text-brand-600">{formatMoney(row.remaining)} ₪</b>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>

            {byBranch && (
                <div className="rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
                    <h4 className="font-bold text-gray-900">التحصيل بين الفروع</h4>
                    <ul className="mt-4 grid gap-x-8 gap-y-4 md:grid-cols-2">
                        {byBranch.map((branch) => (
                            <li key={branch.id}>
                                <span className="flex items-baseline justify-between gap-3 text-sm">
                                    <span className="font-semibold text-gray-900">{branch.name}</span>
                                    <span className="tabular-nums text-gray-600">{formatMoney(branch.payments)} من {formatMoney(branch.due)} ₪ · <span dir="ltr">{formatRate(branch.totalRate)}</span></span>
                                </span>
                                <MeterBar value={Number(branch.payments)} max={branchMax} className="mt-1.5" />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
