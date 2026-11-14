import { lazy, Suspense, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import BranchBanner from '@/Components/BranchBanner';
import { useBranchView } from '@/hooks/useBranchView';
import Icon from '@/Components/Icon';
import PageHeader from '@/Components/PageHeader';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatNumber } from '@/lib/format';
import { formatWeekDay } from '@/lib/weekDays';
import PeriodAccounts from './Dashboard/PeriodAccounts';
import { AttentionItem } from './Dashboard/DashboardParts';

const BranchModal = lazy(() => import('@/Pages/Branches/BranchModal'));
const UserModal = lazy(() => import('@/Pages/Users/UserModal'));

/**
 * The home page: the branch picker first (for whoever sees every branch;
 * everyone else sees their own branch named there), then the open billing
 * period's accounts and what waits on someone — readings to approve or
 * enter, open boxes. Each part shows only to those who
 * may open its page, and follows the branch picked at the top.
 */
export default function Dashboard({
    greeting,
    scope,
    branchScope,
    filters,
    branchOptions,
    sections,
    periodAccounts,
    fieldApp,
    auth,
    canCreateBranch,
    canCreateUser,
    branchForm,
    userForm,
}) {
    const [creating, setCreating] = useState(null);
    // A save from one of the dashboard's windows comes back here, with the branch picked.
    const returnHere = { preserveState: true, headers: { 'X-Return-To': `${window.location.pathname}${window.location.search}` } };
    const { readings, boxes } = sections;
    const branchView = useBranchView();

    /** Reloads the dashboard for another branch. */
    function visit(changes) {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== null && value !== undefined && value !== ''),
        );

        router.get('/dashboard', query, { preserveScroll: true, preserveState: true, replace: true });
    }

    /** Opens the "new branch" or "new user" pop-up — the same one as on its own page — fetching its dropdown options the first time. */
    function openCreateForm(kind) {
        const optionsProp = { branch: 'branchForm', user: 'userForm' }[kind];

        if ({ branchForm, userForm }[optionsProp]) {
            setCreating(kind);
            return;
        }

        router.reload({ only: [optionsProp], onSuccess: () => setCreating(kind) });
    }

    const scopeLine = scope.company
        ? `${scope.label} · ${formatNumber(scope.branches)} ${scope.branches === 1 ? 'فرع' : 'فروع'}`
        : scope.label;

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
    ].filter(Boolean);
    const hasAnyData = Boolean(periodAccounts) || attention.length > 0;

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
                        canCreateBranch ? (
                            <AddButton onClick={() => openCreateForm('branch')}>فرع جديد</AddButton>
                        ) : canCreateUser ? (
                            <AddButton onClick={() => openCreateForm('user')}>مستخدم جديد</AddButton>
                        ) : null
                    }
                />
            }
        >
            <Head title="لوحة التحكم" />

            <div className="space-y-6">
                {/* The branch comes first: every figure below follows it. */}
                {branchScope ? (
                    <BranchBanner
                        scope={branchScope}
                        className=""
                        controls={
                            <>
                                <label className="flex w-full flex-col gap-1.5 text-xs font-semibold text-white/70 sm:w-56">
                                    الفرع
                                    <SearchableSelect
                                        value={branchView.available ? String(branchView.value) : filters.branch ?? ''}
                                        onChange={branchView.available ? branchView.changeBranch : (value) => visit({ branch: value || null })}
                                        options={branchView.available ? branchView.options : branchOptions}
                                        disabled={branchView.switching}
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
                ) : (
                    scope.label && (
                        <span className="inline-flex items-center gap-2 rounded-control border border-gray-100 bg-surface px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm">
                            <Icon name="lock" className="h-4 w-4 text-gray-400" />
                            فرعك: {scope.label}
                        </span>
                    )
                )}

                {periodAccounts && <PeriodAccounts accounts={periodAccounts} />}

                {attention.length > 0 && (
                    <section aria-label="ما ينتظر إجراءً" className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]">
                        {attention.map(({ key, ...item }) => (
                            <AttentionItem key={key} {...item} />
                        ))}
                    </section>
                )}

                {!hasAnyData && (
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
                )}
            </div>

            <Suspense fallback={<p role="status" className="fixed bottom-6 left-1/2 z-[100] -translate-x-1/2 rounded-control border border-gray-200 bg-surface px-4 py-3 text-sm text-gray-600 shadow-card">جارٍ تحميل النافذة…</p>}>
                {branchForm && creating === 'branch' && (
                    <BranchModal
                        show
                        onClose={() => setCreating(null)}
                        branch={null}
                        visitOptions={returnHere}
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
