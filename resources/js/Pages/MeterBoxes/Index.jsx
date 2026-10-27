import { Fragment, useState } from 'react';
import { Head } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import { BranchFilterBanner, splitBranchFilter } from '@/Components/BranchBanner';
import SortableTh from '@/Components/DataTable/SortableTh';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import Icon from '@/Components/Icon';
import { formatMoney, formatNumber } from '@/lib/format';
import { useDataTable } from '@/hooks/useDataTable';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import { useRowClick } from '@/hooks/useRowClick';
import MeterBoxModal from './MeterBoxModal';
import MeterBoxSubscriptions from './MeterBoxSubscriptions';
import './meter-boxes.css';
import PageHeader from '@/Components/PageHeader';

function MeterBoxSummary({ summary }) {
    return (
        <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.4fr_repeat(3,minmax(0,1fr))]">
            <div className="relative overflow-hidden rounded-card bg-graphite-gradient p-5 text-white shadow-card">
                <span aria-hidden="true" className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" />
                <p className="text-sm text-white/70">الطبلونات ضمن نتائج البحث والتصفية</p>
                <p className="mt-1 font-display text-4xl font-bold">{formatNumber(summary.total)} <span className="text-sm font-semibold text-white/70">طبلون</span></p>
                <div className="mt-4 flex items-center gap-2 border-t border-white/15 pt-3 text-xs text-white/70"><Icon name="users" className="h-4 w-4" />المشتركون <b className="font-display text-sm text-white">{formatNumber(summary.subscriptions)}</b></div>
            </div>
            {[
                { icon: 'currency', label: 'ديون المشتركين', value: summary.debt === null ? '—' : `${formatMoney(summary.debt)} ₪`, hint: summary.debt === null ? 'يتطلب صلاحية عرض المشتركين' : 'مجموع الأرصدة المستحقة، دون خصم أرصدة الدائنين', tone: 'text-red-600 dark:text-red-400' },
                { icon: 'users', label: 'المشتركون الفعّالون', value: formatNumber(summary.active), hint: 'جاهزون لتسجيل القراءات', tone: 'text-gray-900' },
                { icon: 'table', label: 'طبلونات بلا مشتركين', value: formatNumber(summary.empty), hint: 'لم يرتبط بها أي مشترك', tone: 'text-gray-900' },
            ].map((stat) => <div key={stat.label} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card">
                <span className="mb-3 flex h-9 w-9 items-center justify-center rounded-control bg-gray-50 text-gray-600"><Icon name={stat.icon} className="h-5 w-5" /></span>
                <p className="text-xs font-semibold text-gray-500">{stat.label}</p>
                <p className={`mt-1 font-display text-2xl font-bold ${stat.tone}`}>{stat.value}</p>
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
    governorates,
    areas,
    subAreas,
    currentBranchAreaId,
    filters,
    filterOptions,
    branchScope,
    summary,
    canViewSubscriptions,
    canRecordReadings,
    canSendMessages,
}) {
    const [modalMeterBox, setModalMeterBox] = useState(null);
    const [creating, setCreating] = useState(false);
    const [expandedId, setExpandedId] = useState(null);
    const rowClick = useRowClick();
    const { requestDelete, deleteDialog } = useDeleteRecord('الطبلون');
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/meter-boxes', filters);
    // The Super Admin picks the branch in the banner above the list, not among the filters; clearing those keeps it.
    const branchFilter = splitBranchFilter(branchScope, filterOptions, filterValues);

    return (
        <SettingsLayout
            scope={branchScope?.name ?? null}
            header={
                <PageHeader title="الطبلونات" info={canViewSubscriptions ? 'كل طبلون برقمه وموقعه. افتح الصف لعرض المشتركين والأرصدة.' : null} actions={canCreate && <AddButton onClick={() => setCreating(true)}>طبلون جديد</AddButton>} />
            }
        >
            <Head title="الطبلونات" />
            {branchScope && <BranchFilterBanner scope={branchScope} eyebrow="تعرض الآن طبلونات" group={branchFilter.branch} value={filterValues.branch_id} onChange={(value) => setFilter('branch_id', value)} />}

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
                    />
                }
            />

            <div className="data-table-container">
                <table className="data-table meter-box-table w-full text-sm text-start" aria-label="الطبلونات ومشتركوها">
                    <thead>
                        <tr>
                            <SortableTh column="box_number" label="الطبلون" sortState={filters} onSort={sort} />
                            <th>الموقع / المنطقة</th>
                            <th>الفرع</th>
                            <SortableTh column="subscriptions_count" label="المشتركون" sortState={filters} onSort={sort} />
                            {canViewSubscriptions ? <SortableTh column="debt" label="الديون" sortState={filters} onSort={sort} /> : <th>الديون</th>}
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {meterBoxes.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={6}>
                                    لا توجد نتائج مطابقة.
                                </td>
                            </tr>
                        ) : (
                            meterBoxes.data.map((meterBox) => (
                                <Fragment key={meterBox.id}>
                                <tr className={expandedId === meterBox.id ? 'meter-box-expanded' : undefined} {...rowClick(canViewSubscriptions ? () => setExpandedId(expandedId === meterBox.id ? null : meterBox.id) : meterBox.canUpdate ? () => setModalMeterBox(meterBox) : null)}>
                                    <td data-label="الطبلون">
                                        <RowIdentity icon="table" name={meterBox.display_name || meterBox.box_number} subtitle={meterBox.display_name ? meterBox.box_number : undefined} />
                                    </td>
                                    <td data-label="الموقع / المنطقة">
                                        <p className="text-sm font-semibold text-gray-700">{meterBox.location || meterBox.subAreaName || '—'}</p>
                                        <p className="mt-0.5 text-xs text-gray-500">{[meterBox.governorateName, meterBox.areaName, meterBox.subAreaName].filter(Boolean).join(' / ') || '—'}</p>
                                    </td>
                                    <td data-label="الفرع" className="text-gray-600">{meterBox.branchName}</td>
                                    <td data-label="المشتركون">
                                        <p className="text-sm font-semibold text-gray-900"><b className="font-display">{formatNumber(meterBox.subscriptionsCount)}</b> مشترك</p>
                                        {meterBox.subscriptionsCount > 0 && <>
                                            <div className="my-1.5 flex h-1.5 w-28 overflow-hidden rounded-full bg-gray-100" aria-hidden="true"><span className="bg-emerald-500" style={{ width: `${meterBox.activeSubscriptionsCount / meterBox.subscriptionsCount * 100}%` }} /></div>
                                            <p className="text-xs text-gray-500">{meterBox.activeSubscriptionsCount} فعّال · {meterBox.subscriptionsCount - meterBox.activeSubscriptionsCount} غير فعّال</p>
                                        </>}
                                    </td>
                                    <td data-label="الديون" className="whitespace-nowrap">
                                        <b className={`font-display text-sm ${Number(meterBox.debt) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'}`}>{meterBox.debt === null ? '—' : `${formatMoney(meterBox.debt)} ₪`}</b>
                                    </td>
                                    <td data-actions="" className="text-end">
                                        <div className="flex items-center justify-end gap-2">
                                        {canViewSubscriptions && <button type="button" className="row-action row-action-quiet" aria-expanded={expandedId === meterBox.id} aria-controls={`meter-box-subscriptions-${meterBox.id}`} aria-label={`${expandedId === meterBox.id ? 'إخفاء' : 'عرض'} مشتركي ${meterBox.box_number}`} title="المشتركون" onClick={() => setExpandedId(expandedId === meterBox.id ? null : meterBox.id)}><Icon name="chevron-down" className={`h-4 w-4 transition-transform ${expandedId === meterBox.id ? 'rotate-180' : ''}`} /></button>}
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
                                {canViewSubscriptions && expandedId === meterBox.id && <tr className="meter-box-detail"><td colSpan={6}>
                                    <MeterBoxSubscriptions key={`${meterBox.id}-${meterBox.subscriptionsCount}-${filters.search}-${filters.sort}`} meterBox={meterBox} canRecordReadings={canRecordReadings} canSendMessages={canSendMessages} />
                                </td></tr>}
                                </Fragment>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={meterBoxes} filters={filters} baseUrl="/meter-boxes" />

            <MeterBoxModal
                show={creating}
                onClose={() => setCreating(false)}
                meterBox={null}
                branches={branches}
                canChooseBranch={canChooseBranch}
                governorates={governorates}
                areas={areas}
                subAreas={subAreas}
                currentBranchAreaId={currentBranchAreaId}
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
                    canChooseBranch={canChooseBranch}
                    governorates={governorates}
                    areas={areas}
                    subAreas={subAreas}
                    currentBranchAreaId={currentBranchAreaId}
                />
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}
