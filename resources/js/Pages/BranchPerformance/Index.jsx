import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import MeterBar from '@/Components/Charts/MeterBar';
import Sparkline from '@/Components/Charts/Sparkline';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { formatMoney, formatNumber, percentOf, timeAgo } from '@/lib/format';
import BranchMark, { branchPlace } from './BranchMark';
import PageHeader from '@/Components/PageHeader';

const SORTS = [
    { value: 'revenue', label: 'الإيرادات' },
    { value: 'subscriptions', label: 'المشتركون' },
    { value: 'activity', label: 'النشاط' },
];

function MiniStat({ label, value }) {
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50 px-3.5 py-2.5">
            <p className="text-xs text-gray-500">{label}</p>
            <p className="mt-0.5 font-display text-lg font-bold text-gray-900">{formatNumber(value)}</p>
        </div>
    );
}

/**
 * One branch: its place in the order, what it charged, how many of its
 * subscriptions are active, its entries over two weeks, and its staff. The
 * whole card opens the branch.
 */
function BranchCard({ branch, style }) {
    const activeShare = percentOf(branch.activeSubscriptions, branch.subscriptions);

    return (
        <article
            className="rise-in group relative flex min-w-0 flex-col rounded-panel border border-gray-100 bg-surface p-6 shadow-card transition-shadow hover:border-gray-200 hover:shadow-lift focus-within:ring-2 focus-within:ring-gray-900"
            style={style}
        >
            <header className="flex items-start gap-3.5">
                <BranchMark isActive={branch.isActive} />
                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-xl font-bold text-gray-900">
                        <Link href={`/branch-performance/${branch.id}`} className="outline-none after:absolute after:inset-0 after:rounded-panel">
                            {branch.name}
                        </Link>
                    </h3>
                    <p className="truncate text-xs text-gray-500">
                        {branchPlace(branch) || '—'}
                        {!branch.isActive && ' · فرع متوقف'}
                    </p>
                </div>
                <span
                    className="shrink-0 rounded-full border border-gray-200 bg-surface px-2.5 py-0.5 font-display text-xs font-bold text-gray-700"
                    title="ترتيبه بين الفروع"
                >
                    #{branch.rank}
                </span>
            </header>

            <div className="mt-6 flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                <div className="min-w-0">
                    <p className="text-xs text-gray-500">إجمالي القيود</p>
                    <p className="mt-1 flex flex-wrap items-baseline gap-x-1.5">
                        <span className="font-display text-3xl font-bold text-gray-900 sm:text-4xl">{formatMoney(branch.chargesTotal)}</span>
                        <span className="text-sm text-gray-500">₪</span>
                    </p>
                </div>
                <div className="shrink-0 text-end">
                    <Sparkline values={branch.sparkline} label={`إدخالات ${branch.name} في آخر 14 يوم`} />
                    <p className="mt-1 text-[13px] text-gray-500">إدخالات آخر 14 يوم</p>
                </div>
            </div>

            <div className="mt-5">
                <div className="flex items-baseline justify-between gap-3 text-xs">
                    <span className="text-gray-500">
                        <b className="font-display text-sm text-gray-900">{formatNumber(branch.activeSubscriptions)}</b> نشط من{' '}
                        {formatNumber(branch.subscriptions)}
                    </span>
                    <b className="font-display text-gray-700">{activeShare}%</b>
                </div>
                <MeterBar value={branch.activeSubscriptions} max={branch.subscriptions} className="mt-2" />
            </div>

            <div className="mt-5 grid grid-cols-3 gap-2.5">
                <MiniStat label="اليوم" value={branch.todayEntries} />
                <MiniStat label="آخر 7 أيام" value={branch.weekEntries} />
                <MiniStat label="الموظفون" value={branch.staff} />
            </div>

            <footer className="mt-5 flex items-center justify-between gap-3 border-t border-gray-100 pt-4 text-xs">
                <span className="inline-flex items-center gap-1.5 text-gray-500">
                    <Icon name="clock" className="h-4 w-4" />
                    {branch.lastEntryAt ? `آخر إدخال ${timeAgo(branch.lastEntryAt)}` : 'لا إدخالات بعد'}
                </span>
                <span className="inline-flex items-center gap-1 font-semibold text-gray-900 transition group-hover:text-brand-600" aria-hidden="true">
                    التفاصيل
                    <Icon name="chevron-left" className="h-4 w-4 transition group-hover:-translate-x-0.5" />
                </span>
            </footer>
        </article>
    );
}

/**
 * How every branch is doing, for the Super Admin: the totals across
 * branches, then one card per branch, ranked by what the sort buttons
 * pick. A card opens the branch's own page.
 */
export default function Index({ sort, summary, branches }) {
    function changeSort(next) {
        router.get('/branch-performance', next === 'revenue' ? {} : { sort: next }, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    eyebrow={`${formatNumber(summary.branches)} فروع · ${formatNumber(summary.activeBranches)} نشطة`}
                    title="أداء الفروع"
                    info="إيرادات ونشاط وفريق كل فرع. اضغط على أي فرع لتفاصيل عمله اليومية."
                    actions={
                        <>
                            <span className="text-sm text-gray-500">ترتيب:</span>
                            <SegmentedTabs options={SORTS} value={sort} onChange={changeSort} label="ترتيب الفروع" />
                        </>
                    }
                />
            }
        >
            <Head title="أداء الفروع" />

            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <KpiTile icon="folder" label="إجمالي القيود" value={formatMoney(summary.chargesTotal)} unit="₪" hint="كل الفروع · منذ البداية" />
                <KpiTile
                    icon="users"
                    label="المشتركون النشطون"
                    value={formatNumber(summary.activeSubscriptions)}
                    hint={`${percentOf(summary.activeSubscriptions, summary.subscriptions)}% من ${formatNumber(summary.subscriptions)} مشترك`}
                />
                <KpiTile
                    icon="chart"
                    label="إدخالات آخر 7 أيام"
                    value={formatNumber(summary.weekEntries)}
                    hint={`${formatNumber(summary.todayEntries)} اليوم · مشتركون جدد وقراءات`}
                />
                <KpiTile
                    icon="user"
                    label="الموظفون"
                    value={formatNumber(summary.staff)}
                    hint={`موزعون على ${formatNumber(summary.branches)} فروع`}
                />
            </div>

            {branches.length === 0 ? (
                <p className="mt-5 rounded-panel border border-gray-100 bg-surface p-12 text-center text-sm text-gray-500 shadow-card">
                    لا توجد فروع بعد.
                </p>
            ) : (
                <div className="mt-5 grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                    {branches.map((branch, index) => (
                        <BranchCard key={branch.id} branch={branch} style={{ '--rise-delay': `${Math.min(index, 8) * 60}ms` }} />
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
