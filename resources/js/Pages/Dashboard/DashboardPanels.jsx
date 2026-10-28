import { Link } from '@inertiajs/react';
import BarChart from '@/Components/Charts/BarChart';
import MeterBar from '@/Components/Charts/MeterBar';
import Pagination from '@/Components/DataTable/Pagination';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import StatusPill from '@/Components/DataTable/StatusPill';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import KpiTile from '@/Components/KpiTile';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { formatMoney, formatNumber, formatShortDay, percentOf, timeAgo } from '@/lib/format';
import { formatWeekDay } from '@/lib/weekDays';
import { shouldPrefetchPage } from '@/lib/navigation';
import { ChangeBadge, OpenLink, Panel, READING_PARTS, SplitBar } from './DashboardParts';

/** The hero tile: what was collected in the period, cash against transfers, against the period before, and today so far. */
export function CollectionsTile({ collections, period }) {
    return (
        <KpiTile
            hero
            label={`التحصيل · آخر ${period}`}
            value={formatMoney(collections.total)}
            unit="شيكل"
            badge={<ChangeBadge change={collections.change} />}
            hint={`${formatNumber(collections.count)} دفعة · الفترة السابقة ${formatMoney(collections.previousTotal)} ₪`}
        >
            <div className="mt-5 flex h-2 overflow-hidden rounded-full bg-white/10" role="img" aria-label="النقد والحوالات">
                {collections.total > 0 && (
                    <>
                        <span className="bg-white/90" style={{ width: `${(collections.cash / collections.total) * 100}%` }} />
                        <span
                            className="bg-brand-400"
                            style={{ width: `${(collections.transfers / collections.total) * 100}%` }}
                        />
                    </>
                )}
            </div>
            <p className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/70">
                <span className="inline-flex items-center gap-1.5">
                    <i className="h-2 w-2 rounded-sm bg-white/90" aria-hidden="true" />
                    نقدي <b className="font-display text-white">{formatMoney(collections.cash)} ₪</b>
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <i className="h-2 w-2 rounded-sm bg-brand-400" aria-hidden="true" />
                    حوالات <b className="font-display text-white">{formatMoney(collections.transfers)} ₪</b>
                </span>
            </p>
            <p className="mb-2 mt-5 flex flex-wrap items-baseline gap-x-2 rounded-control border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/75">
                <Icon name="clock" className="h-4 w-4 shrink-0 self-center text-white/60" />
                اليوم حتى الآن
                <b className="font-display text-lg text-white">{formatMoney(collections.today.total)} ₪</b>
                <span className="text-xs text-white/60">· {formatNumber(collections.today.count)} دفعة</span>
            </p>
        </KpiTile>
    );
}

/** The graphite card of quick actions: the main one across the top, the rest two by two. */
export function QuickActions({ quickActions, lastActionSpans }) {
    return (
        <section className="rise-in relative overflow-hidden rounded-panel bg-graphite-gradient p-5 text-white shadow-lift sm:p-6">
            <div
                className="pointer-events-none absolute -bottom-24 -start-16 h-60 w-60 rounded-full bg-brand-500/25 blur-3xl"
                aria-hidden="true"
            />
            <h3 className="relative font-bold">إجراءات سريعة</h3>
            <div className="relative mt-4 grid grid-cols-2 gap-2.5">
                {quickActions.map((action, index) => {
                    const className = `quick-action flex items-center gap-3 rounded-row border px-3.5 py-3 text-start transition active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white ${
                        action.main
                            ? 'col-span-2 border-transparent bg-brand-gradient py-4 shadow-glow hover:brightness-110'
                            : 'border-white/10 bg-white/5 hover:bg-white/10'
                    } ${lastActionSpans && index === quickActions.length - 1 ? 'col-span-2' : ''}`;
                    const content = (
                        <>
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10">
                                <Icon name={action.icon} className="h-[18px] w-[18px]" />
                            </span>
                            <span className="min-w-0">
                                <b className="quick-action-title block text-sm">{action.title}</b>
                                <span className="quick-action-subtitle block text-xs text-white/60">{action.subtitle}</span>
                            </span>
                        </>
                    );

                    return action.onClick ? (
                        <button key={action.href} type="button" onClick={action.onClick} aria-haspopup="dialog" className={className}>
                            {content}
                        </button>
                    ) : (
                        <Link key={action.href} href={action.href} prefetch={shouldPrefetchPage(action.href)} className={className}>
                            {content}
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

/** What was collected each day of the period, as a chart or a table. */
export function CollectionTrendPanel({ collections, period, chartView, onChartViewChange }) {
    return (
        <Panel
            icon="chart"
            title="حركة التحصيل"
            info={`ما حُصّل كل يوم في آخر ${period}، واليوم آخرها.`}
            className="flex flex-col"
            action={
                <SegmentedTabs
                    label="طريقة العرض"
                    value={chartView}
                    onChange={onChartViewChange}
                    options={[
                        { value: 'chart', label: 'رسم' },
                        { value: 'table', label: 'جدول' },
                    ]}
                />
            }
        >
            <div className="flex flex-1 flex-col justify-end">
                {chartView === 'chart' ? (
                    <BarChart
                        data={collections.series}
                        label={`التحصيل اليومي في آخر ${period}`}
                        formatValue={(value) => `${formatMoney(value)} ₪`}
                        height={230}
                    />
                ) : (
                    <div className="max-h-[270px] overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-surface text-xs text-gray-500">
                                <tr>
                                    <th className="py-2 text-start font-semibold">اليوم</th>
                                    <th className="py-2 text-end font-semibold">المحصّل</th>
                                    <th className="py-2 text-end font-semibold">المقابل في الفترة السابقة</th>
                                </tr>
                            </thead>
                            <tbody>
                                {[...collections.series].reverse().map((day) => (
                                    <tr key={day.date} className="border-t border-gray-100">
                                        <td className="py-2 text-gray-700">{formatShortDay(day.date)}</td>
                                        <td className="py-2 text-end font-display font-semibold text-gray-900">
                                            {formatMoney(day.value)} ₪
                                        </td>
                                        <td className="py-2 text-end font-display text-gray-500">
                                            {formatMoney(day.previous)} ₪
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </Panel>
    );
}

/** The five subscribers who owe the most, each opening their statement for those who may. */
export function TopDebtorsPanel({ debts, can, onOpenStatement }) {
    return (
        <Panel
            icon="clock"
            title="أكبر المدينين"
            info="المشتركون الخمسة الأعلى دينًا، وعمر أقدم دين على كل منهم."
            action={<OpenLink href="/receivables" />}
        >
            {debts.top.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-500">لا ديون مستحقة.</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {debts.top.map((debtor) => (
                        <li
                            key={debtor.subscriptionId}
                            className={`relative flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0 ${can?.viewSubscriptions ? 'dashboard-row-link' : ''}`}
                        >
                            {can?.viewSubscriptions ? (
                                <button
                                    type="button"
                                    onClick={() => onOpenStatement(debtor)}
                                    aria-haspopup="dialog"
                                    aria-label={`كشف حساب ${debtor.name}`}
                                    className="min-w-0 text-start after:absolute after:inset-0 after:content-[''] focus-visible:outline-none"
                                >
                                    <RowIdentity name={debtor.name} subtitle={debtor.accountNumber} subtitleDir="ltr" />
                                </button>
                            ) : (
                                <RowIdentity name={debtor.name} subtitle={debtor.accountNumber} subtitleDir="ltr" />
                            )}
                            <span className="shrink-0 text-end">
                                <b className="block font-display text-sm text-gray-900">
                                    {formatMoney(debtor.balance)} ₪
                                </b>
                                <span
                                    className={`text-xs ${debtor.oldestDays > 90 ? 'text-brand-600' : 'text-gray-500'}`}
                                >
                                    أقدم دين منذ {formatNumber(debtor.oldestDays)} يومًا
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** Active subscribers by the ampere of their circuit breaker, the most first. */
export function BreakersPanel({ subscriptions, breakerMax }) {
    return (
        <Panel
            icon="bolt"
            title="المشتركون حسب القاطع"
            info="المشتركون النشطون حسب أمبير قاطعهم، الأكثر أولًا."
            className="flex flex-col"
        >
            <ul className="flex flex-1 flex-col justify-between gap-3">
                {subscriptions.byBreaker.map((breaker) => (
                    <li
                        key={breaker.ampere}
                        className="grid grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 text-sm"
                    >
                        <span className="font-semibold text-gray-700">
                            <span className="font-display">{breaker.ampere}</span> أمبير
                        </span>
                        <MeterBar value={breaker.count} max={breakerMax} />
                        <span className="font-display text-gray-900">
                            {formatNumber(breaker.count)} <span className="text-xs text-gray-400">{breaker.share}%</span>
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** What each branch (or, inside one branch, each employee) collected in the period. */
export function CollectionRankPanel({ collections, rankList, rankMax, period }) {
    return (
        <Panel
            icon={collections.byBranch ? 'pin' : 'users'}
            title={collections.byBranch ? 'التحصيل حسب الفرع' : 'التحصيل حسب الموظف'}
            info={
                collections.byBranch
                    ? `ما حصّله كل فرع في آخر ${period}. اختر فرعًا من الأعلى لترى لوحته وحده.`
                    : `ما سجّله كل موظف من دفعات في آخر ${period}.`
            }
        >
            {rankList.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-500">لا دفعات في هذه الفترة.</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {rankList.map((item) => (
                        <li key={item.id ?? item.name} className="py-3 first:pt-0 last:pb-0">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="min-w-0 truncate text-sm font-semibold text-gray-900">
                                    {item.name}
                                    <span className="ms-2 text-xs font-normal text-gray-400">
                                        {item.governorate ?? `${formatNumber(item.count)} دفعة`}
                                    </span>
                                </span>
                                <span className="shrink-0 font-display text-sm font-bold text-gray-900">
                                    {formatMoney(item.total)} ₪{' '}
                                    <span className="text-xs font-normal text-gray-400">{item.share}%</span>
                                </span>
                            </div>
                            <MeterBar value={item.total} max={rankMax} className="mt-2" />
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** The reading week's readings: approved, waiting for approval and not read. */
export function WeekReadingsPanel({ readings, can }) {
    return (
        <Panel
            icon="gauge"
            title="قراءات الأسبوع"
            className="flex flex-col"
            info={
                readings.week
                    ? `أسبوع القراءة الذي بدأ ${formatWeekDay(readings.week)}، للمشتركين النشطين.`
                    : 'آخر أسبوع قراءة انتهى في كل فرع، للمشتركين النشطين.'
            }
            action={can?.viewMeterReadings && <OpenLink href="/meter-readings" />}
        >
            <SplitBar label="قراءات الأسبوع" parts={READING_PARTS(readings)} className="" />
            <ul className="mt-4 flex flex-1 flex-col divide-y divide-gray-100">
                {[
                    ['معتمدة', readings.approved, 'green'],
                    ['بانتظار الاعتماد', readings.pending, 'amber'],
                    ['لم تُقرأ', readings.missing, 'red'],
                ].map(([label, value, tone]) => (
                    <li key={label} className="flex flex-1 items-center justify-between gap-3 py-2.5 last:pb-0">
                        <StatusPill tone={tone} label={label} />
                        <span className="flex items-baseline gap-2 font-display text-sm">
                            <b className="text-gray-900">{formatNumber(value)}</b>
                            <span className="text-xs text-gray-400">{percentOf(value, readings.total)}%</span>
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** The latest payments of the period: searched, filtered by how they were paid, a page at a time, with their receipts. */
export function LatestPayments({ payments, latestPayments, period, search, onSearchChange, onVisit, filters, scope, can, canOpenReceipts, onOpenStatement }) {
    return (
        <section aria-label="أحدث الدفعات" className="rise-in">
            <div className="mb-3 flex items-center gap-1">
                <h3 className="text-lg font-bold text-gray-900">أحدث الدفعات</h3>
                <InfoTip label="عن أحدث الدفعات">{`الدفعات المسجلة في آخر ${period}، الأحدث أولًا. المجموع لا يشمل الملغاة.`}</InfoTip>
            </div>
            <div className="data-table-toolbar flex flex-wrap items-center justify-between gap-3">
                <form
                    className="relative w-full sm:max-w-sm"
                    role="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onVisit({ search: search.trim() || null }, ['latestPayments', 'filters']);
                    }}
                >
                    <Icon
                        name="search"
                        className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400"
                    />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => onSearchChange(event.target.value)}
                        placeholder="اسم المشترك أو رقم الحساب"
                        aria-label="ابحث في الدفعات"
                        className="block w-full py-2.5 ps-10 text-sm"
                    />
                </form>
                <SegmentedTabs
                    label="طريقة الدفع"
                    value={filters.method ?? ''}
                    onChange={(method) => onVisit({ method: method || null }, ['latestPayments', 'filters'])}
                    options={[
                        { value: '', label: 'الكل' },
                        { value: 'cash', label: 'نقدي' },
                        { value: 'transfer', label: 'حوالات' },
                    ]}
                />
            </div>
            <div className="data-table-container">
                <table className="data-table w-full text-start text-sm">
                    <thead>
                        <tr>
                            <th>المشترك</th>
                            {scope.company && <th>الفرع</th>}
                            <th>سجّلها</th>
                            <th>الطريقة</th>
                            <th>المبلغ</th>
                            <th>الوقت</th>
                            <th>الحالة</th>
                            {canOpenReceipts && <th className="text-center">الوصل</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {payments.data.length === 0 ? (
                            <tr>
                                <td colSpan={8} className="py-10 text-center text-sm text-gray-500">
                                    لا دفعات في هذه الفترة.
                                </td>
                            </tr>
                        ) : (
                            payments.data.map((payment) => (
                                <tr key={payment.id}>
                                    <td>
                                        {can?.viewSubscriptions ? (
                                            <button
                                                type="button"
                                                onClick={() => onOpenStatement(payment)}
                                                aria-haspopup="dialog"
                                                title="كشف الحساب"
                                                className="dashboard-name-link -m-1.5 rounded-xl p-1.5 text-start transition hover:bg-gray-50 active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                                            >
                                                <RowIdentity name={payment.subscriber} subtitle={payment.accountNumber} subtitleDir="ltr" />
                                            </button>
                                        ) : (
                                            <RowIdentity name={payment.subscriber} subtitle={payment.accountNumber} subtitleDir="ltr" />
                                        )}
                                    </td>
                                    {scope.company && <td className="text-gray-600">{payment.branchName ?? '—'}</td>}
                                    <td className="text-gray-600">{payment.recordedBy ?? '—'}</td>
                                    <td className="text-gray-600">
                                        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                                            <Icon name={payment.isCash ? 'banknotes' : 'bank'} className="h-4 w-4 text-gray-400" />
                                            {payment.isCash ? 'نقدي' : `حوالة${payment.bankName ? ` · ${payment.bankName}` : ''}`}
                                        </span>
                                    </td>
                                    <td
                                        className={`whitespace-nowrap font-display font-bold ${payment.cancelled ? 'text-gray-400 line-through' : 'text-gray-900'}`}
                                    >
                                        {formatMoney(payment.amount)} ₪
                                    </td>
                                    <td
                                        className="whitespace-nowrap text-gray-500"
                                        title={new Date(payment.recordedAt).toLocaleString('ar-SY-u-nu-latn')}
                                    >
                                        {timeAgo(payment.recordedAt)}
                                    </td>
                                    <td>
                                        <StatusPill
                                            tone={payment.cancelled ? 'gray' : 'green'}
                                            label={payment.cancelled ? 'ملغاة' : 'سارية'}
                                        />
                                    </td>
                                    {canOpenReceipts && (
                                        <td className="text-center">
                                            <a
                                                href={`/subscriptions/${payment.subscriptionId}/payments/${payment.id}/receipt`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="طباعة الوصل"
                                                aria-label={`طباعة وصل ${payment.subscriber}`}
                                                className="row-action row-action-print mx-auto"
                                            >
                                                <Icon name="printer" className="h-4 w-4" />
                                            </a>
                                        </td>
                                    )}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            {payments.total > 0 && (
                <div className="data-table-totals flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    مجموع الدفعات السارية
                    <b className="font-display text-gray-900">{formatMoney(latestPayments.sum)} ₪</b>
                </div>
            )}
            <Pagination meta={payments} onPageChange={(page) => onVisit({ page }, ['latestPayments'])} />
        </section>
    );
}
