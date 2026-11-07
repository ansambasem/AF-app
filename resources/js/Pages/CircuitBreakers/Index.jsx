import { useState } from 'react';
import { Head } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import { useDataTable } from '@/hooks/useDataTable';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import { useRowClick } from '@/hooks/useRowClick';
import { formatShekels } from '@/lib/currency';
import CircuitBreakerModal from './CircuitBreakerModal';
import PageHeader from '@/Components/PageHeader';

export default function Index({ circuitBreakers, canCreate, filters, filterOptions }) {
    const [modalCircuitBreaker, setModalCircuitBreaker] = useState(null);
    const [creating, setCreating] = useState(false);
    const rowClick = useRowClick();
    const { requestDelete, deleteDialog } = useDeleteRecord('القاطع');
    const { setPerPage, sort, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/circuit-breakers', filters);

    return (
        <SettingsLayout
            header={
                <PageHeader title="القواطع" actions={canCreate && <AddButton onClick={() => setCreating(true)}>قاطع جديد</AddButton>} />
            }
        >
            <Head title="القواطع" />

            <DataTableToolbar
                showSearch={false}
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={circuitBreakers.total}
                filterMenu={
                    <DataTableFilterMenu
                        tableKey="circuit_breakers"
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
                            <SortableTh column="ampere" label="الأمبير" sortState={filters} onSort={sort} />
                            <SortableTh column="minimum_payment" label="الحد الأدنى للدفع (شيكل)" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {circuitBreakers.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={3}>
                                    لا توجد قواطع بعد.
                                </td>
                            </tr>
                        ) : (
                            circuitBreakers.data.map((circuitBreaker) => (
                                <tr
                                    key={circuitBreaker.id}
                                    {...rowClick(circuitBreaker.canUpdate ? () => setModalCircuitBreaker(circuitBreaker) : null)}
                                >
                                    <td>
                                        <RowIdentity icon="bolt" name={`${circuitBreaker.ampere}A`} />
                                    </td>
                                    <td className="text-end text-gray-600" dir="ltr">
                                        {formatShekels(circuitBreaker.minimum_payment)}
                                    </td>
                                    <td className="text-end">
                                        {(circuitBreaker.canUpdate || circuitBreaker.canDelete) && (
                                            <RowActionsMenu>
                                                {circuitBreaker.canDelete && (
                                                    <button
                                                        onClick={() =>
                                                            requestDelete(`/circuit-breakers/${circuitBreaker.id}`, `${circuitBreaker.ampere}A`)
                                                        }
                                                    >
                                                        حذف
                                                    </button>
                                                )}
                                                {circuitBreaker.canUpdate && (
                                                    <button onClick={() => setModalCircuitBreaker(circuitBreaker)}>تعديل</button>
                                                )}
                                            </RowActionsMenu>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={circuitBreakers} filters={filters} baseUrl="/circuit-breakers" />

            <CircuitBreakerModal show={creating} onClose={() => setCreating(false)} circuitBreaker={null} />
            {modalCircuitBreaker && (
                <CircuitBreakerModal
                    key={modalCircuitBreaker.id}
                    show
                    onClose={() => setModalCircuitBreaker(null)}
                    circuitBreaker={modalCircuitBreaker}
                />
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}
