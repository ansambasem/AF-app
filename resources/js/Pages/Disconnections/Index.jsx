import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import { BranchFilterBanner, splitBranchFilter } from '@/Components/BranchBanner';
import SortableTh from '@/Components/DataTable/SortableTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import KpiTile from '@/Components/KpiTile';
import Modal from '@/Components/Modal';
import FormField from '@/Components/Form/FormField';
import FormSelect from '@/Components/Form/FormSelect';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Icon from '@/Components/Icon';
import { useDataTable } from '@/hooks/useDataTable';
import { useRowClick } from '@/hooks/useRowClick';
import { formatMoney, formatNumber } from '@/lib/format';
import PageHeader from '@/Components/PageHeader';

/** A new list: its name and notes, and its branch for the Super Admin. */
function NewListModal({ branchOptions, onClose }) {
    const form = useForm({ title: '', notes: '', branch_id: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/disconnection-lists', { onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="lg" centered>
            <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="new-list-title">
                <div className="flex items-start gap-4 px-7 pb-2 pt-7">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600">
                        <Icon name="power" strokeWidth={2} />
                    </span>
                    <div>
                        <h3 id="new-list-title" className="text-lg font-bold text-gray-900">قائمة قطع جديدة</h3>
                        <p className="mt-1 text-sm text-gray-600">تُضاف إليها المشتركون يدويًا بعد إنشائها. لا يُقطع أي مشترك ولا تُرسل أي رسالة تلقائيًا.</p>
                    </div>
                </div>
                <div className="space-y-4 px-7 py-5">
                    <FormField id="list-title" label="اسم القائمة" required error={form.errors.title}>
                        <input type="text" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className="block w-full text-sm" autoFocus maxLength={150} />
                    </FormField>
                    {branchOptions.length > 0 && (
                        <FormSelect id="list-branch" label="الفرع" required value={form.data.branch_id} onChange={(value) => form.setData('branch_id', value)} options={branchOptions} placeholder="اختر الفرع" error={form.errors.branch_id} />
                    )}
                    <FormField id="list-notes" label="ملاحظات" error={form.errors.notes}>
                        <textarea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} rows={3} className="block w-full text-sm" />
                    </FormField>
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>إنشاء القائمة</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/**
 * The branch's disconnection lists: how many wait, how many are overdue,
 * and what was disconnected and charged. Each list opens to its subscribers.
 */
export default function Index({ lists, summary, canCreate, branchOptions, branchScope, filters, filterOptions }) {
    const [creating, setCreating] = useState(false);
    const rowClick = useRowClick();
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/disconnection-lists', filters);
    // The Super Admin picks the branch in the banner above the figures, not among the filters; clearing those keeps it.
    const branchFilter = splitBranchFilter(branchScope, filterOptions, filterValues);

    return (
        <AuthenticatedLayout
            scope={branchScope?.name ?? null}
            header={
                <PageHeader title="قوائم القطع" info="قوائم يديرها كل فرع يدويًا: الإدراج والمواعيد والإنذارات وتأكيد القطع الفعلي." actions={canCreate && <AddButton onClick={() => setCreating(true)}>قائمة جديدة</AddButton>} />
            }
        >
            <Head title="قوائم القطع" />
            {branchScope && <BranchFilterBanner scope={branchScope} eyebrow="تعرض الآن قوائم القطع لـ" group={branchFilter.branch} value={filterValues.branch_id} onChange={(value) => setFilter('branch_id', value)} />}

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiTile hero label="بانتظار القطع" value={formatNumber(summary.scheduled)} unit="مشترك" hint={`في ${formatNumber(summary.openLists)} قائمة مفتوحة`} />
                <KpiTile icon="warning" label="متأخر — بانتظار إجراء" value={formatNumber(summary.overdue)} unit="مشترك" hint="مرّ موعده ولم يُتخذ إجراء؛ لا يُقطع تلقائيًا" />
                <KpiTile icon="calendar" label="خلال ٧ أيام" value={formatNumber(summary.upcoming)} unit="مشترك" hint={`إنذارات هذا الشهر: ${formatNumber(summary.warningsThisMonth)}`} />
                <KpiTile icon="power" label="قُطع هذا الشهر" value={formatNumber(summary.disconnectedThisMonth)} unit="مشترك" hint={`رسوم القطع: ${formatMoney(summary.feesThisMonth)} ₪`} />
            </div>

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث باسم القائمة..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={lists.total}
                printable={false}
                filterMenu={
                    <DataTableFilterMenu tableKey="disconnection_lists" groups={branchFilter.groups} values={branchFilter.values} onChange={setFilter} onChangeMany={setFilters} onClear={() => branchFilter.clear(setFilters, clearFilters)} />
                }
            />

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start" aria-label="قوائم القطع">
                    <thead>
                        <tr>
                            <SortableTh column="title" label="القائمة" sortState={filters} onSort={sort} />
                            <th>الفرع</th>
                            <th>بانتظار القطع</th>
                            <th>أقرب موعد</th>
                            <th>تم قطعهم</th>
                            <th>رسوم القطع</th>
                            <SortableTh column="created_at" label="أنشئت" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {lists.data.length === 0 ? (
                            <tr>
                                <td colSpan={8} className="text-gray-500">لا توجد قوائم بعد.</td>
                            </tr>
                        ) : (
                            lists.data.map((list) => (
                                <tr key={list.id} {...rowClick(() => router.visit(`/disconnection-lists/${list.id}`))}>
                                    <td data-label="القائمة">
                                        <RowIdentity icon="power" name={<Link href={`/disconnection-lists/${list.id}`} className="hover:underline">{list.title}</Link>} subtitle={list.notes || `${formatNumber(list.itemsCount)} مشترك مدرج`} />
                                        {list.isClosed && <span className="mt-1 inline-block"><StatusPill tone="gray" label="مغلقة" /></span>}
                                    </td>
                                    <td data-label="الفرع" className="text-gray-600">{list.branchName}</td>
                                    <td data-label="بانتظار القطع">
                                        <b className="font-display">{formatNumber(list.scheduledCount)}</b>
                                        {list.overdueCount > 0 && <span className="ms-2"><StatusPill tone="red" label={`${formatNumber(list.overdueCount)} متأخر`} /></span>}
                                    </td>
                                    <td data-label="أقرب موعد" className="whitespace-nowrap text-gray-600" dir="ltr">{list.nextDate ?? '—'}</td>
                                    <td data-label="تم قطعهم" className="text-gray-600">{formatNumber(list.disconnectedCount)}</td>
                                    <td data-label="رسوم القطع" className="whitespace-nowrap font-semibold text-gray-700">{formatMoney(list.feesTotal)} ₪</td>
                                    <td data-label="أنشئت" className="whitespace-nowrap text-xs text-gray-500">
                                        {list.createdBy}
                                        <span className="block" dir="ltr">{list.createdAt.slice(0, 10)}</span>
                                    </td>
                                    <td data-actions="" className="text-end">
                                        <RowActionsMenu onView={() => router.visit(`/disconnection-lists/${list.id}`)} />
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={lists} filters={filters} baseUrl="/disconnection-lists" />

            {creating && <NewListModal branchOptions={branchOptions} onClose={() => setCreating(false)} />}
        </AuthenticatedLayout>
    );
}
