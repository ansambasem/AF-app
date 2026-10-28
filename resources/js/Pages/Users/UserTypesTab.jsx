import { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import InlineAddRow, { InlineField, inlineInputClass } from '@/Components/InlineAddRow';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import Pagination from '@/Components/DataTable/Pagination';
import SortableTh from '@/Components/DataTable/SortableTh';
import { useDataTable } from '@/hooks/useDataTable';
import { useRowClick } from '@/hooks/useRowClick';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';

/**
 * The Users page's types tab: the job titles chosen when adding users,
 * with how many users have each. Types are added — and renamed — in place
 * in the row at the foot of the list.
 */
export default function UserTypesTab({ userTypes, filters, canCreate, addRequested, onAddHandled }) {
    const { search, setSearch, sort, setPerPage } = useDataTable('/users', filters, { tab: 'types' });
    const rowClick = useRowClick();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(null);
    const nameInput = useRef(null);
    const form = useForm({ name: '' });
    const { requestDelete, deleteDialog } = useDeleteRecord('نوع المستخدم');
    const open = adding || editing !== null;

    useEffect(() => {
        if (addRequested) {
            startAdding();
            onAddHandled();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [addRequested]);

    useEffect(() => {
        if (open) {
            nameInput.current?.focus();
            nameInput.current?.select();
        }
    }, [open, editing]);

    function startAdding() {
        setEditing(null);
        form.setData('name', '');
        form.clearErrors();
        setAdding(true);
    }

    function startEditing(type) {
        setAdding(false);
        form.setData('name', type.name);
        form.clearErrors();
        setEditing(type);
    }

    function close() {
        setAdding(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    }

    function save() {
        const options = { preserveScroll: true, onSuccess: close };

        if (editing) {
            form.put(`/user-types/${editing.id}`, options);
        } else {
            form.post('/user-types', options);
        }
    }

    return (
        <>
            <DataTableToolbar search={search} onSearchChange={setSearch} placeholder="بحث بنوع المستخدم..." perPage={filters.per_page} onPerPageChange={setPerPage} total={userTypes.total} />
            <section className="data-table-container">

            <table className="data-table w-full text-sm text-start" aria-label="أنواع المستخدمين">
                <thead>
                    <tr>
                        <SortableTh column="name" label="نوع المستخدم" sortState={filters} onSort={sort} />
                        <SortableTh column="users_count" label="المستخدمون" sortState={filters} onSort={sort} />
                        <ActionsTh />
                    </tr>
                </thead>
                <tbody>
                    {userTypes.data.length === 0 ? (
                        <tr>
                            <td colSpan={3} className="text-gray-500">
                                {filters.search ? 'لا توجد أنواع مطابقة.' : 'لا توجد أنواع بعد. أضف أول نوع من الأسفل.'}
                            </td>
                        </tr>
                    ) : (
                        userTypes.data.map((type) => (
                            <tr key={type.id} className={editing?.id === type.id ? 'bg-gray-50' : undefined} {...rowClick(type.canUpdate ? () => startEditing(type) : null)}>
                                <td data-label="نوع المستخدم">
                                    <RowIdentity icon="users" name={type.name} />
                                </td>
                                <td data-label="المستخدمون" className="font-display text-gray-600">{type.usersCount ? `${type.usersCount} مستخدم` : '—'}</td>
                                <td data-actions="" className="text-end">
                                    {(type.canUpdate || type.canDelete) && (
                                        <RowActionsMenu>
                                            {type.canUpdate && <button onClick={() => startEditing(type)}>تعديل</button>}
                                            {type.canDelete && <button onClick={() => requestDelete(`/user-types/${type.id}`, type.name)}>حذف</button>}
                                        </RowActionsMenu>
                                    )}
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>

            {(canCreate || editing) && (
                <InlineAddRow
                    open={open}
                    onOpen={startAdding}
                    onCancel={close}
                    onSubmit={save}
                    processing={form.processing}
                    icon={editing ? 'pencil' : 'users'}
                    title={editing ? `تعديل «${editing.name}»` : 'نوع مستخدم جديد'}
                    submitLabel={editing ? 'حفظ' : 'إضافة'}
                    openLabel="إضافة نوع مستخدم"
                    hint="مثل: كهربائي، محصل، فني"
                >
                    <InlineField id="user_type_name" label="اسم نوع المستخدم" error={form.errors.name} className="min-w-[220px] flex-1">
                        <input
                            ref={nameInput}
                            id="user_type_name"
                            maxLength={255}
                            value={form.data.name}
                            onChange={(event) => {
                                form.setData('name', event.target.value);
                                form.clearErrors('name');
                            }}
                            placeholder="مثلاً: كهربائي"
                            aria-invalid={Boolean(form.errors.name)}
                            className={`${inlineInputClass} ${form.errors.name ? 'border-brand-500 ring-4 ring-brand-500/10 focus:border-brand-500 focus:ring-brand-500/10' : ''}`}
                        />
                    </InlineField>
                </InlineAddRow>
            )}

            </section>
            <Pagination meta={userTypes} filters={filters} baseUrl="/users" extraParams={{ tab: 'types' }} />
            {deleteDialog}
        </>
    );
}
