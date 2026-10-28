import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import Icon from '@/Components/Icon';
import BlueActionButton from '@/Components/BlueActionButton';
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
import UserModal from './UserModal';
import UserTypesModal from './UserTypesModal';

export default function Index({
    users,
    canCreate,
    branches,
    canChooseBranch,
    filters,
    filterOptions,
    userTypeOptions,
    canCreateUserType,
    userTypeItems,
}) {
    const [modalUser, setModalUser] = useState(null);
    const [creating, setCreating] = useState(false);
    const [managingTypes, setManagingTypes] = useState(false);
    const rowClick = useRowClick();
    const { requestDelete, deleteDialog } = useDeleteRecord('المستخدم');
    const { requestDelete: requestTypeDelete, deleteDialog: typeDeleteDialog } = useDeleteRecord('نوع المستخدم');
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/users', filters);

    return (
        <AuthenticatedLayout
            header={
                <>
                    <h2 className="text-3xl font-bold text-gray-900">المستخدمون</h2>
                    <div className="flex shrink-0 flex-wrap items-center gap-2 [&>button]:h-11">
                        {userTypeItems && <BlueActionButton onClick={() => setManagingTypes(true)} aria-haspopup="dialog" aria-expanded={managingTypes}><Icon name={canCreateUserType ? 'plus' : 'tag'} className="h-4 w-4" />أنواع المستخدمين</BlueActionButton>}
                        {canCreate && <AddButton onClick={() => setCreating(true)}>مستخدم جديد</AddButton>}
                    </div>
                </>
            }
        >
            <Head title="المستخدمون" />
            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو اسم المستخدم..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={users.total}
                filterMenu={
                    <DataTableFilterMenu tableKey="users" groups={filterOptions} values={filterValues} onChange={setFilter} onChangeMany={setFilters} onClear={clearFilters} />
                }
            />

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start">
                    <thead>
                        <tr>
                            <SortableTh column="name" label="الاسم" sortState={filters} onSort={sort} />
                            <SortableTh column="username" label="اسم المستخدم" sortState={filters} onSort={sort} />
                            <th>نوع المستخدم</th>
                            <th>الفرع</th>
                            <SortableTh column="is_active" label="الحالة" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {users.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={6}>
                                    لا توجد نتائج مطابقة.
                                </td>
                            </tr>
                        ) : (
                            users.data.map((user) => (
                                <tr key={user.id} {...rowClick(user.canUpdate ? () => setModalUser(user) : null)}>
                                    <td>
                                        <RowIdentity name={user.name} subtitle={user.roleLabel} status={user.is_active ? 'green' : 'gray'} />
                                    </td>
                                    <td className="text-end text-gray-600" dir="ltr">
                                        {user.username}
                                    </td>
                                    <td className="text-gray-600">{user.userTypeName ?? '—'}</td>
                                    <td className="text-gray-600">{user.isSuperAdmin ? 'كل الفروع' : (user.branchName ?? '—')}</td>
                                    <td>
                                        <StatusPill tone={user.is_active ? 'green' : 'gray'} label={user.is_active ? 'نشط' : 'متوقف'} />
                                    </td>
                                    <td className="text-end">
                                        {(user.canUpdate || user.canDelete) && (
                                            <RowActionsMenu>
                                                {user.canDelete && <button onClick={() => requestDelete(`/users/${user.id}`, user.name)}>حذف</button>}
                                                {user.canUpdate && <button onClick={() => setModalUser(user)}>تعديل</button>}
                                            </RowActionsMenu>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={users} filters={filters} baseUrl="/users" />

            {userTypeItems && <UserTypesModal show={managingTypes} onClose={() => setManagingTypes(false)} items={userTypeItems} canCreate={canCreateUserType}
                onSaved={() => setSearch('')}
                onDelete={(type) => requestTypeDelete(`/user-types/${type.id}`, type.name)} />}

            <UserModal
                show={creating}
                onClose={() => setCreating(false)}
                user={null}
                branches={branches}
                canChooseBranch={canChooseBranch}
                userTypeOptions={userTypeOptions}
                canCreateUserType={canCreateUserType}
            />

            {/* Keyed by user id so switching who's being edited remounts the
                form with fresh initial values — useForm() only captures its
                initial data once, it won't pick up a changed `user` prop on
                an already-mounted instance. */}
            {modalUser && (
                <UserModal
                    key={modalUser.id}
                    show
                    onClose={() => setModalUser(null)}
                    user={modalUser}
                    branches={branches}
                    canChooseBranch={canChooseBranch}
                    userTypeOptions={userTypeOptions}
                    canCreateUserType={canCreateUserType}
                />
            )}

            {deleteDialog}
            {typeDeleteDialog}
        </AuthenticatedLayout>
    );
}
