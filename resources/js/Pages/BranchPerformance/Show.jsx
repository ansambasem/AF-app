import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BarChart from '@/Components/Charts/BarChart';
import MeterBar from '@/Components/Charts/MeterBar';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import StatusPill from '@/Components/DataTable/StatusPill';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import { formatDayLabel, formatMoney, formatNumber, timeAgo } from '@/lib/format';
import { branchPlace } from './BranchMark';
import PageHeader, { BackLink } from '@/Components/PageHeader';

/** Status colors, fixed because the card behind them is always graphite; each comes with its word and count. */
const STATUS_BARS = { active: 'bg-emerald-400', suspended: 'bg-amber-400', disconnected: 'bg-white/40' };
const TIMELINE_DOTS = { active: 'bg-emerald-500', suspended: 'bg-amber-500', disconnected: 'bg-gray-400' };

function Shekels({ amount, className = 'text-gray-900' }) {
    return (
        <span className={`whitespace-nowrap ${className}`}>
            <b className="font-display font-bold">{formatMoney(amount)}</b> <span className="text-xs font-normal text-gray-500">₪</span>
        </span>
    );
}

/** The branch's subscriptions split by status: one bar, then each status in words. */
function StatusMix({ statusCounts }) {
    const shown = statusCounts.filter((status) => status.count > 0);

    return (
        <>
            {shown.length > 0 && (
                <div className="mt-5 flex h-2.5 gap-0.5" aria-hidden="true">
                    {shown.map((status) => (
                        <span key={status.value} className={`rounded-full ${STATUS_BARS[status.value]}`} style={{ flex: `${status.count} 1 0` }} />
                    ))}
                </div>
            )}
            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-white/70">
                {statusCounts.map((status) => (
                    <li key={status.value} className="inline-flex items-center gap-1.5">
                        <span className={`h-2 w-2 rounded-full ${STATUS_BARS[status.value]}`} aria-hidden="true" />
                        {status.label}
                        <b className="font-display text-sm text-white">{formatNumber(status.count)}</b>
                    </li>
                ))}
            </ul>
        </>
    );
}

function ChartCard({ title, caption, children }) {
    return (
        <section className="rise-in min-w-0 rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
            <div className="mb-7 flex items-baseline justify-between gap-3">
                <h3 className="text-lg font-bold text-gray-900">{title}</h3>
                <span className="text-xs text-gray-500">{caption}</span>
            </div>
            {children}
        </section>
    );
}

/** A table's title bar: it joins the tray under it into one card. */
function TableHeading({ title, caption }) {
    return (
        <div className="data-table-toolbar">
            <h3 className="text-lg font-bold text-gray-900">{title}</h3>
            <p className="mt-0.5 text-sm text-gray-500">{caption}</p>
        </div>
    );
}

/** Each member of staff: their entries against the busiest member's, the lines they recorded, and when they last worked. */
function TeamTable({ team }) {
    const busiest = Math.max(1, ...team.map((member) => member.entries));

    return (
        <section className="mt-5">
            <TableHeading title="فريق الفرع" caption={`${formatNumber(team.length)} موظف · مرتبون حسب عدد الإدخالات`} />
            <div className="data-table-container">
                <table className="data-table w-full text-start text-sm">
                    <thead>
                        <tr>
                            <th>الموظف</th>
                            <th>اسم المستخدم</th>
                            <th>الإدخالات</th>
                            <th>آخر 7 أيام</th>
                            <th>القيود المسجلة</th>
                            <th>آخر نشاط</th>
                        </tr>
                    </thead>
                    <tbody>
                        {team.length === 0 ? (
                            <tr>
                                <td colSpan={6}>لا يوجد موظفون في هذا الفرع.</td>
                            </tr>
                        ) : (
                            team.map((member) => (
                                <tr key={member.id}>
                                    <td>
                                        <RowIdentity name={member.name} subtitle={member.roleLabel} status={member.isActive ? 'green' : 'gray'} />
                                    </td>
                                    <td className="text-gray-600" dir="ltr">
                                        {member.username}
                                    </td>
                                    <td>
                                        <span className="flex min-w-36 items-center gap-3">
                                            <b className="w-9 shrink-0 font-display text-gray-900">{formatNumber(member.entries)}</b>
                                            <MeterBar value={member.entries} max={busiest} className="flex-1" />
                                        </span>
                                    </td>
                                    <td className="font-display font-semibold text-gray-900">{formatNumber(member.weekEntries)}</td>
                                    <td>
                                        {member.recordedCount === 0 ? (
                                            <span className="text-gray-400">—</span>
                                        ) : (
                                            <span className="flex flex-col gap-0.5">
                                                {member.recordedCharged > 0 && (
                                                    <span>
                                                        <span className="text-xs text-gray-500">عليه</span>{' '}
                                                        <Shekels amount={member.recordedCharged} />
                                                    </span>
                                                )}
                                                {member.recordedCredited > 0 && (
                                                    <span>
                                                        <span className="text-xs text-gray-500">له</span>{' '}
                                                        <Shekels
                                                            amount={member.recordedCredited}
                                                            className="text-emerald-700 dark:text-emerald-400"
                                                        />
                                                    </span>
                                                )}
                                                <span className="text-xs text-gray-500">{formatNumber(member.recordedCount)} قيد</span>
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap text-gray-600">
                                        {member.lastActivityAt ? timeAgo(member.lastActivityAt) : '—'}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/** The last two weeks, newest first: each day's new subscriptions, entries, charges and busiest member. */
function WorkLog({ days }) {
    return (
        <section className="min-w-0 xl:col-span-2">
            <TableHeading title="سجل العمل اليومي" caption={`آخر ${formatNumber(days.length)} يوم`} />
            <div className="data-table-container">
                <table className="data-table w-full text-start text-sm">
                    <thead>
                        <tr>
                            <th>اليوم</th>
                            <th>مشتركون جدد</th>
                            <th>الإدخالات</th>
                            <th>القيود</th>
                            <th>الأكثر إدخالاً</th>
                        </tr>
                    </thead>
                    <tbody>
                        {days.map((day, index) => (
                            <tr key={day.date}>
                                <td>
                                    <span className="inline-flex items-center gap-2 whitespace-nowrap font-semibold text-gray-900">
                                        {formatDayLabel(day.date)}
                                        {index === 0 && (
                                            <span className="rounded-full bg-brand-500 px-2 py-0.5 text-[12px] font-bold text-white">اليوم</span>
                                        )}
                                    </span>
                                </td>
                                <td className="font-display font-semibold text-gray-900">{formatNumber(day.newSubscriptions)}</td>
                                <td className="font-display font-semibold text-gray-900">{formatNumber(day.entries)}</td>
                                <td>
                                    {day.chargesCount === 0 ? (
                                        <span className="text-gray-400">—</span>
                                    ) : (
                                        <>
                                            <Shekels amount={day.chargesTotal} />
                                            <span className="block text-xs text-gray-500">{formatNumber(day.chargesCount)} قيد</span>
                                        </>
                                    )}
                                </td>
                                <td className="text-gray-600">{day.topEntrant ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/** The newest subscriptions, as a timeline: who registered them, and when. */
function LatestRegistrations({ subscriptions }) {
    return (
        <section className="rise-in min-w-0 self-start rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="text-lg font-bold text-gray-900">آخر الإدخالات</h3>
                <span className="text-xs text-gray-500">أحدث المشتركين المسجلين</span>
            </div>

            {subscriptions.length === 0 ? (
                <p className="py-12 text-center text-sm text-gray-500">لا مشتركين في هذا الفرع بعد.</p>
            ) : (
                <ol className="relative mt-5 space-y-5 before:absolute before:inset-y-2 before:start-[5px] before:w-px before:bg-gray-200">
                    {subscriptions.map((subscription) => (
                        <li key={subscription.id} className="relative ps-7">
                            <span
                                className={`absolute start-0 top-2 h-[11px] w-[11px] rounded-full ring-4 ring-surface ${TIMELINE_DOTS[subscription.status]}`}
                                aria-hidden="true"
                            />
                            <div className="flex items-baseline justify-between gap-3">
                                <p className="min-w-0 break-words font-semibold text-gray-900">{subscription.name}</p>
                                <time dateTime={subscription.createdAt} className="shrink-0 whitespace-nowrap text-xs text-gray-500">
                                    {timeAgo(subscription.createdAt)}
                                </time>
                            </div>
                            <p className="mt-0.5 flex flex-wrap gap-x-1.5 text-xs text-gray-500">
                                {subscription.registeredByName && <span>بواسطة {subscription.registeredByName}</span>}
                                {subscription.registeredByName && subscription.phone && <span aria-hidden="true">·</span>}
                                {subscription.phone && (
                                    <span dir="ltr" className="tabular-nums">
                                        {subscription.phone}
                                    </span>
                                )}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

/**
 * One branch's work: its subscriptions by status, what it charged, its
 * staff's entries over the last month, each member of staff, the last
 * two weeks day by day and its newest subscriptions. The Super Admin gets
 * here from the branch cards; a branch's staff land here directly.
 */
export default function Show({ branch, canCompareBranches, dailyRegistrations, dailyCharges, team, workLog, latestRegistrations }) {
    const details = [branchPlace(branch), branch.phone].filter(Boolean);
    const ledgerHref = canCompareBranches ? `/ledger?${new URLSearchParams({ 'filter[branch_id]': branch.id })}` : '/ledger';

    return (
        <AuthenticatedLayout
            header={
                <>
                    <PageHeader
                        eyebrow={canCompareBranches && <BackLink href="/branch-performance">أداء الفروع</BackLink>}
                        title={branch.name}
                        titleAddon={<StatusPill tone={branch.isActive ? 'green' : 'gray'} label={branch.isActive ? 'فرع نشط' : 'فرع متوقف'} />}
                        meta={
                            details.length > 0 &&
                            details.map((detail, index) => (
                                <span key={detail}>
                                    {index > 0 && ' · '}
                                    <span dir={detail === branch.phone ? 'ltr' : undefined}>{detail}</span>
                                </span>
                            ))
                        }
                    />
                    <Link
                        href={ledgerHref}
                        className="inline-flex shrink-0 items-center gap-2 self-start rounded-control bg-brand-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 sm:self-center"
                    >
                        <Icon name="ledger" className="h-[18px] w-[18px]" />
                        السجل المالي للفرع
                    </Link>
                </>
            }
        >
            <Head title={`أداء ${branch.name}`} />

            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <KpiTile hero className="sm:col-span-2" label="المشتركون" value={formatNumber(branch.subscriptions)}>
                    <StatusMix statusCounts={branch.statusCounts} />
                </KpiTile>
                <KpiTile
                    icon="folder"
                    label="قيود آخر 30 يوم"
                    value={formatMoney(branch.monthChargesTotal)}
                    unit="₪"
                    hint={`الإجمالي ${formatMoney(branch.chargesTotal)} شيكل`}
                />
                <KpiTile
                    icon="chart"
                    label="إدخالات اليوم"
                    value={formatNumber(branch.todayEntries)}
                    hint={`${formatNumber(branch.weekEntries)} آخر 7 أيام · ${formatNumber(branch.monthEntries)} آخر 30 يوم`}
                />
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <ChartCard title="المشتركون الجدد يوميًا" caption={`آخر ${formatNumber(dailyRegistrations.length)} يوم`}>
                    <BarChart
                        data={dailyRegistrations}
                        label={`المشتركون الجدد في ${branch.name} لكل يوم`}
                        formatValue={(value) => `${formatNumber(value)} مشترك جديد`}
                    />
                </ChartCard>
                <ChartCard title="القيود اليومية" caption={`بالشيكل · آخر ${formatNumber(dailyCharges.length)} يوم`}>
                    <BarChart
                        data={dailyCharges}
                        label={`قيود ${branch.name} لكل يوم، بالشيكل`}
                        formatValue={(value) => `${formatMoney(value)} شيكل`}
                        countLabel="قيد"
                    />
                </ChartCard>
            </div>

            <TeamTable team={team} />

            <div className="mt-5 grid items-start gap-5 xl:grid-cols-3">
                <WorkLog days={workLog} />
                <LatestRegistrations subscriptions={latestRegistrations} />
            </div>
        </AuthenticatedLayout>
    );
}
