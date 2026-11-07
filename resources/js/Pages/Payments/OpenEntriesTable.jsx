import { Link, router } from '@inertiajs/react';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import ColumnWidths, { fixedTableStyle } from '@/Components/DataTable/ColumnWidths';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import Pagination from '@/Components/DataTable/Pagination';
import StatusPill from '@/Components/DataTable/StatusPill';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { boxTypeStyles } from './CollectorCashBox';
import { entryStatus } from '@/lib/financialBoxes';
import { ledgerColumns, ledgerOf, ledgerTableClass, LedgerLeadCells, LedgerTrailCells } from './LedgerColumns';

/**
 * Every entry of the open boxes in one table, each as the subscriber's
 * statement shows it and with the number of the box it sits in, searched
 * and narrowed to one type of box (cash, bank transfers or clearings), and
 * to one person's boxes when `people` are given. `table` is the page's
 * useDataTable, so the page may set the filters too (a box card picks its
 * type); `extraParams` go with every page link.
 */
export default function OpenEntriesTable({ table, entries, filters, counts, url, extraParams = {}, people = null, label }) {
    const { search, setSearch, setPerPage, filterValues, setFilter } = table;
    const type = filterValues.type ?? '';
    const typeFilters = [
        { value: '', label: `كل الحركات · ${counts.all}` },
        ...Object.entries(boxTypeStyles).map(([value, style]) => ({ value, label: `${style.label} · ${counts[value] ?? 0}` })),
    ];
    const columns = [['رقم الصندوق', 76], ...ledgerColumns.before, ['المشترك', 150], ...ledgerColumns.after, ['الحالة', 140], ['', 48]];
    const columnWidths = columns.map(([, width]) => width);

    return <section aria-label={label} className="financial-surface space-y-4">
        <DataTableToolbar
            search={search}
            onSearchChange={setSearch}
            placeholder="ابحث بالمشترك أو رقم الحساب أو السند أو رقم الصندوق"
            perPage={filters.per_page}
            onPerPageChange={setPerPage}
            total={entries.total}
            filterMenu={<div className="flex w-full flex-wrap items-center gap-3"><SegmentedTabs label="نوع الصندوق" value={type} onChange={(value) => setFilter('type', value)} options={typeFilters} />{people && <select aria-label="الموظف" value={filterValues.user ?? ''} onChange={(event) => setFilter('user', event.target.value)} className="py-2 text-sm sm:w-56"><option value="">كل الموظفين</option>{people.map((person) => <option key={person.id} value={String(person.id)}>{person.name}</option>)}</select>}</div>}
        />
        <div className="data-table-container financial-ledger-container">
            <table className={ledgerTableClass} style={fixedTableStyle(columnWidths)}>
                <ColumnWidths widths={columnWidths} />
                <thead>
                    <tr>{columns.map(([heading], index) => index === columns.length - 1 ? <ActionsTh key={index} /> : <th key={index}>{heading}</th>)}</tr>
                </thead>
                <tbody>
                    {entries.data.length === 0 ? (
                        <tr><td colSpan={columns.length} className="text-center text-gray-500">{search || type || filterValues.user ? 'لا توجد حركات بهذا البحث.' : 'لا توجد حركات في الصناديق المفتوحة.'}</td></tr>
                    ) : entries.data.map((entry) => {
                        const [status, tone] = entryStatus(entry);
                        const cancelled = entry.transactionStatus !== 'active';
                        const ledger = ledgerOf(entry);

                        return <tr key={entry.id} className={cancelled ? 'opacity-60' : undefined}>
                            <td style={{ whiteSpace: 'normal' }}>
                                <Link href={entry.box.url} prefetch className="font-semibold text-brand-600 hover:underline">صندوق <bdi dir="ltr" className="font-display">{entry.box.number}</bdi></Link>
                                <span className="block text-xs text-gray-400">{boxTypeStyles[entry.box.type].label}</span>
                            </td>
                            <LedgerLeadCells ledger={ledger} />
                            <td title={entry.subscriber} style={{ whiteSpace: 'normal' }}>
                                {entry.statementUrl ? (
                                    <Link href={entry.statementUrl} prefetch title="فتح كشف الحساب عند هذه الحركة" className="font-semibold text-gray-900 hover:text-brand-600 hover:underline">{entry.subscriber}</Link>
                                ) : (
                                    <span className="font-semibold text-gray-900">{entry.subscriber}</span>
                                )}
                                <span className="block font-display text-xs text-gray-400"><bdi dir="ltr">{entry.accountNumber}</bdi></span>
                            </td>
                            <LedgerTrailCells ledger={ledger} amount={entry.amount} currency={entry.currency} cancelled={cancelled} />
                            <td style={{ whiteSpace: 'normal' }}><StatusPill tone={tone} label={status} />{entry.isLate && <span className="mt-1 block"><StatusPill tone="amber" label="متأخرة" /></span>}</td>
                            <td className="text-end">{(entry.receiptUrl || entry.statementUrl) && (
                                <RowActionsMenu>
                                    {entry.statementUrl && <button onClick={() => router.visit(entry.statementUrl)}>كشف الحساب</button>}
                                    {entry.receiptUrl && <button onClick={() => window.open(entry.receiptUrl, '_blank', 'noopener')}>طباعة السند</button>}
                                </RowActionsMenu>
                            )}</td>
                        </tr>;
                    })}
                </tbody>
            </table>
        </div>
        <Pagination meta={entries} filters={{ search, per_page: filters.per_page, filter: filterValues }} extraParams={extraParams} baseUrl={url} />
    </section>;
}
