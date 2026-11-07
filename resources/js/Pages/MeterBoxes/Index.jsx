import { Fragment, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import DataTableSortControls from '@/Components/DataTable/DataTableSortControls';
import { BranchFilterBanner, splitBranchFilter } from '@/Components/BranchBanner';
import SortableTh from '@/Components/DataTable/SortableTh';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import Icon from '@/Components/Icon';
import { formatNumber } from '@/lib/format';
import { useDataTable } from '@/hooks/useDataTable';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import { useRowClick } from '@/hooks/useRowClick';
import MeterBoxModal from './MeterBoxModal';
import MeterBoxSubscriptions from './MeterBoxSubscriptions';
import './meter-boxes.css';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import { readingPrintUrl } from '@/Components/PrintButton';
import { secondaryButtonClass } from '@/Components/SecondaryButton';

const SUBSCRIPTION_DIRECTION_KEY = 'meter-box-subscription-direction';
const SUBSCRIPTION_SORT_KEY = 'meter-box-subscription-sort';
const SUBSCRIPTION_ORDERS = [
    { value: 'name:asc', label: 'الاسم: من أ إلى ي' },
    { value: 'name:desc', label: 'الاسم: من ي إلى أ' },
    { value: 'account_number:asc', label: 'رقم الحساب: تصاعدي' },
    { value: 'account_number:desc', label: 'رقم الحساب: تنازلي' },
];
let handledReload = false;

/** The order of the subscribers inside every box, as last chosen in this browser. */
function readSubscriptionOrder() {
    try {
        const sort = localStorage.getItem(SUBSCRIPTION_SORT_KEY) === 'account_number' ? 'account_number' : 'name';
        const direction = localStorage.getItem(SUBSCRIPTION_DIRECTION_KEY) === 'desc' ? 'desc' : 'asc';

        return { sort, direction };
    } catch {
        // Use the default order when browser storage is unavailable.
        return { sort: 'name', direction: 'asc' };
    }
}

function MeterBoxSummary({ summary }) {
    return (
        <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div className="relative overflow-hidden rounded-card bg-graphite-gradient p-5 text-white shadow-card">
                <span aria-hidden="true" className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" />
                <p className="text-base font-bold text-white">إجمالي الطبلونات</p>
                <p className="mt-1 text-xs text-white/70">ضمن نتائج البحث والتصفية</p>
                <p className="mt-1 font-display text-4xl font-bold">{formatNumber(summary.total)} <span className="text-sm font-semibold text-white/70">طبلون</span></p>
                <div className="mt-4 flex items-center gap-2 border-t border-white/15 pt-3 text-xs text-white/70"><Icon name="users" className="h-4 w-4" />المشتركون <b className="font-display text-sm text-white">{formatNumber(summary.subscriptions)}</b></div>
            </div>
            {[
                { icon: 'pin', label: 'مناطق الطبلونات', unit: 'منطقة', value: formatNumber(summary.regions), hint: `${formatNumber(summary.located)} طبلون ضمن هذه المناطق${summary.total > summary.located ? ` · ${formatNumber(summary.total - summary.located)} بلا منطقة` : ''}` },
                { icon: 'users', label: 'المشتركون الفعّالون', unit: 'مشترك', value: formatNumber(summary.active), hint: 'المشتركون بحالة فعّال ضمن نتائج البحث' },
                { icon: 'table', label: 'طبلونات بلا مشتركين', unit: 'طبلون', value: formatNumber(summary.empty), hint: 'طبلونات لا يرتبط بها أي مشترك' },
            ].map((stat) => <div key={stat.label} role="group" aria-label={`إحصائية ${stat.label}`} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card">
                <div className="mb-4 flex items-center gap-2.5 rounded-control bg-brand-500/[0.06] px-3 py-2.5">
                    <Icon name={stat.icon} className="h-5 w-5 shrink-0 text-brand-600" />
                    <p className="text-base font-bold leading-6 text-gray-900">{stat.label}</p>
                </div>
                <p className="flex items-baseline gap-2 text-gray-900"><b className="font-display text-3xl font-bold">{stat.value}</b><span className="text-sm font-semibold text-gray-500">{stat.unit}</span></p>
                <p className="mt-2 text-xs leading-5 text-gray-500">{stat.hint}</p>
            </div>)}
        </div>
    );
}

export default function Index({
    meterBoxes,
    canCreate,
    branches,
    canChooseBranch,
    canCreateSubArea,
    governorates,
    areas,
    subAreas,
    currentBranchAreaId,
    filters,
    filterOptions,
    branchScope,
    selectedBranch,
    summary,
    groupByRegion,
    canViewSubscriptions,
    canRecordReadings,
    canSendMessages,
}) {
    const [modalMeterBox, setModalMeterBox] = useState(null);
    const [creating, setCreating] = useState(false);
    const [expandedIds, setExpandedIds] = useState(new Set());
    const [collapsedIds, setCollapsedIds] = useState(new Set());
    const [expandingAll, setExpandingAll] = useState(false);
    const [subscriptionOrder, setSubscriptionOrder] = useState(readSubscriptionOrder);

    function sortSubscriptions(value) {
        const [sort, direction] = value.split(':');
        setSubscriptionOrder({ sort, direction });
        try {
            localStorage.setItem(SUBSCRIPTION_SORT_KEY, sort);
            localStorage.setItem(SUBSCRIPTION_DIRECTION_KEY, direction);
        } catch {
            // The order still applies to every box during this visit.
        }
    }

    useEffect(() => {
        if (handledReload) return;
        handledReload = true;
        const navigation = performance.getEntriesByType('navigation')[0];
        const current = new URL(window.location.href);
        const tableParams = [...current.searchParams.keys()].some((key) => ['search', 'sort', 'direction', 'per_page', 'page'].includes(key) || key.startsWith('filter['));
        if (navigation?.type === 'reload' && navigation.name === current.href && tableParams && !current.searchParams.has('print') && !current.searchParams.has('excel')) {
            router.get(current.pathname, {}, { replace: true, preserveState: false });
        }
    }, []);
    const rowClick = useRowClick();
    const { requestDelete, deleteDialog } = useDeleteRecord('الطبلون');
    // Grouped by region, the server lists each region's boxes together so a region doesn't split across pages.
    const groupParams = groupByRegion ? { group: 'region' } : {};
    const { search, setSearch, sort, sortBy, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/meter-boxes', filters, groupParams);
    const canPrint = usePage().props.can?.printAuditReports !== false;
    const subscriptionsPrintHref = () => readingPrintUrl('الطبلونات ومشتركوها', `/meter-boxes/subscriptions-print${window.location.search}`, { subscription_sort: subscriptionOrder.sort, subscription_direction: subscriptionOrder.direction });

    function toggleGroupByRegion() {
        router.get('/meter-boxes', { search, sort: filters.sort, direction: filters.direction, per_page: filters.per_page, filter: filterValues, group: groupByRegion ? undefined : 'region' }, { preserveState: true, preserveScroll: true, replace: true });
    }
    // The Super Admin picks the branch in the banner above the list, not among the filters; clearing those keeps it.
    const branchFilter = splitBranchFilter(branchScope, filterOptions, filterValues);
    const isExpanded = (id) => expandingAll ? !collapsedIds.has(id) : expandedIds.has(id);
    const allExpanded = meterBoxes.data.length > 0 && meterBoxes.data.every((box) => isExpanded(box.id));
    const anyExpanded = meterBoxes.data.some((box) => isExpanded(box.id));
    const regionGroups = groupByRegion
        ? [...meterBoxes.data.reduce((groups, box) => {
            const key = box.sub_area_id ?? 'none';
            if (!groups.has(key)) groups.set(key, { id: key, name: box.subAreaName || 'بلا منطقة', boxes: [] });
            groups.get(key).boxes.push(box);
            return groups;
        }, new Map()).values()]
        : [{ id: 'all', boxes: meterBoxes.data }];

    function toggleExpanded(id) {
        const change = (current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        };
        if (expandingAll) setCollapsedIds(change); else setExpandedIds(change);
    }

    function expandAll() {
        setExpandingAll(true);
        setCollapsedIds(new Set());
    }

    function collapseAll() {
        setExpandingAll(false);
        setExpandedIds(new Set());
        setCollapsedIds(new Set());
    }

    return (
        <SettingsLayout
            scope={branchScope?.name ?? null}
            header={
                <PageHeader title="الطبلونات" info={canViewSubscriptions ? 'كل طبلون برقمه ومنطقته. افتح الصف لعرض المشتركين وترتيب أسمائهم.' : null} actions={canCreate && <AddButton onClick={() => setCreating(true)}>طبلون جديد</AddButton>} />
            }
        >
            <Head title="الطبلونات" />
            {branchScope && <BranchFilterBanner scope={selectedBranch ? { ...branchScope, place: null } : branchScope} eyebrow="تعرض الآن طبلونات" group={branchFilter.branch} value={filterValues.branch_id} onChange={(value) => setFilter('branch_id', value)} />}

            {summary && <MeterBoxSummary summary={summary} />}

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو رقم الطبلون..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={meterBoxes.total}
                filterMenu={
                    <DataTableFilterMenu
                        tableKey="meter_boxes"
                        groups={branchFilter.groups}
                        values={branchFilter.values}
                        onChange={setFilter}
                        onChangeMany={setFilters}
                        onClear={() => branchFilter.clear(setFilters, clearFilters)}
                    >
                        <DataTableSortControls
                            id="meter-boxes"
                            label="ترتيب الطبلونات"
                            sort={filters.sort}
                            direction={filters.direction}
                            onChange={sortBy}
                            options={[
                                { value: 'box_number', label: 'رقم الطبلون' },
                                { value: 'name', label: 'اسم الطبلون', type: 'name' },
                                { value: 'subscriptions_count', label: 'عدد المشتركين' },
                                { value: 'created_at', label: 'تاريخ الإضافة' },
                            ]}
                        />
                        {canViewSubscriptions && <div role="group" aria-label="ترتيب مشتركي جميع الطبلونات" className="flex w-full min-w-0 flex-col gap-1.5 sm:w-52">
                            <label htmlFor="all-meter-box-subscriptions-direction" className="text-xs font-semibold text-gray-500">ترتيب المشتركين داخل الطبلون</label>
                            <SearchableSelect
                                id="all-meter-box-subscriptions-direction"
                                value={`${subscriptionOrder.sort}:${subscriptionOrder.direction}`}
                                onChange={sortSubscriptions}
                                options={SUBSCRIPTION_ORDERS}
                                clearable={false}
                                searchable={false}
                                portal
                            />
                        </div>}
                    </DataTableFilterMenu>
                }
            />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                {canViewSubscriptions && <>
                    <SecondaryButton onClick={expandAll} disabled={meterBoxes.data.length === 0 || allExpanded}><Icon name="chevron-down" className="h-4 w-4" />توسيع الكل</SecondaryButton>
                    <SecondaryButton onClick={collapseAll} disabled={!anyExpanded}><Icon name="chevron-down" className="h-4 w-4 rotate-180" />طي الكل</SecondaryButton>
                </>}
                <SecondaryButton aria-pressed={groupByRegion} onClick={toggleGroupByRegion} className={groupByRegion ? '!border-brand-500/30 !bg-brand-500/10 !text-brand-600' : ''}><Icon name="pin" className="h-4 w-4" />تجميع حسب المنطقة</SecondaryButton>
                {canViewSubscriptions && canPrint && <a href={subscriptionsPrintHref()} onClick={(event) => { event.currentTarget.href = subscriptionsPrintHref(); }} target="_blank" rel="noopener noreferrer" className={`${secondaryButtonClass} tone-print`} title="طباعة كل طبلون تحت منطقته ومشتركوه تحته، بنفس البحث والتصفية وترتيب المشتركين"><Icon name="printer" className="h-4 w-4" />طباعة الطبلونات مع المشتركين</a>}
                {canViewSubscriptions && <span className="text-xs text-gray-500">التوسيع يعرض مشتركي طبلونات الصفحة الحالية، ويستمر عند الانتقال للصفحة التالية.</span>}
            </div>

            <div className="data-table-container">
                <table className="data-table meter-box-table w-full text-sm text-start" aria-label="الطبلونات ومشتركوها">
                    <thead>
                        <tr>
                            <SortableTh column="name" label="الطبلون" sortState={filters} onSort={sort} />
                            <th>{selectedBranch ? 'منطقة الطبلون' : 'الموقع / المنطقة'}</th>
                            <th>الفرع</th>
                            <SortableTh column="subscriptions_count" label="المشتركون" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {meterBoxes.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={5}>
                                    لا توجد نتائج مطابقة.
                                </td>
                            </tr>
                        ) : (
                            regionGroups.map((region) => <Fragment key={region.id}>
                                {groupByRegion && <tr className="meter-box-region-header"><td colSpan={5}>
                                    <div className="flex flex-wrap items-center gap-3 text-sm">
                                        <Icon name="pin" className="h-5 w-5 text-brand-600" />
                                        {region.id === 'none' ? <b>{region.name}</b> : <button type="button" onClick={() => setFilter('sub_area_id', String(region.id))} className="font-bold text-gray-900 underline-offset-4 hover:text-brand-600 hover:underline" aria-label={`عرض طبلونات ${region.name}`}>{region.name}</button>}
                                        <span className="rounded-control bg-surface px-2.5 py-1 text-xs font-semibold text-gray-500">{formatNumber(region.boxes.length)} طبلون في هذه الصفحة</span>
                                    </div>
                                </td></tr>}
                                {region.boxes.map((meterBox) => (
                                <Fragment key={meterBox.id}>
                                <tr className={isExpanded(meterBox.id) ? 'meter-box-expanded' : undefined} {...rowClick(canViewSubscriptions ? () => toggleExpanded(meterBox.id) : meterBox.canUpdate ? () => setModalMeterBox(meterBox) : null)}>
                                    <td data-label="الطبلون">
                                        <RowIdentity icon="table" name={meterBox.display_name || meterBox.box_number} subtitle={meterBox.display_name ? meterBox.box_number : undefined} />
                                    </td>
                                    <td data-label={selectedBranch ? 'منطقة الطبلون' : 'الموقع / المنطقة'}>
                                        <p className="text-sm font-semibold text-gray-700">{selectedBranch ? meterBox.subAreaName || '—' : meterBox.location || meterBox.subAreaName || '—'}</p>
                                        {!selectedBranch && <p className="mt-0.5 text-xs text-gray-500">{[meterBox.governorateName, meterBox.areaName, meterBox.subAreaName].filter(Boolean).join(' / ') || '—'}</p>}
                                        {selectedBranch && meterBox.location && meterBox.location !== meterBox.subAreaName && <p className="mt-0.5 text-xs text-gray-500">{meterBox.location}</p>}
                                    </td>
                                    <td data-label="الفرع" className="text-gray-600">{meterBox.branchName}</td>
                                    <td data-label="المشتركون">
                                        <p className="text-sm font-semibold text-gray-900"><b className="font-display">{formatNumber(meterBox.subscriptionsCount)}</b> مشترك</p>
                                        {meterBox.subscriptionsCount > 0 && <>
                                            <div className="my-1.5 flex h-1.5 w-28 overflow-hidden rounded-full bg-gray-100" aria-hidden="true"><span className="bg-emerald-500" style={{ width: `${meterBox.activeSubscriptionsCount / meterBox.subscriptionsCount * 100}%` }} /></div>
                                            <p className="text-xs text-gray-500">{meterBox.activeSubscriptionsCount} فعّال · {meterBox.subscriptionsCount - meterBox.activeSubscriptionsCount} غير فعّال</p>
                                        </>}
                                    </td>
                                    <td data-actions="" className="text-end">
                                        <div className="flex items-center justify-end gap-2">
                                        {canViewSubscriptions && <button type="button" className="row-action row-action-quiet" aria-expanded={isExpanded(meterBox.id)} aria-controls={`meter-box-subscriptions-${meterBox.id}`} aria-label={`${isExpanded(meterBox.id) ? 'إخفاء' : 'عرض'} مشتركي ${meterBox.box_number}`} title="المشتركون" onClick={() => toggleExpanded(meterBox.id)}><Icon name="chevron-down" className={`h-4 w-4 transition-transform ${isExpanded(meterBox.id) ? 'rotate-180' : ''}`} /></button>}
                                        {(meterBox.canUpdate || meterBox.canDelete) && (
                                            <RowActionsMenu>
                                                {meterBox.canDelete && (
                                                    <button onClick={() => requestDelete(`/meter-boxes/${meterBox.id}`, meterBox.display_name)}>حذف</button>
                                                )}
                                                {meterBox.canUpdate && <button onClick={() => setModalMeterBox(meterBox)}>تعديل</button>}
                                            </RowActionsMenu>
                                        )}
                                        </div>
                                    </td>
                                </tr>
                                {canViewSubscriptions && isExpanded(meterBox.id) && <tr className="meter-box-detail"><td colSpan={5}>
                                    <MeterBoxSubscriptions key={`${meterBox.id}-${meterBox.subscriptionsCount}-${filters.search}-${filters.sort}`} meterBox={meterBox} sort={subscriptionOrder.sort} direction={subscriptionOrder.direction} canRecordReadings={canRecordReadings} canSendMessages={canSendMessages} />
                                </td></tr>}
                                </Fragment>
                            ))}</Fragment>)
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={meterBoxes} filters={filters} baseUrl="/meter-boxes" extraParams={groupParams} />

            <MeterBoxModal
                key={`new-${selectedBranch?.id ?? 'all'}`}
                show={creating}
                onClose={() => setCreating(false)}
                meterBox={null}
                branches={branches}
                canChooseBranch={canChooseBranch && !selectedBranch}
                canCreateSubArea={canCreateSubArea}
                branchId={selectedBranch?.id}
                governorates={governorates}
                areas={areas}
                subAreas={subAreas}
                currentBranchAreaId={selectedBranch?.area_id ?? currentBranchAreaId}
            />

            {/* Keyed by meter box id so switching who's being edited remounts
                the form with fresh initial values — useForm() only captures
                its initial data once, it won't pick up a changed `meterBox`
                prop on an already-mounted instance. */}
            {modalMeterBox && (
                <MeterBoxModal
                    key={modalMeterBox.id}
                    show
                    onClose={() => setModalMeterBox(null)}
                    meterBox={modalMeterBox}
                    branches={branches}
                    canChooseBranch={canChooseBranch && !selectedBranch}
                    canCreateSubArea={canCreateSubArea}
                    branchId={selectedBranch?.id}
                    governorates={governorates}
                    areas={areas}
                    subAreas={subAreas}
                    currentBranchAreaId={selectedBranch?.area_id ?? currentBranchAreaId}
                />
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}
