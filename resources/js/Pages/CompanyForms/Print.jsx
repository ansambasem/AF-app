import { useCallback, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import CompanyFormPaper from './CompanyFormPaper';
import useCompanySource from './useCompanySource';
import { formDimensions, printPageRules } from '@/lib/companyForms';
import { downloadCompanyFormOffline } from '@/lib/companyFormOffline';
import paperCss from './CompanyForms.css?raw';
import { formatDate } from '@/lib/format';

export default function Print({ document: issued }) {
    const { layout, values, source } = issued.snapshot;
    const sourceState = useCompanySource(source);
    const [overflow, setOverflow] = useState({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const root = useRef(null);
    const onOverflow = useCallback((index, value) => setOverflow((old) => old[index] === value ? old : { ...old, [index]: value }), []);
    const pages = source ? sourceState.pages : layout.pages.map(() => { const [width, height] = formDimensions(layout); return { width, height }; });
    const rules = printPageRules(pages);
    const ready = !sourceState.loading && !sourceState.error && pages.length > 0 && !Object.values(overflow).some(Boolean);

    async function prepare(action) {
        if (!ready || busy) return;
        setBusy(true); setError('');
        try {
            await document.fonts.ready;
            await Promise.all([...root.current.querySelectorAll('img')].map((image) => image.decode()));
            if (action === 'offline') await downloadCompanyFormOffline(root.current, issued.title, paperCss, rules);
            else window.print();
        } catch (failure) { setError(failure.message || 'تعذّر تجهيز النسخة للطباعة. أعد المحاولة.'); }
        finally { setBusy(false); }
    }

    return <main ref={root} className="company-document-print mx-auto max-w-[1100px] space-y-5 p-4 sm:p-7" dir="rtl">
        <Head title={`${issued.title} — مستند ${issued.id}`} />
        <style>{rules}</style>
        <section className="cf-print-toolbar space-y-4 rounded-card border border-gray-100 bg-surface p-5 shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-4"><div><Link href="/company-forms?view=documents" className="text-sm text-gray-500 hover:text-gray-900">رجوع إلى نماذج الشركة والأرشيف</Link><h1 className="mt-2 text-2xl font-bold text-gray-900">{issued.title}</h1><p className="mt-1 text-sm text-gray-500">مستند #{issued.id} · {issued.mode === 'blank' ? 'نموذج فارغ للتعبئة اليدوية' : issued.mode === 'archive' ? 'طلب ورقي مؤرشف' : 'نسخة معبأة محفوظة'}</p></div><div className="flex flex-wrap gap-2"><PrimaryButton type="button" disabled={!ready || busy} onClick={() => prepare('print')}><Icon name="printer" className="h-4 w-4" />طباعة / حفظ PDF</PrimaryButton><SecondaryButton disabled={!ready || busy} onClick={() => prepare('offline')}><Icon name="document" className="h-4 w-4" />تنزيل نسخة دون إنترنت</SecondaryButton></div></div>
            <p className="rounded-control bg-gray-50 p-3 text-sm leading-7 text-gray-600">من نافذة الطباعة اختر الطابعة أو «حفظ PDF»، وحجم الورق المناسب، وأوقف ترويسة المتصفح وتذييله. النسخة التي تنزّلها للعمل دون إنترنت تضم الصفحات والشعار داخل ملف واحد.</p>
            {issued.paperReference && <p className="text-sm text-gray-600">رقم الطلب الورقي: <bdi>{issued.paperReference}</bdi> · التاريخ: {formatDate(issued.paperDate)}</p>}
            {issued.notes && <p className="text-sm text-gray-600">ملاحظات داخلية: {issued.notes}</p>}
            {source && <a href={`${source.url}?download=1`} className="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:underline"><Icon name="document" className="h-4 w-4" />تنزيل الملف الأصلي: {source.name}</a>}
            {(error || sourceState.error) && <p role="alert" className="text-sm text-brand-600">{error || sourceState.error}</p>}
            {Object.values(overflow).some(Boolean) && <p role="alert" className="rounded-control bg-amber-50 p-3 text-sm text-amber-800">محتوى هذه النسخة يتجاوز حدود الورقة. عدّل النموذج ثم أصدر نسخة جديدة لتجنب قص البيانات.</p>}
        </section>
        {sourceState.loading ? <div role="status" className="cf-print-toolbar animate-pulse rounded-card bg-surface p-12 text-center text-gray-500">جارٍ تجهيز صفحات المستند…</div> : pages.map((page, index) => <CompanyFormPaper key={index} layout={layout} page={layout.pages[index]} pageIndex={index} sourcePage={source ? page : null} blank={issued.mode === 'blank'} values={values} onOverflow={onOverflow} />)}
    </main>;
}
