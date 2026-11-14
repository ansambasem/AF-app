import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormSection from '@/Components/Form/FormSection';
import FormField from '@/Components/Form/FormField';
import SearchableSelect from '@/Components/SearchableSelect';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ChoiceChips from '@/Components/ChoiceChips';
import Switch from '@/Components/Switch';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CompanyFormPaper from './CompanyFormPaper';
import useCompanySource from './useCompanySource';
import { FORMAL_LETTERHEAD, MAX_FORM_PAGES, letterhead, moveFormItem } from '@/lib/companyForms';
import { PRINT_FONTS } from '@/lib/printLayout';
import PageHeader, { BackLink } from '@/Components/PageHeader';

const TYPES = [{ value: 'heading', label: 'عنوان', icon: 'document' }, { value: 'paragraph', label: 'نص', icon: 'note' }, { value: 'fields', label: 'حقول بيانات', icon: 'grid' }, { value: 'signatures', label: 'توقيعات', icon: 'pencil' }, { value: 'spacer', label: 'مسافة', icon: 'expand' }];
const FONT_OPTIONS = Object.entries(PRINT_FONTS).map(([value, font]) => ({ value, label: font.label }));
const SIDES = [{ value: 'right', label: 'يمين' }, { value: 'center', label: 'وسط' }, { value: 'left', label: 'يسار' }];
const newBlock = (type) => ({ id: crypto.randomUUID(), type, text: type === 'heading' ? 'عنوان جديد' : type === 'signatures' ? 'توقيع المشترك|توقيع الموظف' : '', align: type === 'heading' ? 'center' : 'right', bold: type === 'heading', fontSize: null, height: 8, fields: [] });

export default function Editor({ template, defaultLayout, fields }) {
    const form = useForm({ name: template?.name ?? 'طلب اشتراك جديد', category: template?.category ?? 'طلبات الاشتراك', layout: template?.layout ?? defaultLayout, version: template?.version ?? null, source: null });
    const { data, setData, errors, processing } = form;
    const [tab, setTab] = useState(template?.source ? 'import' : 'content');
    const [pageIndex, setPageIndex] = useState(0);
    const [blockId, setBlockId] = useState(data.layout.pages[0]?.blocks[0]?.id);
    const [overlayId, setOverlayId] = useState(null);
    const [fieldKey, setFieldKey] = useState('subscriber_name');
    const [customLabel, setCustomLabel] = useState('');
    const [placing, setPlacing] = useState(false);
    const [pendingFile, setPendingFile] = useState(null);
    const [fileError, setFileError] = useState('');
    const [objectUrl, setObjectUrl] = useState('');
    const [overflow, setOverflow] = useState({});
    const fileInput = useRef(null);
    const syncedSource = useRef(null);
    const source = data.source ? { url: objectUrl, name: data.source.name, mime: data.source.type || (data.source.name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'image/png') } : template?.source;
    const sourceState = useCompanySource(source?.url ? source : null);
    const layout = data.layout;
    const page = layout.pages[pageIndex] ?? layout.pages[0];
    const blockIndex = page?.blocks.findIndex((item) => item.id === blockId) ?? -1;
    const block = page?.blocks[blockIndex];
    const overlay = layout.overlays.find((item) => item.id === overlayId);
    const fieldOptions = Object.entries(fields).map(([value, label]) => ({ value, label }));
    const onOverflow = useCallback((index, value) => setOverflow((old) => old[index] === value ? old : { ...old, [index]: value }), []);

    function setLayout(next) { setData('layout', typeof next === 'function' ? next(layout) : next); }
    function sectionValue(section, key, value) { setLayout({ ...layout, [section]: { ...layout[section], [key]: value } }); }
    const head = letterhead(layout.header);
    const headValue = (key) => (value) => sectionValue('header', key, value);
    const numberInput = (id, key, min, max, step = 1) => <input id={id} type="number" min={min} max={max} step={step} value={head[key]} onChange={(event) => sectionValue('header', key, Math.min(max, Math.max(min, Number(event.target.value))))} className="w-full" />;
    const colorInput = (id, key) => <input id={id} type="color" value={head[key]} onChange={(event) => sectionValue('header', key, event.target.value)} className="h-10 w-full" />;
    function updateBlock(patch) { setLayout({ ...layout, pages: layout.pages.map((item, index) => index === pageIndex ? { ...item, blocks: item.blocks.map((item) => item.id === blockId ? { ...item, ...patch } : item) } : item) }); }
    function updateOverlay(patch) { setLayout({ ...layout, overlays: layout.overlays.map((item) => item.id === overlayId ? { ...item, ...patch } : item) }); }

    useEffect(() => {
        if (!data.source) { setObjectUrl(''); return undefined; }
        const url = URL.createObjectURL(data.source); setObjectUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [data.source]);
    useEffect(() => {
        if (!source?.url || sourceState.pages.length === 0 || syncedSource.current === source.url) return;
        syncedSource.current = source.url;
        const pages = sourceState.pages.map((_, index) => data.source ? { id: crypto.randomUUID(), blocks: [] } : layout.pages[index] ?? { id: crypto.randomUUID(), blocks: [] });
        setData('layout', { ...layout, pages });
        setPageIndex(0);
    }, [source?.url, sourceState.pages]);

    function chooseFile(file) {
        if (!file) return;
        if (file.size > 10 * 1024 * 1024 || !/\.(pdf|png|jpe?g|webp)$/i.test(file.name)) { setFileError('اختر PDF أو PNG أو JPG أو WebP بحجم 10 ميجابايت أو أقل.'); return; }
        setFileError(''); setPendingFile(file);
    }
    function importFile() {
        setData({ ...data, source: pendingFile, layout: { ...layout, header: { ...layout.header, enabled: false }, watermark: { ...layout.watermark, enabled: false }, footer: '', pages: [{ id: crypto.randomUUID(), blocks: [] }], overlays: [] } });
        setPendingFile(null); setTab('import'); setOverlayId(null); setBlockId(null); setPageIndex(0);
    }
    function addBlock(type) {
        const item = newBlock(type);
        setLayout({ ...layout, pages: layout.pages.map((itemPage, index) => index === pageIndex ? { ...itemPage, blocks: [...itemPage.blocks, item] } : itemPage) });
        setBlockId(item.id);
    }
    function addField(custom = false) {
        const key = custom ? `custom_${crypto.randomUUID().replaceAll('-', '')}` : fieldKey;
        const label = custom ? customLabel.trim() : fields[fieldKey];
        if (!label || !block || block.fields.length >= 30) return;
        updateBlock({ fields: [...block.fields, { key, label }] });
        if (custom) setCustomLabel('');
    }
    function placeField(number, x, y) {
        const custom = fieldKey === 'custom';
        if (custom && !customLabel.trim()) return;
        const item = { id: crypto.randomUUID(), page: number, key: custom ? `custom_${crypto.randomUUID().replaceAll('-', '')}` : fieldKey, label: custom ? customLabel.trim() : fields[fieldKey], x: Math.min(60, Math.max(0, Math.round(x * 10) / 10)), y: Math.min(95, Math.max(0, Math.round(y * 10) / 10)), width: 35, fontSize: 15, align: 'right' };
        setLayout({ ...layout, overlays: [...layout.overlays, item] }); setOverlayId(item.id); setPlacing(false);
    }
    function save(event) {
        event.preventDefault();
        if (processing || sourceState.loading || sourceState.error) return;
        form.post(template ? `/company-forms/${template.id}` : '/company-forms', { forceFormData: true, preserveScroll: true, onSuccess: (page) => {
            const saved = page.props.template;
            if (saved) { setData((old) => ({ ...old, version: saved.version, source: null })); syncedSource.current = null; }
        } });
    }
    const previewPages = source?.url ? sourceState.pages : layout.pages.map(() => null);

    return <AuthenticatedLayout header={<PageHeader eyebrow={<BackLink href="/company-forms">نماذج الشركة</BackLink>} title={template ? 'تخصيص النموذج' : 'نموذج جديد'} actions={<><SecondaryButton onClick={() => window.history.back()}>رجوع</SecondaryButton><PrimaryButton type="submit" form="company-form-editor" disabled={processing || sourceState.loading || Boolean(sourceState.error)}><Icon name="check" className="h-4 w-4" />{processing ? 'جارٍ الحفظ…' : 'حفظ النموذج'}</PrimaryButton></>} />}>
        <Head title="تخصيص نماذج الشركة" />
        <form id="company-form-editor" onSubmit={save} className="space-y-5">
            <div className="grid gap-4 rounded-card border border-gray-100 bg-surface p-5 shadow-card sm:grid-cols-2">
                <FormField id="company-form-name" label="اسم النموذج" required error={errors.name}><input value={data.name} onChange={(event) => setData('name', event.target.value)} className="w-full" maxLength={150} /></FormField>
                <FormField id="company-form-category" label="التصنيف" required error={errors.category}><input value={data.category} onChange={(event) => setData('category', event.target.value)} className="w-full" placeholder="طلبات الاشتراك، تغيير العداد…" maxLength={100} /></FormField>
                <InputError message={errors.version} /><InputError message={errors.layout} /><InputError message={errors['layout.overlays']} /><InputError message={errors.source} />
            </div>
            <div className="grid items-start gap-6 xl:grid-cols-[390px_minmax(0,1fr)]">
                <aside className="min-w-0 space-y-4">
                    <ChoiceChips label="أقسام التخصيص" options={[{ value: 'content', label: 'المحتوى', icon: 'note' }, { value: 'appearance', label: 'المظهر', icon: 'cog' }, { value: 'import', label: 'ملف مستورد', icon: 'document-plus' }]} value={tab} onChange={setTab} />
                    {tab === 'appearance' && <>
                        <FormSection columns={1} icon="printer" title="الورقة والخط">
                            <FormField id="company-paper-size" label="حجم الورقة"><SearchableSelect options={['A4', 'A5', 'letter'].map((value) => ({ value, label: value }))} value={layout.paper} onChange={(paper) => setLayout({ ...layout, paper })} clearable={false} searchable={false} disabled={Boolean(source?.url)} /></FormField>
                            <ChoiceChips label="اتجاه الورقة" options={[{ value: 'portrait', label: 'طولي', icon: 'portrait' }, { value: 'landscape', label: 'عرضي', icon: 'landscape' }]} value={layout.orientation} onChange={(orientation) => setLayout({ ...layout, orientation })} disabled={Boolean(source?.url)} />
                            {source?.url && <p className="text-xs text-gray-500">الملف المستورد يحافظ على مقاسات صفحاته الأصلية.</p>}
                            <FormField id="company-margin" label="هوامش المحتوى (مم)"><input type="number" min="5" max="30" value={layout.margin} onChange={(event) => setLayout({ ...layout, margin: Number(event.target.value) })} className="w-full" /></FormField>
                            <FormField id="company-font" label="حجم خط المحتوى"><input type="number" min="11" max="24" value={layout.fontSize} onChange={(event) => setLayout({ ...layout, fontSize: Number(event.target.value) })} className="w-full" /></FormField>
                        </FormSection>
                        <FormSection columns={1} icon="building" title="الترويسة والتذييل">
                            <Switch label="إظهار ترويسة الشركة" checked={layout.header.enabled} onChange={(value) => sectionValue('header', 'enabled', value)} />
                            <Switch label="إظهار شعار الشركة" checked={layout.header.logo} onChange={(value) => sectionValue('header', 'logo', value)} />
                            <ChoiceChips label="شكل الترويسة" options={[{ value: 'simple', label: 'بسيطة' }, { value: 'letterhead', label: 'رسمية: نص على جانبي الشعار' }]} value={head.style} onChange={headValue('style')} />
                            <SecondaryButton onClick={() => setLayout({ ...layout, header: { ...layout.header, ...FORMAL_LETTERHEAD, enabled: true } })}><Icon name="sparkles" className="h-4 w-4" />تطبيق ترويسة رسمية جاهزة</SecondaryButton>
                            {head.style === 'letterhead' && <ChoiceChips label="موضع الشعار" options={SIDES} value={head.logoPosition} onChange={headValue('logoPosition')} />}
                            <div className="grid grid-cols-2 gap-3">
                                <FormField id="company-logo-width" label="عرض الشعار (مم)">{numberInput('company-logo-width', 'logoWidth', 10, 60)}</FormField>
                                <FormField id="company-logo-height" label="ارتفاع الشعار (مم)">{numberInput('company-logo-height', 'logoHeight', 8, 50)}</FormField>
                            </div>
                            <FormField id="company-heading" label="اسم الشركة بالعربية"><input value={layout.header.company ?? ''} onChange={(event) => sectionValue('header', 'company', event.target.value)} className="w-full" maxLength={200} /></FormField>
                            <FormField id="company-subtitle" label="تفاصيل بالعربية (العنوان، التواصل…)"><textarea value={layout.header.subtitle ?? ''} onChange={(event) => sectionValue('header', 'subtitle', event.target.value)} className="w-full" maxLength={300} /></FormField>
                            <div className="grid grid-cols-2 gap-3">
                                <FormField id="company-arabic-font" label="خط النص العربي"><SearchableSelect options={FONT_OPTIONS} value={head.companyFont} onChange={headValue('companyFont')} clearable={false} searchable={false} /></FormField>
                                <FormField id="company-arabic-size" label="حجم اسم الشركة">{numberInput('company-arabic-size', 'companySize', 10, 36)}</FormField>
                                <FormField id="company-subtitle-size" label="حجم التفاصيل">{numberInput('company-subtitle-size', 'subtitleSize', 9, 28)}</FormField>
                                <FormField id="company-arabic-align" label="محاذاة العربي"><SearchableSelect options={SIDES} value={head.arabicAlign} onChange={headValue('arabicAlign')} clearable={false} searchable={false} /></FormField>
                            </div>
                            <FormField id="company-english" label="النص الإنجليزي (الاسم والتفاصيل)"><textarea dir="ltr" rows={3} value={layout.header.english ?? ''} onChange={(event) => sectionValue('header', 'english', event.target.value)} className="w-full" maxLength={300} placeholder={'Fares Abu Zayed Sons\nGeneral Trading & Import'} /></FormField>
                            {head.english && <div className="grid grid-cols-2 gap-3">
                                <FormField id="company-english-font" label="خط النص الإنجليزي"><SearchableSelect options={FONT_OPTIONS} value={head.englishFont} onChange={headValue('englishFont')} clearable={false} searchable={false} /></FormField>
                                <FormField id="company-english-size" label="حجم النص الإنجليزي">{numberInput('company-english-size', 'englishSize', 9, 32)}</FormField>
                                <FormField id="company-english-align" label="محاذاة الإنجليزي"><SearchableSelect options={SIDES} value={head.englishAlign} onChange={headValue('englishAlign')} clearable={false} searchable={false} /></FormField>
                            </div>}
                            <FormField id="company-text-color" label="لون نص الترويسة">{colorInput('company-text-color', 'textColor')}</FormField>
                            <ChoiceChips label="الخط أسفل الترويسة" options={[{ value: 'none', label: 'بدون' }, { value: 'single', label: 'خط واحد' }, { value: 'double', label: 'خطان' }]} value={head.ruleStyle} onChange={headValue('ruleStyle')} />
                            {head.ruleStyle !== 'none' && <div className="grid grid-cols-2 gap-3">
                                <FormField id="company-rule-width" label="سماكة الخط (بكسل)">{numberInput('company-rule-width', 'ruleWidth', 0.5, 6, 0.5)}</FormField>
                                <FormField id="company-rule-color" label={head.ruleStyle === 'double' ? 'لون الخط الأول' : 'لون الخط'}>{colorInput('company-rule-color', 'ruleColor')}</FormField>
                                {head.ruleStyle === 'double' && <>
                                    <FormField id="company-rule-color-2" label="لون الخط الثاني">{colorInput('company-rule-color-2', 'ruleColor2')}</FormField>
                                    <FormField id="company-rule-gap" label="المسافة بين الخطين (مم)">{numberInput('company-rule-gap', 'ruleGap', 0.5, 5, 0.5)}</FormField>
                                </>}
                            </div>}
                            <FormField id="company-footer" label="تذييل الورقة"><textarea value={layout.footer ?? ''} onChange={(event) => setLayout({ ...layout, footer: event.target.value })} className="w-full" maxLength={500} /></FormField>
                        </FormSection>
                        <FormSection columns={1} icon="layers" title="العلامة المائية">
                            <Switch label="إظهار العلامة المائية" checked={layout.watermark.enabled} onChange={(value) => sectionValue('watermark', 'enabled', value)} />
                            <Switch label="استخدام شعار الشركة" checked={layout.watermark.logo} onChange={(value) => sectionValue('watermark', 'logo', value)} />
                            <FormField id="company-watermark" label="النص"><input value={layout.watermark.text ?? ''} onChange={(event) => sectionValue('watermark', 'text', event.target.value)} className="w-full" maxLength={150} /></FormField>
                            <FormField id="company-opacity" label="وضوح العلامة المائية"><input type="range" min="0.02" max="0.2" step="0.01" value={layout.watermark.opacity} onChange={(event) => sectionValue('watermark', 'opacity', Number(event.target.value))} className="w-full" /></FormField>
                        </FormSection>
                    </>}
                    {tab === 'content' && <>
                        <FormSection columns={1} icon="document-plus" title="محتوى الصفحة" info="أضف عنصرًا ثم عدّل نصه أو بياناته. الأسهم تغيّر ترتيب العناصر.">
                            <div className="flex flex-wrap gap-2">{TYPES.map((item) => <SecondaryButton key={item.value} onClick={() => addBlock(item.value)} disabled={page.blocks.length >= 35}><Icon name={item.icon} className="h-4 w-4" />{item.label}</SecondaryButton>)}</div>
                            <div className="space-y-2">{page.blocks.map((item, index) => <div key={item.id} className={`flex items-center gap-1 rounded-control border p-2 ${item.id === blockId ? 'border-brand-500/40 bg-brand-500/5' : 'border-gray-200'}`}><button type="button" className="min-w-0 flex-1 truncate px-1 text-start text-sm font-semibold text-gray-800" onClick={() => setBlockId(item.id)}>{index + 1}. {TYPES.find((type) => type.value === item.type)?.label} {item.text?.slice(0, 24)}</button><button type="button" disabled={index === 0} aria-label="نقل العنصر للأعلى" className="rounded p-1 text-gray-500 disabled:opacity-30" onClick={() => setLayout({ ...layout, pages: layout.pages.map((p, i) => i === pageIndex ? { ...p, blocks: moveFormItem(p.blocks, index, -1) } : p) })}><Icon name="arrow-up" className="h-4 w-4" /></button><button type="button" disabled={index === page.blocks.length - 1} aria-label="نقل العنصر للأسفل" className="rounded p-1 text-gray-500 disabled:opacity-30" onClick={() => setLayout({ ...layout, pages: layout.pages.map((p, i) => i === pageIndex ? { ...p, blocks: moveFormItem(p.blocks, index, 1) } : p) })}><Icon name="arrow-down" className="h-4 w-4" /></button><button type="button" aria-label="حذف العنصر" className="rounded p-1 text-brand-600" onClick={() => { setLayout({ ...layout, pages: layout.pages.map((p, i) => i === pageIndex ? { ...p, blocks: p.blocks.filter((b) => b.id !== item.id) } : p) }); setBlockId(null); }}><Icon name="trash" className="h-4 w-4" /></button></div>)}</div>
                        </FormSection>
                        {block && <FormSection columns={1} icon="pencil" title="تعديل العنصر المختار">
                            {['heading', 'paragraph', 'signatures'].includes(block.type) && <FormField id="company-block-text" label={block.type === 'signatures' ? 'التوقيعات — كل توقيع في سطر' : 'النص'}><textarea rows={5} value={block.type === 'signatures' ? block.text.split('|').join('\n') : block.text} onChange={(event) => updateBlock({ text: block.type === 'signatures' ? event.target.value.replaceAll('\n', '|') : event.target.value })} className="w-full" maxLength={6000} /></FormField>}
                            {['paragraph', 'heading', 'fields'].includes(block.type) && <>
                                <FormField id="company-field-key" label="البيانات الجاهزة"><SearchableSelect options={fieldOptions} value={fieldKey === 'custom' ? '' : fieldKey} onChange={setFieldKey} clearable={false} /></FormField>
                                <SecondaryButton onClick={() => block.type === 'fields' ? addField() : updateBlock({ text: `${block.text} {${fieldKey}}` })} disabled={!fields[fieldKey]}><Icon name="plus" className="h-4 w-4" />إدراج الحقل</SecondaryButton>
                            </>}
                            {block.type === 'fields' && <>
                                <div className="space-y-2">{block.fields.map((field, index) => <div key={`${field.key}-${index}`} className="flex items-center gap-2"><input aria-label="اسم الحقل على الورقة" value={field.label} onChange={(event) => updateBlock({ fields: block.fields.map((old, i) => i === index ? { ...old, label: event.target.value } : old) })} className="min-w-0 flex-1" maxLength={100} /><button type="button" aria-label={`إزالة ${field.label}`} onClick={() => updateBlock({ fields: block.fields.filter((_, i) => i !== index) })} className="rounded-control p-2 text-brand-600"><Icon name="close" className="h-4 w-4" /></button></div>)}</div>
                                <FormField id="company-custom-field" label="حقل يدوي إضافي"><input value={customLabel} onChange={(event) => setCustomLabel(event.target.value)} placeholder="مثل: رقم الطلب الخارجي" className="w-full" maxLength={100} /></FormField><SecondaryButton disabled={!customLabel.trim() || block.fields.length >= 30} onClick={() => addField(true)}>إضافة الحقل اليدوي</SecondaryButton>
                            </>}
                            {block.type === 'spacer' ? <FormField id="company-space" label="ارتفاع المسافة (مم)"><input type="number" min="1" max="60" value={block.height} onChange={(event) => updateBlock({ height: Number(event.target.value) })} className="w-full" /></FormField> : <>
                                <ChoiceChips label="المحاذاة" options={[{ value: 'right', label: 'يمين' }, { value: 'center', label: 'وسط' }, { value: 'left', label: 'يسار' }]} value={block.align} onChange={(align) => updateBlock({ align })} />
                                <Switch label="خط عريض" checked={block.bold} onChange={(bold) => updateBlock({ bold })} />
                                <FormField id="company-block-font" label="حجم خط هذا العنصر" hint="اتركه فارغًا لاستخدام حجم النموذج."><input type="number" min="11" max="32" value={block.fontSize ?? ''} onChange={(event) => updateBlock({ fontSize: event.target.value === '' ? null : Number(event.target.value) })} className="w-full" /></FormField>
                            </>}
                        </FormSection>}
                    </>}
                    {tab === 'import' && <>
                        <FormSection columns={1} icon="document-plus" title="استيراد نموذج قديم" info="PDF أو صورة، حتى 10 ميجابايت و10 صفحات. يبقى التصميم الأصلي خلف الحقول التي تضيفها.">
                            <input ref={fileInput} type="file" accept=".pdf,.png,.jpg,.jpeg,.webp" className="sr-only" onChange={(event) => { chooseFile(event.target.files?.[0]); event.target.value = ''; }} />
                            <SecondaryButton onClick={() => fileInput.current.click()}><Icon name="document-plus" className="h-4 w-4" />{source ? 'اختيار ملف بديل' : 'اختيار الملف'}</SecondaryButton>
                            {source && <p className="break-words rounded-control bg-gray-50 px-3 py-2 text-sm text-gray-700">{source.name}</p>}<InputError message={fileError || sourceState.error} />
                        </FormSection>
                        {source?.url && <FormSection columns={1} icon="pin" title="الحقول فوق الملف" info="اختر الحقل، ثم اضغط تحديد مكانه وانقر على الورقة. تستطيع تعديل الموضع بالأرقام.">
                            <SearchableSelect options={[...fieldOptions, { value: 'custom', label: 'حقل يدوي مخصص' }]} value={fieldKey} onChange={setFieldKey} clearable={false} />
                            {fieldKey === 'custom' && <input aria-label="اسم الحقل اليدوي" value={customLabel} onChange={(event) => setCustomLabel(event.target.value)} className="w-full" placeholder="اسم الحقل" maxLength={100} />}
                            <SecondaryButton onClick={() => setPlacing(!placing)} disabled={sourceState.loading || Boolean(sourceState.error) || layout.overlays.length >= 100 || (fieldKey === 'custom' && !customLabel.trim())}><Icon name="pin" className="h-4 w-4" />{placing ? 'إلغاء التحديد على الورقة' : 'تحديد مكان الحقل'}</SecondaryButton>
                            {placing && <p role="status" className="text-sm font-semibold text-brand-600">اضغط على مكان الحقل في معاينة الورقة.</p>}
                            <SearchableSelect value={overlayId ?? ''} onChange={setOverlayId} options={layout.overlays.map((item) => ({ value: item.id, label: `${item.label} · صفحة ${item.page}` }))} placeholder="اختر حقلًا لتعديل مكانه" />
                            {overlay && <>
                                <FormField id="company-overlay-label" label="اسم الحقل"><input value={overlay.label} onChange={(event) => updateOverlay({ label: event.target.value })} className="w-full" maxLength={100} /></FormField>
                                <div className="grid grid-cols-2 gap-3">{[['x', 'من اليسار %', 0, 98], ['y', 'من الأعلى %', 0, 95], ['width', 'العرض %', 2, 100], ['fontSize', 'حجم الخط', 9, 32]].map(([key, label, min, max]) => <FormField key={key} id={`company-overlay-${key}`} label={label}><input type="number" min={min} max={max} step={key === 'fontSize' ? '1' : '0.1'} value={overlay[key]} onChange={(event) => updateOverlay({ [key]: Number(event.target.value) })} className="w-full" /></FormField>)}</div>
                                <ChoiceChips label="محاذاة الحقل" options={[{ value: 'right', label: 'يمين' }, { value: 'center', label: 'وسط' }, { value: 'left', label: 'يسار' }]} value={overlay.align} onChange={(align) => updateOverlay({ align })} />
                                <SecondaryButton onClick={() => { setLayout({ ...layout, overlays: layout.overlays.filter((item) => item.id !== overlayId) }); setOverlayId(null); }}>حذف الحقل المختار</SecondaryButton>
                            </>}
                        </FormSection>}
                    </>}
                    {Object.keys(errors).filter((key) => key.startsWith('layout.')).map((key) => <InputError key={key} message={errors[key]} />)}
                </aside>
                <section className="min-w-0 space-y-4 rounded-card border border-gray-200 bg-gray-100 p-4 sm:p-6 xl:sticky xl:top-4">
                    <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-lg font-bold text-gray-900">معاينة مباشرة</h3><p className="text-xs text-gray-500">تظهر الحقول فارغة لتعبئتها يدويًا. تُملأ عند إصدار نسخة معبأة.</p></div><div className="flex items-center gap-2"><span className="text-sm text-gray-500">الصفحة</span><div className="w-28"><SearchableSelect options={layout.pages.map((_, index) => ({ value: String(index), label: String(index + 1) }))} value={String(pageIndex)} onChange={(value) => { setPageIndex(Number(value)); setBlockId(null); }} searchable={false} clearable={false} /></div></div></div>
                    {!source?.url && <div className="flex flex-wrap gap-2"><SecondaryButton disabled={layout.pages.length >= MAX_FORM_PAGES} onClick={() => { setLayout({ ...layout, pages: [...layout.pages, { id: crypto.randomUUID(), blocks: [] }] }); setPageIndex(layout.pages.length); setBlockId(null); }}>إضافة صفحة</SecondaryButton><SecondaryButton disabled={layout.pages.length === 1} onClick={() => { setLayout({ ...layout, pages: layout.pages.filter((_, index) => index !== pageIndex) }); setPageIndex(0); setBlockId(null); }}>حذف الصفحة الحالية</SecondaryButton></div>}
                    {overflow[pageIndex] && <p role="alert" className="rounded-control border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">المحتوى أطول من الورقة. انقل بعض العناصر إلى صفحة أخرى أو قلّل المسافات قبل الطباعة.</p>}
                    {sourceState.loading ? <div role="status" className="animate-pulse rounded-card bg-surface p-12 text-center text-gray-500">جارٍ تجهيز صفحات الملف…</div> : sourceState.error ? <p role="alert" className="rounded-card bg-surface p-6 text-brand-600">{sourceState.error}</p> : previewPages.length > 0 && <CompanyFormPaper key={pageIndex} layout={layout} page={page} pageIndex={pageIndex} sourcePage={sourceState.pages[pageIndex] ?? null} blank={!source?.url} onPlace={placing ? placeField : null} selectedOverlay={overlayId} onSelectOverlay={setOverlayId} onOverflow={onOverflow} />}
                </section>
            </div>
        </form>
        <ConfirmDialog show={Boolean(pendingFile)} onConfirm={importFile} onCancel={() => setPendingFile(null)} title="استخدام الملف كنموذج أصلي؟" message="يُستبدل محتوى المسودة الحالية بصفحات الملف. بعدها تضيف الحقول في أماكنها فوق النموذج الأصلي." confirmLabel="استيراد الملف" icon="document-plus" />
    </AuthenticatedLayout>;
}
