import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import DensityToggle from './DensityToggle';
import TableExportActions from './TableExportActions';

const PAGE_SIZES = [15, 25, 50, 100];

/**
 * The top of a table card: search (press / to jump to it, Esc to clear),
 * the result count, the print button, the row-density switch and the
 * page-size switch, with the filter row below. Print opens the print
 * designer in a new tab with the table's search, sort and filters;
 * `printable={false}` hides the button.
 */
export default function DataTableToolbar({
    search,
    onSearchChange,
    placeholder = 'بحث...',
    perPage,
    onPerPageChange,
    total,
    showSearch = true,
    filterMenu,
    printable = true,
    actions,
}) {
    const searchRef = useRef(null);
    const canPrint = usePage().props.can?.printAuditReports !== false;

    useEffect(() => {
        if (!showSearch) {
            return;
        }

        function onKeyDown(event) {
            const typing = event.target.closest?.('input, textarea, select, [contenteditable="true"]');

            if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey) {
                event.preventDefault();
                searchRef.current?.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [showSearch]);

    return (
        <div className="data-table-toolbar flex flex-wrap items-center justify-between gap-3">
            {showSearch ? (
                <div className="relative w-full sm:max-w-sm">
                    <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400" />
                    <input
                        ref={searchRef}
                        type="text"
                        aria-label={placeholder}
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape' && search) {
                                onSearchChange('');
                            }
                        }}
                        placeholder={placeholder}
                        className="block w-full py-2.5 pe-10 ps-10 text-sm"
                    />
                    <span className="kbd pointer-events-none absolute inset-y-0 end-3 my-auto h-fit">/</span>
                </div>
            ) : (
                <div />
            )}

            <div className="flex flex-wrap items-center gap-2">
                {typeof total === 'number' && (
                    <span className="inline-flex items-center gap-1.5 rounded-control border border-gray-100 bg-gray-50 px-3 py-1.5 text-sm text-gray-500">
                        <b className="font-display font-bold text-gray-900">{total.toLocaleString('en')}</b>
                        نتيجة
                    </span>
                )}
                <DensityToggle />
                <div
                    role="group"
                    aria-label="عدد الصفوف في الصفحة"
                    className="inline-flex gap-0.5 rounded-control border border-gray-100 bg-gray-50 p-[3px]"
                >
                    {PAGE_SIZES.map((size) => (
                        <button
                            key={size}
                            type="button"
                            aria-pressed={perPage === size}
                            onClick={() => onPerPageChange(size)}
                            className={`rounded-lg px-2.5 py-1 font-display text-xs font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${
                                perPage === size ? 'bg-surface text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-900'
                            }`}
                        >
                            {size}
                        </button>
                    ))}
                </div>
                <div data-table-export-slot className="ms-auto flex items-center empty:hidden">
                    {actions !== undefined ? actions : printable && canPrint && <TableExportActions />}
                </div>
            </div>

            {filterMenu}
        </div>
    );
}
