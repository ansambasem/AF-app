import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import ExcelStudio from '@/Components/ExcelStudio';
import SecondaryButton from '@/Components/SecondaryButton';
import { extractSummary, extractTable, paginatedProp } from '@/lib/print';
import { appendExcelPage, tableExcelPart } from '@/lib/tableExcel';

/** Read the same authorized table as printing, one bounded server page at a time. */
export default function TableExcelDesigner({ settings, children }) {
    const { props } = usePage();
    const source = useRef(null);
    const collected = useRef(null);
    const lastProps = useRef(null);
    const [ready, setReady] = useState(null);
    const [error, setError] = useState('');
    const [count, setCount] = useState(0);
    const [open, setOpen] = useState(true);
    const title = settings.title || document.title;
    const [meta, setMeta] = useState({});

    useEffect(() => {
        if (lastProps.current === props || ready || error) return;
        const frame = requestAnimationFrame(() => {
            lastProps.current = props;
            try {
                const provided = props.printData;
                const table = provided ? { columns: provided.columns, rows: provided.rows } : extractTable(source.current);
                if (!table) throw new Error('لا يوجد جدول قابل للتصدير في هذه الصفحة.');
                const page = appendExcelPage(collected.current, table, provided ? provided.pagination ?? { current_page: 1, total: provided.total } : paginatedProp(props));
                collected.current = page;
                setCount(page.rows.length);
                if (page.next) {
                    const next = new URL(page.next, window.location.origin);
                    next.searchParams.set('print', '1');
                    next.searchParams.set('print_all', '1');
                    next.searchParams.set('excel', '1');
                    router.get(next.href, {}, {
                        preserveState: true, preserveScroll: true,
                        onError: () => setError('تعذّر تحميل بقية النتائج. أغلق المحرر وأعد المحاولة.'),
                        onNetworkError: () => setError('انقطع الاتصال أثناء تحميل النتائج. أعد المحاولة.'),
                    });
                } else {
                    const branch = props.branchScope?.name ?? props.subscription?.branchName ?? props.auth?.user?.branchName;
                    const summary = provided?.summary ?? extractSummary(source.current);
                    setMeta({
                        lines: [branch, summary].filter(Boolean),
                        criteria: [['الفرع', branch ?? ''], ['بحث', props.filters?.search ?? ''], ['عدد السجلات', page.rows.length], ['ملخص الجدول', summary], ['أعدّه', props.auth?.user?.name ?? ''], ['تاريخ الإعداد', new Date().toLocaleString('en-GB')]],
                    });
                    setReady([tableExcelPart(page, { name: title })]);
                }
            } catch (failure) {
                setError(failure.message || 'تعذّر تجهيز ملف Excel. أعد المحاولة.');
            }
        });
        return () => cancelAnimationFrame(frame);
    }, [props, ready, error, title]);

    return <div dir="rtl" className="min-h-screen bg-gray-50 p-6 text-gray-900">
        <div className="mx-auto max-w-3xl space-y-4 rounded-panel border border-gray-100 bg-surface p-6">
            <h1 className="text-xl font-bold">{title} — Excel</h1>
            {error ? <p role="alert" className="text-sm text-red-600">{error}</p> : !ready ? <p role="status" className="text-sm text-gray-500">جارٍ تحميل جميع النتائج المطابقة… {count.toLocaleString('en')} سجل</p> : <SecondaryButton onClick={() => setOpen(true)}>فتح محرر Excel</SecondaryButton>}
            <SecondaryButton onClick={() => window.close()}>إغلاق</SecondaryButton>
        </div>
        {ready && <ExcelStudio show={open} onClose={() => setOpen(false)} title={`تصدير ${title} إلى Excel`} storageKey={`table:${window.location.pathname}`} templateKind="tables" loadParts={async () => ready} defaults={{ title, fileName: title }} meta={meta} />}
        {!props.printData && <div ref={source} className="pd-source" aria-hidden="true" inert>{children}</div>}
    </div>;
}
