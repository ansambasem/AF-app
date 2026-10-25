import { lazy, Suspense, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import BarChart from '@/Components/Charts/BarChart';
import MeterBar from '@/Components/Charts/MeterBar';
import Pagination from '@/Components/DataTable/Pagination';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import StatusPill from '@/Components/DataTable/StatusPill';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import KpiTile from '@/Components/KpiTile';
import PageHeader from '@/Components/PageHeader';
import SearchableSelect from '@/Components/SearchableSelect';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { useStatementWindow } from '@/hooks/useStatementWindow';
import { formatMoney, formatNumber, formatShortDay, percentOf, timeAgo } from '@/lib/format';
import { formatWeekDay } from '@/lib/weekDays';
import { shouldPrefetchPage } from '@/lib/navigation';

const BranchModal = lazy(() => import('@/Pages/Branches/BranchModal'));
const UserModal = lazy(() => import('@/Pages/Users/UserModal'));
const SubscriptionModal = lazy(() => import('@/Pages/Subscriptions/SubscriptionModal'));
const StatementModal = lazy(() => import('@/Pages/Subscriptions/StatementModal'));
const QuickPaymentSearch = lazy(() => import('./Dashboard/QuickPaymentSearch'));

/** The current address without `?edit=` (the subscriber being edited in a window). */
function urlWithoutEdit() {
    const url = new URL(window.location.href);
    url.searchParams.delete('edit');

    return `${url.pathname}${url.search}`;
}

/** The period's name on the tabs and in sentences. */
const periodLabel = (days) => (days === 7 ? '7 أيام' : `${days} يومًا`);

/** "+4.2%" or "−3%" against the period before, on the graphite card; nothing when there was nothing before. */
function ChangeBadge({ change }) {
    if (change === null || change === undefined) {
        return null;
    }

    const up = change >= 0;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-display text-xs font-bold ${up ? 'bg-emerald-400/15 text-emerald-300' : 'bg-brand-400/20 text-brand-100'}`}
            title="مقارنة بالفترة السابقة"
        >
            <Icon name={up ? 'trend-up' : 'trend-down'} className="h-3.5 w-3.5" />
            <span dir="ltr">
                {up ? '+' : '−'}
                {Math.abs(change)}%
            </span>
        </span>
    );
}

/** A bar split into coloured parts (`{ value, className, label }`), each as wide as its share. */
function SplitBar({ parts, label, className = 'mt-4' }) {
    const total = parts.reduce((sum, part) => sum + part.value, 0);

    return (
        <div className={`flex h-2 overflow-hidden rounded-full bg-gray-100 ${className}`} role="img" aria-label={label}>
            {total > 0 &&
                parts.map((part) =>
                    part.value > 0 ? (
                        <span
                            key={part.label}
                            title={`${part.label}: ${formatNumber(part.value)}`}
                            className={part.className}
                            style={{ width: `${(part.value / total) * 100}%` }}
                        />
                    ) : null,
                )}
        </div>
    );
}

/** A dashboard card: an icon tile, a title with its guidance behind an "!", an action at the far end, then the body. */
function Panel({ icon, title, info, action, className = '', children }) {
    return (
        <section className={`rise-in rounded-panel border border-gray-100 bg-surface p-5 shadow-card sm:p-6 ${className}`}>
            <div className="mb-5 flex flex-wrap items-center gap-x-2.5 gap-y-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500">
                    <Icon name={icon} className="h-[18px] w-[18px]" />
                </span>
                <h3 className="font-bold text-gray-900">{title}</h3>
                {info && <InfoTip label={`عن ${title}`}>{info}</InfoTip>}
                {action && <div className="ms-auto">{action}</div>}
            </div>
            {children}
        </section>
    );
}

/** "فتح" at the end of a card's title, to the page the card sums up. */
function OpenLink({ href }) {
    return (
        <Link href={href} prefetch={shouldPrefetchPage(href)} className="inline-flex items-center gap-1 text-sm font-semibold text-gray-500 transition hover:text-gray-900">
            عرض الكل
            <Icon name="chevron-left" className="h-4 w-4" strokeWidth={2} />
        </Link>
    );
}

/** A figure tile that opens the page it sums up. */
function TileLink({ href, children }) {
    return (
        <Link
            href={href}
            prefetch={shouldPrefetchPage(href)}
            className="block rounded-panel transition hover:-translate-y-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 [&>div]:h-full"
        >
            {children}
        </Link>
    );
}

const ATTENTION_TONES = {
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    red: 'bg-brand-500/10 text-brand-600',
    quiet: 'bg-gray-100 text-gray-500',
};

/** Something waiting on someone: its count, what it is, and a link to where it is dealt with. Quiet once nothing waits. */
function AttentionItem({ icon, title, subtitle, count, href, tone = 'amber' }) {
    const toneClass = ATTENTION_TONES[count > 0 ? tone : 'quiet'];

    return (
        <Link
            href={href}
            prefetch={shouldPrefetchPage(href)}
            className="rise-in flex items-center gap-3 rounded-card border border-gray-100 bg-surface p-4 shadow-card transition hover:-translate-y-0.5 hover:border-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
        >
            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-control ${toneClass}`}>
                <Icon name={icon} className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold text-gray-900">{title}</span>
                <span className="block truncate text-xs text-gray-500">{subtitle}</span>
            </span>
            <span className={`rounded-full px-2.5 py-0.5 font-display text-sm font-bold ${toneClass}`}>{formatNumber(count)}</span>
        </Link>
    );
}

/** A pulsing placeholder while a figure loads after the page. */
function PendingTile() {
    return (
        <div className="animate-pulse rounded-panel border border-gray-100 bg-surface p-6 shadow-card" aria-hidden="true">
            <div className="flex items-center gap-2.5">
                <span className="h-9 w-9 rounded-xl bg-gray-100" />
                <span className="h-3 w-24 rounded bg-gray-100" />
            </div>
            <div className="mt-5 h-8 w-32 rounded bg-gray-100" />
            <div className="mt-3 h-3 w-40 rounded bg-gray-100" />
        </div>
    );
}

/** A pulsing placeholder card while a list loads after the page. */
function PendingPanel() {
    return (
        <div className="animate-pulse rounded-panel border border-gray-100 bg-surface p-6 shadow-card" aria-hidden="true">
            <div className="flex items-center gap-2.5">
                <span className="h-9 w-9 rounded-xl bg-gray-100" />
                <span className="h-3 w-32 rounded bg-gray-100" />
            </div>
            {[0, 1, 2, 3, 4].map((row) => (
                <div key={row} className="mt-5 flex items-center justify-between gap-3">
                    <span className="h-3 w-40 rounded bg-gray-100" />
                    <span className="h-3 w-20 rounded bg-gray-100" />
                </div>
            ))}
        </div>
    );
}

const READING_PARTS = (readings) => [
    { label: 'معتمدة', value: readings.approved, className: 'bg-emerald-500' },
    { label: 'بانتظار الاعتماد', value: readings.pending, className: 'bg-amber-500' },
    { label: 'لم تُقرأ', value: readings.missing, className: 'bg-brand-500' },
];

/**
 * The home page: the figures of the branches the user looks after over
 * the chosen period — the collections, subscribers, meter boxes, weekly
 * readings and debts — what waits on someone, the quick actions and the
 * latest payments. The head of the company sees every branch and can
 * narrow to a governorate or a branch; everyone else sees their own
 * branch. Each part shows only to those who may open its page.
 */
export default function Dashboard({
    greeting,
    scope,
    filters,
    periods,
    branchOptions,
    governorateOptions,
    sections,
    debts,
    latestPayments,
    fieldApp,
    auth,
    can,
    canCreateSubscription,
    canCreateBranch,
    canCreateUser,
    branchForm,
    userForm,
    statement,
    subscriptionForm,
    subscriptionEdit,
}) {
    const [creating, setCreating] = useState(null);
    const [findingPayer, setFindingPayer] = useState(false);
    const [editing, setEditing] = useState(false);
    const statementWindow = useStatementWindow(statement);
    // A save from one of the dashboard's windows comes back here (with any open statement), not to the record's list page.
    const returnHere = { preserveState: true, headers: { 'X-Return-To': urlWithoutEdit() } };
    const [chartView, setChartView] = useState('chart');
    const [search, setSearch] = useState(filters.search ?? '');
    const { collections, subscriptions, meterBoxes, readings, disconnections, boxes } = sections;
    const hasAnyData = Object.keys(sections).length > 0 || Boolean(latestPayments);
    const period = periodLabel(filters.days);
    const canOpenReceipts = can?.viewSubscriptions || can?.recordPayments;

    /** Reloads the dashboard with changed filters (only some props when given), back on the first page of payments. */
    function visit(changes, only = null) {
        const query = Object.fromEntries(
            Object.entries({ ...filters, page: undefined, ...changes }).filter(([, value]) => value !== null && value !== undefined && value !== ''),
        );

        router.get('/dashboard', query, { preserveScroll: true, preserveState: true, replace: true, ...(only ? { only } : {}) });
    }

    /**
     * Opens the "new branch", "new user" or "new subscriber" pop-up — the
     * same one as on its own page — fetching its dropdown options the first
     * time.
     */
    function openCreateForm(kind) {
        const optionsProp = { branch: 'branchForm', user: 'userForm', subscription: 'subscriptionForm' }[kind];

        if ({ branchForm, userForm, subscriptionForm }[optionsProp]) {
            setCreating(kind);
            return;
        }

        router.reload({ only: [optionsProp], onSuccess: () => setCreating(kind) });
    }

    /** A subscriber's statement — their account, with the payment, charge and discount forms — over the dashboard; `form` opens one of them at once. */
    function openStatement(subscriber, form = null) {
        statementWindow.open(
            {
                id: subscriber.subscriptionId ?? subscriber.id,
                fullName: subscriber.fullName ?? subscriber.name ?? subscriber.subscriber,
                accountNumber: subscriber.accountNumber,
                status: subscriber.status,
                statusLabel: subscriber.statusLabel,
                branchName: subscriber.branchName,
            },
            form,
        );
    }

    /** Opens the subscriber's "edit" pop-up over their statement, fetching their details (and the form's dropdowns the first time). */
    function openEdit(subscriber) {
        router.reload({
            data: { edit: subscriber.id },
            only: subscriptionForm ? ['subscriptionEdit'] : ['subscriptionEdit', 'subscriptionForm'],
            onSuccess: () => setEditing(true),
        });
    }

    function closeEdit() {
        setEditing(false);
        router.replace({ url: urlWithoutEdit(), props: (props) => ({ ...props, subscriptionEdit: null }), preserveScroll: true, preserveState: true });
    }

    const scopeLine = scope.company
        ? `${scope.label} · ${formatNumber(scope.branches)} ${scope.branches === 1 ? 'فرع' : 'فروع'} في ${formatNumber(scope.governorates)} ${scope.governorates === 1 ? 'محافظة' : 'محافظات'}`
        : scope.label;

    // "تسجيل دفعة" and "مشترك جديد" open over the dashboard; someone who can't open a statement records payments on the payments page.
    const quickActions = [
        can?.recordPayments && {
            href: '/payments',
            onClick: can?.viewSubscriptions ? () => setFindingPayer(true) : null,
            icon: 'banknotes',
            title: 'تسجيل دفعة',
            subtitle: 'استلام دفعة وطباعة الوصل',
            main: true,
        },
        canCreateSubscription && { href: '/subscriptions/create', onClick: () => openCreateForm('subscription'), icon: 'plus', title: 'مشترك جديد', subtitle: 'إضافة اشتراك' },
        can?.viewMeterReadings && { href: '/meter-readings', icon: 'chart', title: 'القراءات', subtitle: 'إدخال واعتماد' },
        can?.sendMessages && { href: '/messages/create', icon: 'send', title: 'رسالة جديدة', subtitle: 'لمشترك أو مجموعة' },
        can?.viewReports && { href: '/reports', icon: 'receipt', title: 'التقارير', subtitle: 'تقرير الفترة وتنزيله' },
        can?.viewMeterBoxes && { href: '/meter-boxes', icon: 'table', title: 'الطبلونات', subtitle: 'أماكنها ومشتركوها' },
    ].filter(Boolean);

    const attention = [
        readings?.canApprove && {
            key: 'pending',
            icon: 'check',
            title: 'بانتظار الاعتماد',
            subtitle: 'في أسبوع القراءة الأخير',
            count: readings.pending,
            href: '/meter-readings',
        },
        readings && {
            key: 'missing',
            icon: 'gauge',
            title: 'عدادات لم تُقرأ',
            subtitle: 'في أسبوع القراءة الأخير',
            count: readings.missing,
            href: '/meter-readings',
            tone: 'red',
        },
        boxes && {
            key: 'boxes',
            icon: 'wallet',
            title: 'صناديق مفتوحة',
            subtitle: 'فيها حركات لم تُغلق',
            count: boxes.open,
            href: '/payments/cash-boxes?place=open',
        },
        disconnections && {
            key: 'overdue',
            icon: 'power',
            title: 'قطع متأخر',
            subtitle: 'في قوائم القطع ولم يُنفّذ',
            count: disconnections.overdue,
            href: '/disconnection-lists',
            tone: 'red',
        },
        disconnections && {
            key: 'upcoming',
            icon: 'calendar',
            title: 'قطع خلال 7 أيام',
            subtitle: `${formatNumber(disconnections.openLists)} قائمة مفتوحة`,
            count: disconnections.upcoming,
            href: '/disconnection-lists',
        },
    ].filter(Boolean);

    // An odd tile left alone on the last row takes the whole row.
    const lastActionSpans = quickActions.filter((action) => !action.main).length % 2 === 1;

    const rankList = collections?.byBranch ?? collections?.byCollector ?? null;
    const rankMax = Math.max(0, ...(rankList ?? []).map((item) => item.total));
    const topBreaker = subscriptions?.byBreaker?.[0] ?? null;
    const hasBreakers = subscriptions?.byBreaker.length > 0;
    const breakerMax = Math.max(0, ...(subscriptions?.byBreaker ?? []).map((breaker) => breaker.count));
    const payments = latestPayments?.rows;
    const tiles = [subscriptions, meterBoxes, subscriptions, debts !== null].filter(Boolean).length;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={
                        <>
                            {greeting}، <span className="text-brand-600">{auth.user.name}</span>
                        </>
                    }
                    meta={[scopeLine, formatWeekDay(scope.today)].filter(Boolean).join(' · ')}
                    actions={
                        <>
                            {can?.viewUserTypes && (
                                <Link href="/users?tab=types" className={secondaryButtonClass}>
                                    أنواع المستخدمين
                                </Link>
                            )}
                            {canCreateBranch ? (
                                <AddButton onClick={() => openCreateForm('branch')}>فرع جديد</AddButton>
                            ) : canCreateUser ? (
                                <AddButton onClick={() => openCreateForm('user')}>مستخدم جديد</AddButton>
                            ) : null}
                        </>
                    }
                />
            }
        >
            <Head title="لوحة التحكم" />

            {!hasAnyData ? (
                <div className="rounded-card border border-dashed border-gray-200 bg-surface px-6 py-16 text-center">
                    {fieldApp ? (
                        <>
                            <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-500/10 text-brand-600">
                                <Icon name="phone" className="h-7 w-7" />
                            </span>
                            <h3 className="mt-4 text-lg font-bold text-gray-900">حسابك للعمل الميداني</h3>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-7 text-gray-600">
                                تُسجَّل الدفعات والقراءات من تطبيق التحصيل على الهاتف، وتدخل إليه بنفس اسم المستخدم وكلمة المرور. لا تظهر لك بيانات في
                                الموقع.
                            </p>
                            {fieldApp.url && (
                                <a
                                    href={fieldApp.url}
                                    className="mt-6 inline-flex items-center justify-center gap-2 rounded-control bg-brand-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-glow transition hover:brightness-110"
                                >
                                    <Icon name="arrow-down-tray" className="h-4 w-4" />
                                    تحميل تطبيق التحصيل
                                </a>
                            )}
                        </>
                    ) : (
                        <p className="text-sm text-gray-500">لا توجد بيانات لعرضها حاليًا — لم يتم منحك صلاحية عرض أي جدول بعد.</p>
                    )}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <SegmentedTabs
                            label="الفترة"
                            value={filters.days}
                            onChange={(days) => visit({ days })}
                            options={periods.map((days) => ({ value: days, label: periodLabel(days) }))}
                        />
                        {scope.company ? (
                            <>
                                {governorateOptions.length > 1 && (
                                    <div className="w-full sm:w-52">
                                        <SearchableSelect
                                            value={filters.governorate ?? ''}
                                            onChange={(value) => visit({ governorate: value || null, branch: null })}
                                            options={governorateOptions}
                                            placeholder="كل المحافظات"
                                            searchable={governorateOptions.length > 6}
                                            active={Boolean(filters.governorate)}
                                        />
                                    </div>
                                )}
                                <div className="w-full sm:w-52">
                                    <SearchableSelect
                                        value={filters.branch ?? ''}
                                        onChange={(value) => visit({ branch: value || null })}
                                        options={branchOptions.filter(
                                            (branch) => !filters.governorate || String(branch.governorate) === String(filters.governorate),
                                        )}
                                        placeholder="كل الفروع"
                                        searchPlaceholder="ابحث عن فرع..."
                                        active={Boolean(filters.branch)}
                                    />
                                </div>
                            </>
                        ) : (
                            scope.label && (
                                <span className="inline-flex items-center gap-2 rounded-control border border-gray-100 bg-surface px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm">
                                    <Icon name="lock" className="h-4 w-4 text-gray-400" />
                                    فرعك: {scope.label}
                                </span>
                            )
                        )}
                    </div>

                    {(collections || tiles > 0) && (
                        <div className={`grid grid-cols-1 gap-5 ${collections && tiles > 0 ? 'xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' : ''}`}>
                            {collections && (
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
                            )}

                            {tiles > 0 && (
                                <div className={`grid grid-cols-1 gap-5 sm:grid-cols-2 ${collections ? '' : 'xl:grid-cols-4'}`}>
                                    {subscriptions && (
                                        <TileLink href="/subscriptions">
                                            <KpiTile
                                                icon="users"
                                                label="المشتركون النشطون"
                                                value={formatNumber(subscriptions.active)}
                                                hint={`من ${formatNumber(subscriptions.total)} · ${formatNumber(subscriptions.newInPeriod)} جديد في آخر ${period}`}
                                            >
                                                <SplitBar
                                                    label="حالات المشتركين"
                                                    parts={[
                                                        { label: 'نشط', value: subscriptions.active, className: 'bg-emerald-500' },
                                                        { label: 'موقوف', value: subscriptions.suspended, className: 'bg-amber-500' },
                                                        { label: 'مفصول', value: subscriptions.disconnected, className: 'bg-gray-400' },
                                                    ]}
                                                />
                                            </KpiTile>
                                        </TileLink>
                                    )}
                                    {meterBoxes && (
                                        <TileLink href="/meter-boxes">
                                            <KpiTile
                                                icon="table"
                                                label="الطبلونات"
                                                value={formatNumber(meterBoxes.total)}
                                                hint={
                                                    readings
                                                        ? `قُرئ ${percentOf(readings.approved + readings.pending, readings.total)}% من العدادات هذا الأسبوع`
                                                        : `${formatNumber(meterBoxes.empty)} بلا مشتركين نشطين`
                                                }
                                            >
                                                {readings && <SplitBar label="قراءات الأسبوع" parts={READING_PARTS(readings)} />}
                                            </KpiTile>
                                        </TileLink>
                                    )}
                                    {subscriptions && (
                                        <KpiTile
                                            icon="bolt"
                                            label="الأمبير المتعاقد عليه"
                                            value={formatNumber(subscriptions.contractedAmperes)}
                                            unit="أمبير"
                                            hint={
                                                topBreaker
                                                    ? `الأكثر: قاطع ${topBreaker.ampere} أمبير (${topBreaker.share}%)`
                                                    : 'لا قواطع للمشتركين النشطين'
                                            }
                                        />
                                    )}
                                    {debts === undefined ? (
                                        <PendingTile />
                                    ) : (
                                        debts && (
                                            <TileLink href="/receivables">
                                                <KpiTile
                                                    icon="clock"
                                                    label="الديون المستحقة"
                                                    value={formatMoney(debts.total)}
                                                    unit="شيكل"
                                                    hint={`${formatNumber(debts.count)} مدين · أكثر من 90 يومًا ${debts.olderShare}%`}
                                                >
                                                    <SplitBar
                                                        label="أعمار الديون"
                                                        parts={[
                                                            { label: 'حتى 90 يومًا', value: debts.total - debts.older, className: 'bg-amber-500' },
                                                            { label: 'أكثر من 90 يومًا', value: debts.older, className: 'bg-brand-500' },
                                                        ]}
                                                    />
                                                </KpiTile>
                                            </TileLink>
                                        )
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {attention.length > 0 && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]">
                            {attention.map(({ key, ...item }) => (
                                <AttentionItem key={key} {...item} />
                            ))}
                        </div>
                    )}

                    {(quickActions.length > 0 || collections) && (
                        <div
                            className={`grid grid-cols-1 gap-5 ${quickActions.length > 0 && collections ? 'xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]' : ''}`}
                        >
                            {quickActions.length > 0 && (
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
                            )}

                            {collections && (
                                <Panel
                                    icon="chart"
                                    title="حركة التحصيل"
                                    info={`ما حُصّل كل يوم في آخر ${period}، واليوم آخرها.`}
                                    className="flex flex-col"
                                    action={
                                        <SegmentedTabs
                                            label="طريقة العرض"
                                            value={chartView}
                                            onChange={setChartView}
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
                            )}
                        </div>
                    )}

                    {(debts !== null || hasBreakers) && (
                        <div className={`grid grid-cols-1 gap-5 ${debts !== null && hasBreakers ? 'lg:grid-cols-2' : ''}`}>
                            {debts === undefined ? (
                                <PendingPanel />
                            ) : (
                                debts && (
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
                                                                onClick={() => openStatement(debtor)}
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
                                )
                            )}

                            {hasBreakers && (
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
                            )}
                        </div>
                    )}

                    {(rankList || readings) && (
                        <div className={`grid grid-cols-1 gap-5 ${rankList && readings ? 'lg:grid-cols-2' : ''}`}>
                            {rankList && (
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
                            )}

                            {readings && (
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
                            )}
                        </div>
                    )}

                    {payments && (
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
                                        visit({ search: search.trim() || null }, ['latestPayments', 'filters']);
                                    }}
                                >
                                    <Icon
                                        name="search"
                                        className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400"
                                    />
                                    <input
                                        type="search"
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder="اسم المشترك أو رقم الحساب"
                                        aria-label="ابحث في الدفعات"
                                        className="block w-full py-2.5 ps-10 text-sm"
                                    />
                                </form>
                                <SegmentedTabs
                                    label="طريقة الدفع"
                                    value={filters.method ?? ''}
                                    onChange={(method) => visit({ method: method || null }, ['latestPayments', 'filters'])}
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
                                                                onClick={() => openStatement(payment)}
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
                            <Pagination meta={payments} onPageChange={(page) => visit({ page }, ['latestPayments'])} />
                        </section>
                    )}
                </div>
            )}

            <Suspense fallback={<p role="status" className="fixed bottom-6 left-1/2 z-[100] -translate-x-1/2 rounded-control border border-gray-200 bg-surface px-4 py-3 text-sm text-gray-600 shadow-card">جارٍ تحميل النافذة…</p>}>
                {branchForm && creating === 'branch' && (
                    <BranchModal
                        show
                        onClose={() => setCreating(null)}
                        branch={null}
                        governorates={branchForm.governorates}
                        areas={branchForm.areas}
                        visitOptions={returnHere}
                    />
                )}
                {subscriptionForm && creating === 'subscription' && (
                    <SubscriptionModal show onClose={() => setCreating(null)} subscription={null} visitOptions={returnHere} {...subscriptionForm} />
                )}
                {subscriptionForm && subscriptionEdit && editing && (
                    <SubscriptionModal key={`edit-${subscriptionEdit.id}`} show onClose={closeEdit} subscription={subscriptionEdit} visitOptions={returnHere} {...subscriptionForm} />
                )}
                {findingPayer && (
                    <QuickPaymentSearch
                        show
                        onClose={() => setFindingPayer(false)}
                        onPick={(subscriber) => {
                            setFindingPayer(false);
                            openStatement(subscriber, 'payment');
                        }}
                    />
                )}
                {statementWindow.subscription && (
                    <StatementModal
                        key={statementWindow.subscription.id}
                        subscription={statementWindow.subscription}
                        statement={statementWindow.statement}
                        initialForm={statementWindow.form}
                        onSwitch={(header) => statementWindow.open(header)}
                        onClose={statementWindow.close}
                        onEdit={openEdit}
                    />
                )}
                {userForm && creating === 'user' && (
                    <UserModal
                        show
                        onClose={() => setCreating(null)}
                        user={null}
                        branches={userForm.branches}
                        canChooseBranch={userForm.canChooseBranch}
                        userTypeOptions={userForm.userTypeOptions}
                        visitOptions={returnHere}
                    />
                )}
            </Suspense>
        </AuthenticatedLayout>
    );
}
