import { useCallback, useEffect, useRef, useState } from 'react';
import { useForm, useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import FormField from '@/Components/Form/FormField';
import SearchableSelect from '@/Components/SearchableSelect';
import ChoiceChips from '@/Components/ChoiceChips';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import CompanyFormPaper from './CompanyFormPaper';
import useCompanySource from './useCompanySource';
import DateInput from '@/Components/DateInput';

const MODE_OPTIONS = [{ value: 'blank', label: 'نموذج فارغ', icon: 'document' }, { value: 'filled', label: 'نسخة معبأة', icon: 'user' }];
const ARCHIVE_LAYOUT = { paper: 'A4', orientation: 'portrait', margin: 16, fontSize: 15, header: { enabled: false }, watermark: { enabled: false }, footer: '', pages: [{ id: 'archive', blocks: [] }], overlays: [] };

export default function IssueModal({ templateId = null, mode = 'blank', onClose }) {
    const archive = mode === 'archive';
    const form = useForm({ operation_id: crypto.randomUUID(), company_form_template_id: templateId, template_version: null, mode, subscription_id: null, values: {}, title: '', paper_reference: '', paper_date: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Hebron' }).format(new Date()), notes: '', attachment: null });
    const { data, setData, errors, processing } = form;
    const [loaded, setLoaded] = useState(null);
    const [setupError, setSetupError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [contextLoading, setContextLoading] = useState(false);
    const [contextError, setContextError] = useState('');
    const [objectUrl, setObjectUrl] = useState('');
    const [fileError, setFileError] = useState('');
    const [overflow, setOverflow] = useState({});
    const fileInput = useRef(null);
    const setupHttp = useHttp();
    const contextHttp = useHttp();
    const lookupHttp = useHttp();
    const setupRef = useRef(setupHttp); setupRef.current = setupHttp;
    const contextRef = useRef(contextHttp); contextRef.current = contextHttp;
    const lookupRef = useRef(lookupHttp); lookupRef.current = lookupHttp;
    const source = archive ? (data.attachment && objectUrl ? { url: objectUrl, name: data.attachment.name, mime: data.attachment.type || (data.attachment.name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'image/png') } : null) : loaded?.template.source;
    const sourceState = useCompanySource(source);
    const layout = archive ? ARCHIVE_LAYOUT : loaded?.template.layout;
    const onOverflow = useCallback((index, value) => setOverflow((old) => old[index] === value ? old : { ...old, [index]: value }), []);
    const loadSubscriptions = useCallback(async (search) => {
        const response = await lookupRef.current.get(`/company-forms/subscriptions?${new URLSearchParams({ search })}`);
        return { options: response.options, hasMore: response.options.length === 15 };
    }, []);

    useEffect(() => {
        if (!templateId) return undefined;
        let active = true; const client = setupRef.current;
        setSetupError('');
        client.get(`/company-forms/${templateId}`).then((response) => {
            if (!active) return;
            setLoaded(response); setData((old) => ({ ...old, template_version: response.template.version, values: response.values ?? {} }));
        }).catch(() => { if (active) setSetupError('تعذّر تحميل النموذج. أعد المحاولة.'); });
        return () => { active = false; client.cancel(); };
    }, [templateId, attempt]);
    useEffect(() => {
        if (!data.subscription_id) { setContextLoading(false); setContextError(''); return undefined; }
        let active = true; const client = contextRef.current;
        setContextLoading(true); setContextError('');
        client.get(`/company-forms/subscriptions?id=${data.subscription_id}`).then((response) => {
            if (active) setData('values', response.values);
        }).catch(() => { if (active) setContextError('تعذّر تحميل بيانات الاشتراك. اختره مجددًا قبل الإصدار.'); })
            .finally(() => { if (active) setContextLoading(false); });
        return () => { active = false; client.cancel(); };
    }, [data.subscription_id]);
    useEffect(() => {
        if (!data.attachment) { setObjectUrl(''); return undefined; }
        const url = URL.createObjectURL(data.attachment); setObjectUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [data.attachment]);

    function selectAttachment(file) {
        if (!file) return;
        if (file.size > 10 * 1024 * 1024 || !/\.(pdf|png|jpe?g|webp)$/i.test(file.name)) { setFileError('اختر PDF أو صورة بحجم 10 ميجابايت أو أقل.'); return; }
        setFileError(''); setData('attachment', file);
    }
    function changeMode(next) {
        if (next === data.mode) return;
        setData({ ...data, mode: next, subscription_id: null, values: next === 'blank' ? {} : loaded?.values ?? {} });
    }
    const ready = (archive ? Boolean(data.attachment && objectUrl) : Boolean(loaded)) && !sourceState.loading && !sourceState.error && !contextLoading && !contextError && !Object.values(overflow).some(Boolean);
    function submit(event) {
        event.preventDefault();
        if (!ready || processing) return;
        form.post('/company-documents', { forceFormData: true, preserveScroll: true });
    }
    const pages = source?.url ? sourceState.pages : layout?.pages.map(() => null) ?? [];

    return <Modal show onClose={() => { if (!processing) onClose(); }} maxWidth="6xl" centered>
        <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="company-issue-title" className="flex max-h-[88dvh] min-h-0 flex-col">
            <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-5 py-5 sm:px-7"><div className="flex items-center gap-3"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><Icon name={archive ? 'history' : 'printer'} /></span><div><h3 id="company-issue-title" className="text-xl font-bold text-gray-900">{archive ? 'أرشفة طلب ورقي' : loaded?.template.name ?? 'تجهيز النموذج'}</h3><p className="mt-1 text-sm text-gray-500">{archive ? 'حفظ الورقة بعد إدخالها يدويًا، مع رقمها وتاريخها.' : 'راجع النسخة قبل حفظها وفتح الطباعة.'}</p></div></div><button type="button" disabled={processing} onClick={onClose} aria-label="إغلاق" className="rounded-control p-2 text-gray-500 hover:bg-gray-100"><Icon name="close" className="h-5 w-5" /></button></header>
            <div className="min-h-0 flex-1 overflow-y-auto bg-gray-50 px-5 py-5 sm:px-7">
                {setupError ? <div role="alert" className="space-y-3 rounded-card bg-surface p-6"><p>{setupError}</p><SecondaryButton onClick={() => setAttempt((old) => old + 1)}>إعادة المحاولة</SecondaryButton></div> : !archive && !loaded ? <div role="status" className="animate-pulse rounded-card bg-surface p-10 text-center text-gray-500">جارٍ تحميل النموذج…</div> : <div className="grid items-start gap-5 lg:grid-cols-[340px_minmax(0,1fr)]">
                    <div className="space-y-5 rounded-card border border-gray-100 bg-surface p-5">
                        {!archive && <ChoiceChips label="طريقة تعبئة النموذج" options={MODE_OPTIONS} value={data.mode} onChange={changeMode} disabled={processing} />}
                        {data.mode === 'blank' && <p className="rounded-control bg-gray-50 p-3 text-sm leading-7 text-gray-600">تُطبع الترويسة والنصوص الثابتة، وتبقى بيانات المشترك والتوقيعات فارغة للتعبئة اليدوية.</p>}
                        {(archive || data.mode === 'filled') && <FormField id="company-issue-subscription" label="الاشتراك" hint="يمكنك تعبئة نموذج لشخص لم يُسجّل بعد."><SearchableSelect value={data.subscription_id ?? ''} options={[]} loadOptions={loadSubscriptions} onChange={(next) => setData({ ...data, subscription_id: next || null, values: next ? {} : loaded?.values ?? {} })} placeholder="ابحث واختر الاشتراك" disabled={processing} /></FormField>}
                        {contextLoading && <p role="status" className="text-sm text-gray-500">جارٍ تعبئة بيانات الاشتراك…</p>}<InputError message={contextError} />
                        {data.mode === 'filled' && !contextLoading && <><p className="text-xs text-gray-500">تعديل هذه القيم يخص المستند فقط، وتبقى بيانات المشترك في النظام كما هي.</p>{loaded.fields.map((field) => <FormField key={field.key} id={`company-value-${field.key}`} label={field.label}><input value={data.values[field.key] ?? ''} onChange={(event) => setData('values', { ...data.values, [field.key]: event.target.value })} className="w-full" maxLength={3000} disabled={processing} /></FormField>)}</>}
                        {archive && <>
                            <FormField id="company-archive-title" label="عنوان المستند" required error={errors.title}><input value={data.title} onChange={(event) => setData('title', event.target.value)} className="w-full" maxLength={150} required /></FormField>
                            <FormField id="company-paper-reference" label="رقم الطلب الورقي" required error={errors.paper_reference}><input value={data.paper_reference} onChange={(event) => setData('paper_reference', event.target.value)} className="w-full" maxLength={100} required /></FormField>
                            <FormField id="company-paper-date" label="تاريخ الطلب الورقي" required error={errors.paper_date}><DateInput name="paper_date" value={data.paper_date} onChange={(value) => setData('paper_date', value)} required /></FormField>
                            <input ref={fileInput} type="file" className="sr-only" accept=".pdf,.png,.jpg,.jpeg,.webp" onChange={(event) => { selectAttachment(event.target.files?.[0]); event.target.value = ''; }} />
                            <SecondaryButton onClick={() => fileInput.current.click()}><Icon name="document-plus" className="h-4 w-4" />{data.attachment ? 'استبدال الملف' : 'اختيار صورة الطلب أو PDF'}</SecondaryButton>
                            <p className="break-words text-xs text-gray-500">{data.attachment?.name ?? 'حتى 10 ميجابايت و10 صفحات.'}</p><InputError message={errors.attachment || fileError} />
                        </>}
                        <FormField id="company-document-notes" label="ملاحظات داخلية" error={errors.notes}><textarea value={data.notes} onChange={(event) => setData('notes', event.target.value)} className="w-full" rows={3} maxLength={2000} /></FormField>
                        {Object.entries(errors).filter(([key]) => !['notes', 'title', 'paper_reference', 'paper_date', 'attachment'].includes(key)).map(([key, message]) => <InputError key={key} message={message} />)}
                    </div>
                    <div className="min-w-0 rounded-card border border-gray-200 bg-gray-100 p-3 sm:p-5">
                        <h4 className="mb-4 text-base font-bold text-gray-900">معاينة النسخة</h4>
                        {sourceState.loading ? <p role="status" className="py-10 text-center text-sm text-gray-500">جارٍ تجهيز الصفحات…</p> : sourceState.error ? <p role="alert" className="text-sm text-brand-600">{sourceState.error}</p> : archive && !source ? <p className="py-12 text-center text-sm text-gray-500">اختر الملف لمعاينة الورقة.</p> : pages.map((sourcePage, index) => <CompanyFormPaper key={index} layout={layout} page={layout.pages[index]} sourcePage={sourcePage} pageIndex={index} blank={data.mode === 'blank'} values={data.values} onOverflow={onOverflow} />)}
                        {Object.values(overflow).some(Boolean) && <p role="alert" className="rounded-control bg-amber-50 p-3 text-sm text-amber-800">المحتوى يتجاوز حدود الورقة. عدّل النموذج أو قصّر القيم قبل إصدار النسخة.</p>}
                    </div>
                </div>}
            </div>
            <footer className="flex shrink-0 items-center justify-end gap-3 border-t border-gray-100 bg-surface px-5 py-4 sm:px-7"><SecondaryButton disabled={processing} onClick={onClose}>إلغاء</SecondaryButton><PrimaryButton type="submit" disabled={!ready || processing}><Icon name={archive ? 'check' : 'printer'} className="h-4 w-4" />{processing ? 'جارٍ الحفظ…' : archive ? 'حفظ الطلب الورقي' : 'حفظ النسخة وفتح الطباعة'}</PrimaryButton></footer>
        </form>
    </Modal>;
}
