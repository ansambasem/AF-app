import { lazy, Suspense, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import BranchBanner from '@/Components/BranchBanner';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import PageHeader from '@/Components/PageHeader';
import SearchableSelect from '@/Components/SearchableSelect';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { useStatementWindow } from '@/hooks/useStatementWindow';
import { formatMoney, formatNumber, percentOf } from '@/lib/format';
import { formatWeekDay } from '@/lib/weekDays';
import { AttentionItem, PendingPanel, PendingTile, READING_PARTS, SplitBar, TileLink } from './Dashboard/DashboardParts';
import {
    BreakersPanel,
    CollectionRankPanel,
    CollectionsTile,
    CollectionTrendPanel,
    LatestPayments,
    QuickActions,
    TopDebtorsPanel,
    WeekReadingsPanel,
} from './Dashboard/DashboardPanels';

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
    branchScope,
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
            scope={branchScope?.name ?? null}
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
                    {branchScope && (
                        <BranchBanner
                            scope={branchScope}
                            meta={`آخر ${period}`}
                            className=""
                            controls={
                                <>
                                    {governorateOptions.length > 0 && (
                                        <label className="flex w-full flex-col gap-1.5 text-xs font-semibold text-white/70 sm:w-56">
                                            المحافظة
                                            <SearchableSelect
                                                value={filters.governorate ?? ''}
                                                onChange={(value) => visit({ governorate: value || null, branch: null })}
                                                options={governorateOptions}
                                                placeholder="كل المحافظات"
                                                searchable={governorateOptions.length > 6}
                                                active={Boolean(filters.governorate)}
                                                portal
                                                onDark
                                            />
                                        </label>
                                    )}
                                    <label className="flex w-full flex-col gap-1.5 text-xs font-semibold text-white/70 sm:w-56">
                                        الفرع
                                        <SearchableSelect
                                            value={filters.branch ?? ''}
                                            onChange={(value) => visit({ branch: value || null })}
                                            options={branchOptions.filter((branch) => !filters.governorate || String(branch.governorate) === String(filters.governorate))}
                                            placeholder="كل الفروع"
                                            searchPlaceholder="ابحث عن فرع..."
                                            active={Boolean(filters.branch)}
                                            portal
                                            onDark
                                        />
                                    </label>
                                </>
                            }
                        />
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                        <SegmentedTabs
                            label="الفترة"
                            value={filters.days}
                            onChange={(days) => visit({ days })}
                            options={periods.map((days) => ({ value: days, label: periodLabel(days) }))}
                        />
                        {/* The head of the company picks the governorate and branch in the banner above; everyone else sees their own branch here. */}
                        {!scope.company && (
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
                                <CollectionsTile collections={collections} period={period} />
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
                                <QuickActions quickActions={quickActions} lastActionSpans={lastActionSpans} />
                            )}

                            {collections && (
                                <CollectionTrendPanel collections={collections} period={period} chartView={chartView} onChartViewChange={setChartView} />
                            )}
                        </div>
                    )}

                    {(debts !== null || hasBreakers) && (
                        <div className={`grid grid-cols-1 gap-5 ${debts !== null && hasBreakers ? 'lg:grid-cols-2' : ''}`}>
                            {debts === undefined ? (
                                <PendingPanel />
                            ) : (
                                debts && (
                                    <TopDebtorsPanel debts={debts} can={can} onOpenStatement={openStatement} />
                                )
                            )}

                            {hasBreakers && (
                                <BreakersPanel subscriptions={subscriptions} breakerMax={breakerMax} />
                            )}
                        </div>
                    )}

                    {(rankList || readings) && (
                        <div className={`grid grid-cols-1 gap-5 ${rankList && readings ? 'lg:grid-cols-2' : ''}`}>
                            {rankList && (
                                <CollectionRankPanel collections={collections} rankList={rankList} rankMax={rankMax} period={period} />
                            )}

                            {readings && (
                                <WeekReadingsPanel readings={readings} can={can} />
                            )}
                        </div>
                    )}

                    {payments && (
                        <LatestPayments payments={payments} latestPayments={latestPayments} period={period} search={search} onSearchChange={setSearch} onVisit={visit} filters={filters} scope={scope} can={can} canOpenReceipts={canOpenReceipts} onOpenStatement={openStatement} />
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
                        canCreateUserType={userForm.canCreateUserType}
                        visitOptions={returnHere}
                    />
                )}
            </Suspense>
        </AuthenticatedLayout>
    );
}
