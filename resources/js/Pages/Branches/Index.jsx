import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import { useDataTable } from '@/hooks/useDataTable';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import { useRowClick } from '@/hooks/useRowClick';
import BranchModal from './BranchModal';
import DomainModal from './DomainModal';
import PageHeader from '@/Components/PageHeader';
import { areasCountLabel } from '@/lib/branchAreas';

export default function Index({ branches, canCreate, filters, filterOptions, canManageDomains = false, domainRoutingEnabled = false }) {
    const [domainBranch, setDomainBranch] = useState(null);
    const [modalBranch, setModalBranch] = useState(null);
    const [creating, setCreating] = useState(false);
    const rowClick = useRowClick();
    const { requestDelete, deleteDialog } = useDeleteRecord('الفرع');
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/branches', filters);

    return (
        <SettingsLayout
            header={
                <PageHeader title="الفروع" actions={canCreate && <AddButton onClick={() => setCreating(true)}>فرع جديد</AddButton>} />
            }
        >
            <Head title="الفروع" />
            {canManageDomains && !domainRoutingEnabled && <p className="mb-5 rounded-control border border-gray-200 bg-surface p-4 text-sm leading-7 text-gray-600">يمكنك تجهيز روابط الفروع هنا. تشغيلها يحتاج ربط DNS وHTTPS بالاستضافة وتفعيل توجيه الدومينات عند النشر.</p>}

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو الهاتف..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={branches.total}
                filterMenu={
                    <DataTableFilterMenu
                        tableKey="branches"
                        groups={filterOptions}
                        values={filterValues}
                        onChange={setFilter}
                        onChangeMany={setFilters}
                        onClear={clearFilters}
                    />
                }
            />

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start">
                    <thead>
                        <tr>
                            <SortableTh column="name" label="الاسم" sortState={filters} onSort={sort} />
                            <SortableTh column="phone" label="الهاتف" sortState={filters} onSort={sort} />
                            <th>المناطق</th>
                            <th>رابط الفرع</th>
                            <SortableTh column="is_active" label="الحالة" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {branches.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={6}>
                                    لا توجد نتائج مطابقة.
                                </td>
                            </tr>
                        ) : (
                            branches.data.map((branch) => (
                                <tr key={branch.id} {...rowClick(branch.canUpdate ? () => setModalBranch(branch) : null)}>
                                    <td>
                                        <RowIdentity
                                            icon="pin"
                                            name={branch.name}
                                            status={branch.is_active ? 'green' : 'gray'}
                                        />
                                    </td>
                                    <td className="text-gray-600" dir="ltr">
                                        {branch.phone}
                                    </td>
                                    <td onClick={(e) => e.stopPropagation()}>
                                        <Link href={`/branch-areas?selected=${branch.id}`} className="font-semibold text-brand-600 hover:underline">{areasCountLabel(branch.sub_areas_count ?? 0)}</Link>
                                    </td>
                                    <td onClick={(e) => e.stopPropagation()}><div className="space-y-2">
                                        {branch.domain ? <><p dir="ltr" className="text-start text-xs text-gray-600">{branch.domain}</p><StatusPill tone={branch.domain_enabled ? 'green' : 'gray'} label={branch.domain_enabled ? 'الرابط مفعّل' : 'الرابط متوقف'} /></> : <p className="text-gray-400">لم يُحدد رابط</p>}
                                        <div className="flex flex-wrap gap-3 text-xs font-semibold">{canManageDomains && <button type="button" onClick={() => setDomainBranch(branch)} className="text-brand-600 hover:underline">إدارة الرابط</button>}{domainRoutingEnabled && branch.url && <a href={branch.url} className="text-gray-700 hover:underline">فتح الفرع</a>}</div>
                                    </div></td>
                                    <td>
                                        <StatusPill tone={branch.is_active ? 'green' : 'gray'} label={branch.is_active ? 'نشط' : 'متوقف'} />
                                    </td>
                                    <td className="text-end">
                                        {(branch.canUpdate || branch.canDelete) && (
                                            <RowActionsMenu>
                                                {branch.canDelete && (
                                                    <button onClick={() => requestDelete(`/branches/${branch.id}`, branch.name)}>حذف</button>
                                                )}
                                                {branch.canUpdate && <button onClick={() => setModalBranch(branch)}>تعديل</button>}
                                            </RowActionsMenu>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={branches} filters={filters} baseUrl="/branches" />
            {domainBranch && <DomainModal key={domainBranch.id} branch={domainBranch} onClose={() => setDomainBranch(null)} routingEnabled={domainRoutingEnabled} />}

            <BranchModal show={creating} onClose={() => setCreating(false)} branch={null} />

            {/* Keyed by branch id so switching who's being edited remounts
                the form with fresh initial values — useForm() only captures
                its initial data once, it won't pick up a changed `branch`
                prop on an already-mounted instance. */}
            {modalBranch && (
                <BranchModal
                    key={modalBranch.id}
                    show
                    onClose={() => setModalBranch(null)}
                    branch={modalBranch}
                />
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}
