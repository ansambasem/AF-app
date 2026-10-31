import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { usePage } from '@inertiajs/react';
import TableExportActions from '@/Components/DataTable/TableExportActions';
import { extractTable } from '@/lib/print';
import { tableExcelPart } from '@/lib/tableExcel';

function tableContainer(table) {
    return table.closest('.data-table-container') ?? (table.parentElement.matches('section, article') ? table : table.parentElement);
}

function tableToolbar(table, container) {
    const previous = container.previousElementSibling;
    return (previous?.matches('.data-table-toolbar') ? previous : null)
        ?? container.parentElement.querySelector(':scope > .data-table-toolbar')
        ?? table.closest('section, article, [role="dialog"]')?.querySelector(':scope > .data-table-toolbar');
}

function hasOwnExport(toolbar, fallback = null) {
    return [...(toolbar?.querySelectorAll('[data-excel-button]') ?? [])].some((button) => !fallback?.contains(button));
}

/** Tables without their own export action reuse the toolbar's slot or get a standalone toolbar. */
export default function TableExcelActions() {
    const canExport = usePage().props.can?.printAuditReports !== false;
    const hosts = useRef(new Map());
    const serial = useRef(0);
    const [tables, setTables] = useState([]);

    useEffect(() => {
        if (!canExport) return;
        let frame;
        function scan() {
            frame = null;
            let changed = false;
            for (const [table, entry] of hosts.current) {
                const toolbar = table.isConnected ? tableToolbar(table, tableContainer(table)) : null;
                if (!table.isConnected || !entry.host.isConnected || hasOwnExport(toolbar, entry.host)) {
                    entry.host.remove();
                    hosts.current.delete(table);
                    changed = true;
                }
            }
            document.querySelectorAll('main table, [role="dialog"] table').forEach((table, index) => {
                if (hosts.current.has(table) || !table.getClientRects().length || !table.querySelector('thead th')) return;
                if (table.closest('.sr-only, [aria-hidden="true"], .pd-root, .pd-source, [data-excel-source="custom"]')) return;
                const container = tableContainer(table);
                const toolbar = tableToolbar(table, container);
                if (hasOwnExport(toolbar)) return;
                const slot = toolbar?.querySelector('[data-table-export-slot]');
                if (slot?.querySelector('[data-auto-table-exports]')) return;
                const section = table.closest('section, article, [role="dialog"]');
                const title = table.getAttribute('aria-label') || section?.querySelector('h2, h3')?.textContent.trim() || document.querySelector('#main-content h1, #main-content h2')?.textContent.trim() || document.title;
                const host = document.createElement('div');
                host.dataset.autoTableExports = '';
                host.className = slot ? 'flex items-center gap-2' : 'data-table-toolbar mb-2 flex justify-end gap-2';
                if (slot) slot.appendChild(host);
                else container.parentElement.insertBefore(host, container);
                const storageKey = `table:${window.location.pathname}:${table.id || table.getAttribute('aria-label') || title}:${index}`;
                hosts.current.set(table, { table, host, title, storageKey, key: `${window.location.pathname}:${serial.current++}:${title}` });
                changed = true;
            });
            if (changed) setTables([...hosts.current.values()]);
        }
        const schedule = () => { frame ??= requestAnimationFrame(scan); };
        const observer = new MutationObserver(schedule);
        observer.observe(document.body, { childList: true, subtree: true });
        scan();
        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
            for (const { host } of hosts.current.values()) host.remove();
            hosts.current.clear();
        };
    }, [canExport]);

    return tables.map(({ table, host, title, storageKey, key }) => createPortal(<TableExportActions title={title} showPrint={false} excelProps={{ storageKey, loadParts: async () => [tableExcelPart(extractTable(null, table), { name: title })] }} />, host, key));
}
