import { useHttp, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import { printUrl } from '@/lib/print';

export function readingPrintUrl(title, base, params = {}) {
    const url = new URL(printUrl(title, base));
    Object.entries(params).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            url.searchParams.delete(`${key}[]`);
            value.forEach((item) => url.searchParams.append(`${key}[]`, item));
        } else if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, value);
        }
    });
    return url.href;
}

/** Only the small print launcher loads with a list. The designer loads in its own tab. */
export default function PrintButton({ title, base, params = {}, selectedIds = [], selectionPage = null }) {
    const { can } = usePage().props;
    const http = useHttp();
    const [error, setError] = useState('');
    const opening = useRef(false);
    if (can?.printAuditReports === false) return null;
    const href = readingPrintUrl(title, base ?? window.location.href, params);

    async function printSelected() {
        if (http.processing || opening.current) return;
        const tab = window.open('about:blank', '_blank');
        if (!tab) { setError('اسمح بفتح نافذة الطباعة من المتصفح ثم أعد المحاولة.'); return; }
        tab.opener = null;
        opening.current = true;
        setError('');
        try {
            http.transform(() => ({ page: selectionPage, ids: selectedIds }));
            const result = await http.post('/print-selections');
            const url = new URL(href);
            url.searchParams.set('print_scope', 'selected');
            url.searchParams.set('print_selection', result.token);
            tab.location.replace(url.href);
        } catch {
            tab.close();
            setError('تعذّر تجهيز العناصر المحددة. حدّث الصفحة وتحقق من الاتصال ثم أعد المحاولة.');
        } finally {
            opening.current = false;
        }
    }

    return <div className="flex flex-wrap items-center gap-2">
        <a href={href} target="_blank" rel="noopener noreferrer" className={`${secondaryButtonClass} tone-print`}><Icon name="printer" className="h-4 w-4" />طباعة وتخصيص</a>
        {selectionPage && selectedIds.length > 0 && <button type="button" className={secondaryButtonClass} disabled={http.processing} onClick={printSelected}>طباعة المحدد ({selectedIds.length})</button>}
        {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
    </div>;
}
