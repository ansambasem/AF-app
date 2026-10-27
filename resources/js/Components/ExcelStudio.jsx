import { useEffect, useId, useMemo, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { DialogHeader } from '@/Components/DialogFrame';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Switch from '@/Components/Switch';
import TextInput from '@/Components/TextInput';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { buildWorkbookModel, COLUMN_WIDTH_RANGE, columnLetter, columnWidth, defaultExcelSettings, EXCEL_FONT_SIZES, EXCEL_ORIENTATIONS, EXCEL_ROW_HEIGHTS, EXCEL_THEMES, excelFileName, excelLook, fitExcelSettings, formatExcelCell } from '@/lib/excelSheet';

const PREVIEW_ROWS = 80;

const LOOK = [
    ['header', 'العنوان والفرع والفترة أعلى الورقة'],
    ['totals', 'صف الإجمالي', 'ومجموع لكل مجموعة عند التجميع'],
    ['formulas', 'معادلات حيّة', 'الإجماليات SUBTOTAL تتحدّث عند التعديل أو التصفية في Excel'],
    ['freeze', 'تثبيت صف العناوين'],
    ['autofilter', 'فلتر على الأعمدة'],
    ['rtl', 'الورقة من اليمين إلى اليسار'],
    ['zebra', 'صفوف متناوبة اللون'],
    ['currency', 'رمز الشيكل في الخلايا', 'أوقفه إن كانت الأرقام ستُنقل إلى نظام محاسبي'],
    ['pageSetup', 'جاهز للطباعة من Excel', 'A4 بعرض الصفحة، وصف العناوين وترقيم الصفحات في كل صفحة'],
    ['criteria', 'ورقة «معايير التقرير»', 'الفرع والفترة والتصفية، ومن أعدّ الملف ومتى'],
];

/** Settings kept in this browser: the last export of each kind, and the templates saved for it. */
const store = {
    read(key, fallback) {
        try {
            const value = window.localStorage.getItem(key);
            return value ? JSON.parse(value) : fallback;
        } catch {
            return fallback;
        }
    },
    write(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Private browsing: the settings simply aren't remembered.
        }
    },
};

/**
 * The Excel editor: the parts a page can export (see lib/excelSheet.js),
 * which of them the file holds, each part's columns and their order, how
 * its rows are grouped, the title and the file's name and look — beside a
 * preview of the sheets as Excel will show them — then a formatted .xlsx.
 * `loadParts` resolves the parts (a page may fetch rows for them); the
 * settings are kept per `storageKey`, with templates saved under names in
 * this browser, and the company's own templates (ExcelTemplateController)
 * under the same key — the default of which is where someone with no
 * settings of their own starts.
 */
export default function ExcelStudio({ show, onClose, title, storageKey, loadParts, defaults, meta = {} }) {
    const id = useId();
    const [parts, setParts] = useState(null);
    const [failed, setFailed] = useState(false);
    const [loadedRows, setLoadedRows] = useState(0);
    const [settings, setSettings] = useState(null);
    const [editing, setEditing] = useState(null);
    const [sheetIndex, setSheetIndex] = useState(0);
    const [activeCell, setActiveCell] = useState(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [templateName, setTemplateName] = useState('');
    const [templates, setTemplates] = useState([]);
    const [tab, setTab] = useState('settings');
    const [company, setCompany] = useState({ templates: [], canManage: false });
    const [companyName, setCompanyName] = useState('');
    const [companyDefault, setCompanyDefault] = useState(false);
    const listHttp = useHttp({});
    const saveHttp = useHttp({});

    useEffect(() => {
        if (!show) {
            return;
        }

        let cancelled = false;
        const controller = new AbortController();
        setParts(null);
        setFailed(false);
        setLoadedRows(0);
        setError('');
        setTemplates(store.read(`excel-templates:${storageKey}`, []));
        const companyTemplates = listHttp.get(`/excel-templates/${storageKey}`).catch(() => ({ templates: [], canManage: false }));
        Promise.all([loadParts({ signal: controller.signal, onProgress: setLoadedRows }), companyTemplates])
            .then(([loaded, shared]) => {
                if (cancelled) return;
                const base = defaultExcelSettings(loaded, defaults);
                const own = store.read(`excel-settings:${storageKey}`, null);
                setCompany(shared);
                setParts(loaded);
                setSettings(fitExcelSettings(own ?? shared.templates.find((template) => template.isDefault)?.settings ?? null, loaded, base));
                setEditing(loaded.find((part) => part.columns.length > 2)?.key ?? loaded[0]?.key ?? null);
                setSheetIndex(0);
                setActiveCell(null);
            })
            .catch(() => !cancelled && setFailed(true));

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [show]);

    const sheets = useMemo(() => (parts && settings ? buildWorkbookModel(parts, settings, meta) : []), [parts, settings, meta]);
    const sheet = sheets[Math.min(sheetIndex, Math.max(sheets.length - 1, 0))] ?? null;
    const firstFormula = useMemo(() => {
        for (const row of sheet?.rows ?? []) {
            const index = row.cells.findIndex((cell) => cell.f);
            if (index >= 0) return { address: `${columnLetter(index)}${row.row}`, formula: row.cells[index].f };
        }
        return null;
    }, [sheet]);
    const shownCell = activeCell ?? firstFormula;
    const part = parts?.find((candidate) => candidate.key === editing) ?? null;
    const partSettings = part ? settings.parts[part.key] : null;
    const fileName = settings ? excelFileName(settings.fileName) : '';

    const change = (patch) => setSettings((current) => ({ ...current, ...patch }));
    const changePart = (key, patch) => setSettings((current) => ({ ...current, parts: { ...current.parts, [key]: { ...current.parts[key], ...patch } } }));

    function moveColumn(index, step) {
        const columns = [...partSettings.columns];
        const target = index + step;
        if (target < 0 || target >= columns.length) return;
        [columns[index], columns[target]] = [columns[target], columns[index]];
        changePart(part.key, { columns });
    }

    function setColumnWidth(key, width) {
        changePart(part.key, { columns: partSettings.columns.map((candidate) => (candidate.key === key ? { ...candidate, width } : candidate)) });
    }

    /** The layout of a template laid over this export, keeping its own title and file name. */
    function applyTemplate(saved) {
        setSettings((current) => fitExcelSettings({ ...saved, title: current.title, fileName: current.fileName }, parts, { ...defaultExcelSettings(parts, defaults), title: current.title, fileName: current.fileName }));
    }

    /** The settings as a template keeps them: everything but this file's title and name. */
    function layout() {
        const { title: _title, fileName: _fileName, ...look } = settings;

        return look;
    }

    async function reloadCompany() {
        setCompany(await listHttp.get(`/excel-templates/${storageKey}`));
    }

    async function sendCompany(method, url, data) {
        saveHttp.clearErrors();
        saveHttp.transform(() => data);
        try {
            const response = await saveHttp[method](url);
            if (!response) return false;
            await reloadCompany();

            return true;
        } catch {
            return false;
        }
    }

    async function saveCompanyTemplate() {
        const name = companyName.trim();
        if (!name) return;
        if (await sendCompany('post', '/excel-templates', { kind: storageKey, name, is_default: companyDefault, settings: layout() })) {
            setCompanyName('');
            setCompanyDefault(false);
        }
    }

    function saveTemplate() {
        const name = templateName.trim();
        if (!name) return;
        const next = [...templates.filter((template) => template.name !== name), { name, settings: layout() }];
        setTemplates(next);
        store.write(`excel-templates:${storageKey}`, next);
        setTemplateName('');
    }

    function removeTemplate(name) {
        const next = templates.filter((template) => template.name !== name);
        setTemplates(next);
        store.write(`excel-templates:${storageKey}`, next);
    }

    async function download() {
        setSaving(true);
        setError('');
        try {
            const { workbookBlob, saveBlob } = await import('@/lib/excelWorkbook');
            saveBlob(await workbookBlob(sheets, settings), fileName);
            store.write(`excel-settings:${storageKey}`, layout());
            onClose();
        } catch {
            setError('تعذّر إنشاء الملف. تحقّق من الاتصال وأعد المحاولة.');
        } finally {
            setSaving(false);
        }
    }

    const rowsCount = sheets.reduce((total, current) => total + (current.key === 'criteria' ? 0 : current.count), 0);

    return (
        <Modal show={show} onClose={saving ? undefined : onClose} maxWidth="full" panelClassName="sm:max-w-[1280px]">
            <div role="dialog" aria-modal="true" aria-labelledby={`${id}-title`} dir="rtl" className="flex h-[min(92dvh,900px)] min-h-0 flex-col">
                <DialogHeader titleId={`${id}-title`} title={title} description="ملف ‎.xlsx منسّق: اختر الأجزاء والأعمدة والعنوان، وشاهد الورقة قبل التنزيل." icon="excel" tone="primary" onClose={onClose} closeDisabled={saving} />

                {failed ? (
                    <p role="alert" className="m-6 rounded-control bg-red-500/10 p-4 text-sm text-red-700">تعذّر تحميل بيانات التقرير. أغلق النافذة وأعد المحاولة.</p>
                ) : !parts || !settings ? (
                    <div className="flex flex-1 items-center justify-center p-10 text-sm text-gray-500" role="status">جارٍ تجهيز بيانات التقرير…{loadedRows > 0 && ` تم تحميل ${loadedRows.toLocaleString('en-US')} حركة`}</div>
                ) : (
                    <>
                        <div className="flex shrink-0 gap-1 border-b border-gray-100 p-2 lg:hidden" role="tablist">
                            {[['settings', 'الإعدادات'], ['preview', 'المعاينة']].map(([key, label]) => (
                                <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)} className={`flex-1 rounded-control px-3 py-2 text-sm font-semibold ${tab === key ? 'bg-graphite-gradient text-white' : 'text-gray-600'}`}>
                                    {label}
                                </button>
                            ))}
                        </div>
                        <div className="grid min-h-0 flex-1 lg:grid-cols-[380px_minmax(0,1fr)]">
                            <div className={`min-h-0 overflow-y-auto border-gray-100 px-5 pb-6 lg:block lg:border-e ${tab === 'settings' ? 'block' : 'hidden'}`}>
                                <Section title="ماذا يحوي الملف" hint={`${parts.filter((candidate) => settings.parts[candidate.key].on).length} من ${parts.length}`}>
                                    <ul className="space-y-1.5">
                                        {parts.map((candidate) => (
                                            <li key={candidate.key} className={`flex items-center gap-2 rounded-control border px-3 py-2 ${editing === candidate.key ? 'border-excel-500/50 bg-excel-500/5' : 'border-gray-100'}`}>
                                                <input type="checkbox" className="rounded border-gray-300 text-excel-600 focus:ring-excel-500" checked={settings.parts[candidate.key].on} onChange={(event) => changePart(candidate.key, { on: event.target.checked })} aria-label={`تضمين ${candidate.name}`} />
                                                <button type="button" className="flex min-w-0 flex-1 items-center justify-between gap-2 text-start text-sm" onClick={() => setEditing(candidate.key)}>
                                                    <span className="truncate font-semibold text-gray-900">{candidate.name}</span>
                                                    <span className="shrink-0 font-display text-xs text-gray-500">{candidate.rows.length.toLocaleString('en')} صف</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </Section>

                                {part && (
                                    <Section title={`أعمدة «${part.name}»`} hint={`${partSettings.columns.filter((column) => column.on).length} من ${part.columns.length}`}>
                                        <ul className="overflow-hidden rounded-control border border-gray-100">
                                            {partSettings.columns.map((column, index) => {
                                                const definition = part.columns.find((candidate) => candidate.key === column.key);
                                                return (
                                                    <li key={column.key} className="flex items-center gap-2 border-b border-gray-100 px-3 py-1.5 last:border-b-0">
                                                        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-sm">
                                                            <input type="checkbox" className="rounded border-gray-300 text-excel-600 focus:ring-excel-500" checked={column.on} onChange={() => changePart(part.key, { columns: partSettings.columns.map((candidate) => (candidate.key === column.key ? { ...candidate, on: !candidate.on } : candidate)) })} />
                                                            <span className="truncate">{definition.label}</span>
                                                            {definition.sum && <span className="text-xs text-gray-400">مجموع</span>}
                                                        </label>
                                                        <input
                                                            type="number"
                                                            min={COLUMN_WIDTH_RANGE[0]}
                                                            max={COLUMN_WIDTH_RANGE[1]}
                                                            value={columnWidth(definition, partSettings)}
                                                            onChange={(event) => setColumnWidth(column.key, event.target.value === '' ? undefined : Number(event.target.value))}
                                                            className="w-14 rounded-md border-gray-200 px-1.5 py-0.5 text-center font-display text-xs"
                                                            aria-label={`عرض عمود ${definition.label}`}
                                                            title="عرض العمود بالأحرف"
                                                        />
                                                        <button type="button" onClick={() => moveColumn(index, -1)} disabled={index === 0} className="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30" aria-label={`نقل ${definition.label} للأعلى`}><Icon name="arrow-up" className="h-4 w-4" /></button>
                                                        <button type="button" onClick={() => moveColumn(index, 1)} disabled={index === partSettings.columns.length - 1} className="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30" aria-label={`نقل ${definition.label} للأسفل`}><Icon name="arrow-down" className="h-4 w-4" /></button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                        <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
                                            <span>الرقم بجانب كل عمود: عرضه بالأحرف ({COLUMN_WIDTH_RANGE[0]}–{COLUMN_WIDTH_RANGE[1]}).</span>
                                            {partSettings.columns.some((column) => column.width !== undefined) && (
                                                <button type="button" className="font-semibold text-gray-700 hover:text-gray-900" onClick={() => changePart(part.key, { columns: partSettings.columns.map(({ width: _width, ...column }) => column) })}>
                                                    العرض الافتراضي
                                                </button>
                                            )}
                                        </div>
                                        {(part.groupBys ?? []).length > 0 && (
                                            <label className="mt-3 block text-sm font-semibold text-gray-700">
                                                تجميع الصفوف حسب
                                                <select className="mt-1.5 block w-full rounded-control border-gray-200 text-sm" value={partSettings.groupBy} onChange={(event) => changePart(part.key, { groupBy: event.target.value })}>
                                                    <option value="">بدون تجميع</option>
                                                    {part.groupBys.map((group) => (
                                                        <option key={group.key} value={group.key}>{group.label}</option>
                                                    ))}
                                                </select>
                                            </label>
                                        )}
                                    </Section>
                                )}

                                <Section title="العنوان والملف">
                                    <label className="block text-sm font-semibold text-gray-700">
                                        عنوان الملف (أول سطر في كل ورقة)
                                        <TextInput className="mt-1.5 w-full" value={settings.title} onChange={(event) => change({ title: event.target.value })} />
                                    </label>
                                    <label className="mt-3 block text-sm font-semibold text-gray-700">
                                        اسم الملف
                                        <TextInput className="mt-1.5 w-full font-display text-sm" dir="auto" value={settings.fileName} onChange={(event) => change({ fileName: event.target.value })} />
                                    </label>
                                </Section>

                                <Section title="الألوان والخط">
                                    <p className="mb-2 text-sm font-semibold text-gray-700">ألوان صف العناوين والعنوان</p>
                                    <div className="grid grid-cols-5 gap-2" role="radiogroup" aria-label="الألوان">
                                        {Object.entries(EXCEL_THEMES).map(([key, theme]) => (
                                            <button
                                                key={key}
                                                type="button"
                                                role="radio"
                                                aria-checked={settings.theme === key}
                                                onClick={() => change({ theme: key })}
                                                className={`flex flex-col items-center gap-1 rounded-control border p-1.5 text-[11px] font-semibold transition ${settings.theme === key ? 'border-excel-500 bg-excel-500/5 text-gray-900' : 'border-gray-100 text-gray-600 hover:border-gray-200'}`}
                                            >
                                                <span className="flex h-7 w-full flex-col overflow-hidden rounded border border-gray-200" aria-hidden="true">
                                                    <span className="h-2" style={{ background: `#${theme.title}` }} />
                                                    <span className="flex-1" style={{ background: `#${theme.head}` }} />
                                                    <span className="h-1.5" style={{ background: `#${theme.group}` }} />
                                                </span>
                                                {theme.label}
                                            </button>
                                        ))}
                                    </div>
                                    <div className="mt-4 space-y-3 text-sm font-semibold text-gray-700">
                                        <div className="flex items-center justify-between gap-3">
                                            حجم الخط
                                            <SegmentedTabs label="حجم الخط" value={settings.fontSize} onChange={(value) => change({ fontSize: value })} options={Object.entries(EXCEL_FONT_SIZES).map(([value, size]) => ({ value, label: size.label }))} />
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            ارتفاع الصفوف
                                            <SegmentedTabs label="ارتفاع الصفوف" value={settings.rowHeight} onChange={(value) => change({ rowHeight: value })} options={Object.entries(EXCEL_ROW_HEIGHTS).map(([value, height]) => ({ value, label: height.label }))} />
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            اتجاه الصفحة عند الطباعة
                                            <SegmentedTabs label="اتجاه الصفحة" value={settings.orientation} onChange={(value) => change({ orientation: value })} options={Object.entries(EXCEL_ORIENTATIONS).map(([value, label]) => ({ value, label }))} />
                                        </div>
                                    </div>
                                </Section>

                                <Section title="الشكل داخل Excel">
                                    <div className="space-y-3">
                                        {LOOK.map(([key, label, hint]) => (
                                            <div key={key}>
                                                <Switch checked={Boolean(settings[key])} onChange={(value) => change({ [key]: value })} label={label} hint={hint} />
                                            </div>
                                        ))}
                                    </div>
                                </Section>

                                <Section title="قوالب الشركة" hint="مشتركة بين جميع المستخدمين">
                                    {company.templates.length === 0 ? (
                                        <p className="mb-3 text-xs leading-5 text-gray-500">
                                            لا قوالب للشركة بعد.{!company.canManage && ' يضيفها من يملك صلاحية إدارة قوالب الطباعة.'}
                                        </p>
                                    ) : (
                                        <ul className="mb-3 overflow-hidden rounded-control border border-gray-100">
                                            {company.templates.map((template) => (
                                                <li key={template.id} className="flex items-center gap-1.5 border-b border-gray-100 px-3 py-2 last:border-b-0">
                                                    <button type="button" className="min-w-0 flex-1 text-start text-sm" onClick={() => applyTemplate(template.settings)} title="تطبيق القالب">
                                                        <b className="block truncate font-semibold text-gray-900">{template.name}</b>
                                                        {template.updatedBy && <small className="block truncate text-xs text-gray-500">آخر تعديل: {template.updatedBy}</small>}
                                                    </button>
                                                    {template.isDefault && <span className="shrink-0 rounded-full bg-excel-500/10 px-2 py-0.5 text-[11px] font-bold text-excel-700">الافتراضي</span>}
                                                    {company.canManage && (
                                                        <>
                                                            <button type="button" disabled={saveHttp.processing} onClick={() => sendCompany('put', `/excel-templates/${template.id}`, { settings: layout() })} className="rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-40" aria-label={`حفظ الإعداد الحالي في ${template.name}`} title="حفظ الإعداد الحالي فيه">
                                                                <Icon name="check" className="h-4 w-4" />
                                                            </button>
                                                            <button type="button" disabled={saveHttp.processing} onClick={() => sendCompany('put', `/excel-templates/${template.id}`, { is_default: !template.isDefault })} className="rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-40" aria-label={template.isDefault ? `إلغاء ${template.name} كقالب افتراضي` : `جعل ${template.name} القالب الافتراضي`} title={template.isDefault ? 'إلغاء الافتراضي' : 'جعله الافتراضي'}>
                                                                <Icon name="pin" className="h-4 w-4" />
                                                            </button>
                                                            <button type="button" disabled={saveHttp.processing} onClick={() => window.confirm(`حذف القالب «${template.name}» لجميع المستخدمين؟`) && sendCompany('delete', `/excel-templates/${template.id}`, {})} className="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40" aria-label={`حذف القالب ${template.name}`} title="حذف">
                                                                <Icon name="trash" className="h-4 w-4" />
                                                            </button>
                                                        </>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {company.canManage && (
                                        <>
                                            <div className="flex gap-2">
                                                <TextInput className="min-w-0 flex-1 text-sm" placeholder="اسم قالب الشركة، مثلاً: تقرير الإدارة" value={companyName} onChange={(event) => setCompanyName(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && saveCompanyTemplate()} aria-label="اسم قالب الشركة" maxLength={60} />
                                                <SecondaryButton onClick={saveCompanyTemplate} disabled={!companyName.trim() || saveHttp.processing}>حفظ للشركة</SecondaryButton>
                                            </div>
                                            <label className="mt-2 flex items-center gap-2 text-xs font-semibold text-gray-600">
                                                <input type="checkbox" className="rounded border-gray-300 text-excel-600 focus:ring-excel-500" checked={companyDefault} onChange={(event) => setCompanyDefault(event.target.checked)} />
                                                القالب الافتراضي لكل من يصدّر هذا التقرير
                                            </label>
                                            {Object.values(saveHttp.errors ?? {})[0] && <p role="alert" className="mt-2 text-xs font-semibold text-red-700">{Object.values(saveHttp.errors)[0]}</p>}
                                        </>
                                    )}
                                </Section>

                                <Section title="قوالبي" hint="تُحفظ في هذا المتصفح">
                                    {templates.length > 0 && (
                                        <div className="mb-3 flex flex-wrap gap-2">
                                            {templates.map((template) => (
                                                <span key={template.name} className="inline-flex items-center gap-1 rounded-control border border-gray-200 bg-surface ps-3 text-sm font-semibold">
                                                    <button type="button" className="py-1.5" onClick={() => applyTemplate(template.settings)}>{template.name}</button>
                                                    <button type="button" className="rounded p-1.5 text-gray-400 hover:text-red-600" onClick={() => removeTemplate(template.name)} aria-label={`حذف القالب ${template.name}`}><Icon name="close" className="h-3.5 w-3.5" /></button>
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                    <div className="flex gap-2">
                                        <TextInput className="min-w-0 flex-1 text-sm" placeholder="اسم القالب، مثلاً: نسخة المحاسب" value={templateName} onChange={(event) => setTemplateName(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && saveTemplate()} aria-label="اسم القالب" />
                                        <SecondaryButton onClick={saveTemplate} disabled={!templateName.trim()}>حفظ</SecondaryButton>
                                    </div>
                                </Section>
                            </div>

                            <div className={`min-h-0 flex-col bg-gray-50 p-4 lg:flex ${tab === 'preview' ? 'flex' : 'hidden'}`}>
                                <Preview sheet={sheet} sheets={sheets} sheetIndex={sheetIndex} onSheet={(index) => { setSheetIndex(index); setActiveCell(null); }} shownCell={shownCell} onCell={setActiveCell} settings={settings} />
                            </div>
                        </div>
                    </>
                )}

                <footer className="flex shrink-0 flex-wrap items-center gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4">
                    <div className="min-w-0 basis-full text-sm text-gray-500 sm:flex-1 sm:basis-0">
                        {settings && parts && (
                            <>
                                <bdi className="block truncate font-display font-bold text-gray-900">{fileName}</bdi>
                                <span className="block">{sheets.length} {sheets.length === 1 ? 'ورقة' : sheets.length === 2 ? 'ورقتان' : 'أوراق'} · {rowsCount.toLocaleString('en')} صف</span>
                            </>
                        )}
                        {error && <span role="alert" className="block text-red-700">{error}</span>}
                    </div>
                    <SecondaryButton onClick={onClose} disabled={saving}>إلغاء</SecondaryButton>
                    <PrimaryButton type="button" onClick={download} disabled={saving || !sheets.length}>
                        <Icon name="arrow-down-tray" className="h-4 w-4" />
                        {saving ? 'جارٍ إنشاء الملف…' : 'تنزيل ملف Excel'}
                    </PrimaryButton>
                </footer>
            </div>
        </Modal>
    );
}

function Section({ title, hint = null, children }) {
    return (
        <section className="border-b border-gray-100 py-5 last:border-b-0">
            <h3 className="mb-3 flex items-center justify-between gap-2 text-sm font-bold text-gray-900">
                {title}
                {hint && <span className="text-xs font-medium text-gray-500">{hint}</span>}
            </h3>
            {children}
        </section>
    );
}

/** The weight and lines of each kind of row; its colours, size and height come from the look the user chose. */
const ROW_STYLE = {
    title: 'font-bold',
    subtitle: 'font-bold text-gray-900',
    meta: 'text-gray-500',
    head: 'text-center font-bold',
    group: 'font-bold',
    subtotal: 'bg-gray-100 font-bold',
    total: 'border-t-4 border-double border-gray-500 bg-gray-200 font-bold',
};

/** The sheets as Excel will show them: column letters, row numbers, the formula bar and a tab per sheet. */
function Preview({ sheet, sheets, sheetIndex, onSheet, shownCell, onCell, settings }) {
    if (!sheet) {
        return <p className="m-auto text-sm text-gray-500">اختر جزءاً واحداً على الأقل.</p>;
    }

    const shown = sheet.rows.slice(0, PREVIEW_ROWS);
    const { theme, font, rows: heights } = excelLook(settings);
    // Points to pixels, as Excel draws at 100%.
    const px = (points) => Math.round(points * 1.333);
    const rowStyle = (kind) => ({
        title: { color: `#${theme.title}`, fontSize: px(font.title), height: px(font.title * 1.75) },
        subtitle: { fontSize: px(font.subtitle), height: px(font.subtitle * 1.65) },
        meta: { fontSize: px(font.body - 1), height: px(heights.data) },
        head: { background: `#${theme.head}`, color: `#${theme.headText}`, height: px(heights.data + 6) },
        group: { background: `#${theme.group}`, color: `#${theme.groupText}` },
    })[kind] ?? { height: px(heights.data) };
    const width = 40 + sheet.widths.reduce((total, columnWidth) => total + columnWidth * 7.5, 0);

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-control border border-gray-200 bg-surface">
            <div className="flex items-center gap-2 bg-excel-600 px-3 py-1.5 text-xs font-semibold text-white">
                <Icon name="excel" className="h-4 w-4" />
                معاينة الورقة
                <span className="ms-auto font-display">{sheet.rows.length.toLocaleString('en')} صف</span>
            </div>
            <div className="flex items-center border-b border-gray-200 text-xs" dir="ltr">
                <span className="min-w-16 border-e border-gray-200 px-3 py-1.5 font-display text-gray-600">{shownCell?.address ?? 'A1'}</span>
                <i className="border-e border-gray-200 px-3 py-1.5 text-gray-400">fx</i>
                <code className="truncate px-3 py-1.5" dir="auto">{shownCell ? (shownCell.formula ? `=${shownCell.formula}` : shownCell.text) : ''}</code>
            </div>
            <div className="min-h-0 flex-1 overflow-auto" dir={settings.rtl ? 'rtl' : 'ltr'}>
                <table className="border-collapse" style={{ width, tableLayout: 'fixed', fontFamily: 'Arial, sans-serif', fontSize: px(font.body) }}>
                    <colgroup>
                        <col style={{ width: 40 }} />
                        {sheet.widths.map((columnWidth, index) => (
                            <col key={index} style={{ width: columnWidth * 7.5 }} />
                        ))}
                    </colgroup>
                    <thead>
                        <tr>
                            <th className="sticky top-0 border border-gray-200 bg-gray-50" />
                            {sheet.widths.map((_, index) => (
                                <th key={index} className="sticky top-0 border border-gray-200 bg-gray-50 px-1 py-0.5 font-normal text-gray-500">{columnLetter(index)}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((row) => {
                            const merged = ['title', 'subtitle', 'meta', 'group'].includes(row.kind) && row.cells.length === 1;
                            return (
                                <tr key={row.row} className={`${ROW_STYLE[row.kind] ?? ''} ${row.zebra ? 'bg-gray-50' : ''}`} style={rowStyle(row.kind)}>
                                    <td className="border border-gray-200 bg-gray-50 px-1 text-center font-normal text-gray-400">{row.row}</td>
                                    {row.kind === 'blank' ? (
                                        <td colSpan={sheet.widths.length} className="h-5 border border-gray-200" />
                                    ) : merged ? (
                                        <td colSpan={sheet.widths.length} className="truncate border border-gray-200 px-2">{row.cells[0].v}</td>
                                    ) : (
                                        sheet.widths.map((_, index) => {
                                            const cell = row.cells[index] ?? { v: '' };
                                            const text = formatExcelCell(cell.v, cell.t, settings.currency);
                                            const address = `${columnLetter(index)}${row.row}`;
                                            const numeric = ['money', 'decimal', 'int'].includes(cell.t);
                                            return (
                                                <td key={index} onClick={() => onCell({ address, formula: cell.f, text })} className={`cursor-cell truncate border border-gray-200 px-2 ${numeric ? 'font-display tabular-nums' : ''} ${shownCell?.address === address ? 'outline outline-2 -outline-offset-2 outline-excel-500' : ''}`} style={{ textAlign: numeric ? (settings.rtl ? 'left' : 'right') : undefined }}>
                                                    {cell.f && <span className="me-1 text-[9px] italic text-excel-600">ƒ</span>}
                                                    {text}
                                                </td>
                                            );
                                        })
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {sheet.rows.length > PREVIEW_ROWS && <p className="border-t border-gray-100 px-3 py-2 text-xs text-gray-500">تعرض المعاينة أول {PREVIEW_ROWS} صفاً من {sheet.rows.length.toLocaleString('en')}؛ الملف يحوي كل الصفوف.</p>}
            <div className="flex gap-0.5 overflow-x-auto border-t border-gray-200 bg-gray-50 px-2" role="tablist" aria-label="أوراق الملف">
                {sheets.map((candidate, index) => (
                    <button key={candidate.key} type="button" role="tab" aria-selected={index === sheetIndex} onClick={() => onSheet(index)} className={`whitespace-nowrap border-t-[3px] px-3 py-1.5 text-xs ${index === sheetIndex ? 'border-excel-600 bg-surface font-bold text-excel-700' : 'border-transparent text-gray-500'}`}>
                        {candidate.name}
                    </button>
                ))}
            </div>
        </div>
    );
}
