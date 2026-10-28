import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { usePage } from '@inertiajs/react';
import ExcelButton from '@/Components/ExcelButton';
import { extractTable } from '@/lib/print';
import { tableExcelPart } from '@/lib/tableExcel';

/** Tables without the shared toolbar (including dialogs) get the same Excel action. */
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
                if (!table.isConnected) {
                    entry.host.remove();
                    hosts.current.delete(table);
                    changed = true;
                }
            }
            document.querySelectorAll('main table, [role="dialog"] table').forEach((table, index) => {
                if (hosts.current.has(table) || !table.getClientRects().length || !table.querySelector('thead th')) return;
                if (table.closest('.sr-only, [aria-hidden="true"], .pd-root, .pd-source, [data-excel-source="custom"]')) return;
                const container = table.closest('.data-table-container') ?? (table.parentElement.matches('section, article') ? table : table.parentElement);
                const toolbar = container.previousElementSibling;
                if (toolbar?.matches('.data-table-toolbar') && toolbar.querySelector('[data-excel-button]')) return;
                if (container.parentElement.querySelector(':scope > .data-table-toolbar [data-excel-button]')) return;
                const section = table.closest('section, article, [role="dialog"]');
                if (section?.querySelector(':scope > .data-table-toolbar [data-excel-button]')) return;
                const title = table.getAttribute('aria-label') || section?.querySelector('h2, h3')?.textContent.trim() || document.querySelector('#main-content h1, #main-content h2')?.textContent.trim() || document.title;
                const host = document.createElement('div');
                host.className = 'mb-2 flex justify-end gap-2';
                container.parentElement.insertBefore(host, container);
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

    return tables.map(({ table, host, title, storageKey, key }) => createPortal(<ExcelButton title={title} storageKey={storageKey} loadParts={async () => [tableExcelPart(extractTable(null, table), { name: title })]} />, host, key));
}
