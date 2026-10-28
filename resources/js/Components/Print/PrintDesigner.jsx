import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ExcelButton from '@/Components/ExcelButton';
import { tableExcelPart } from '@/lib/tableExcel';
import { extractSummary, extractTable, paginatedProp, printScopeUrl } from '@/lib/print';
import { defaultLayout, fitLayout, pageCss, paperSize, rememberedLayout, rememberLayout } from '@/lib/printLayout';
import PrintPaper from './PrintPaper';
import PrintSettingsPanel from './PrintSettingsPanel';
import { reportDetails, reportFilters } from '@/lib/cashBoxReport';
import { statementPrintDetails } from '@/lib/statementPrint';
import { STATEMENT_VIEWS } from '@/lib/accountStatement';

/** Pixels in a millimetre on screen (96 dpi). */
const PX_PER_MM = 96 / 25.4;

const ZOOMS = [
    { value: 'fit', label: 'ملاءمة الشاشة' },
    { value: '0.5', label: '50%' },
    { value: '0.75', label: '75%' },
    { value: '1', label: '100%' },
];

/**
 * A list page opened for printing (see lib/print.js): the settings on one
 * side and the sheet as it will print on the other, changing as the
 * settings change. The page itself is drawn out of sight and its table
 * read from it, so the printout lists the same rows in the same order,
 * then laid out however the user likes.
 *
 * It starts from the template named in the address (`print_template`, as
 * the templates page opens it), else the list's default template, else the
 * layout last used in this browser. Templates are the company's, shared by
 * everyone (PrintTemplateController); whoever may manage them saves the
 * design into one from here.
 */
export default function PrintDesigner({ settings, children, beforePrint }) {
    const { props } = usePage();
    const sourceRef = useRef(null);
    const stageRef = useRef(null);
    const toolbarRef = useRef(null);
    const [toolbarHeight, setToolbarHeight] = useState(65);
    const provided = props.printData ?? null;
    const pageKey = provided?.context?.templatePage ?? window.location.pathname;
    const [table, setTable] = useState(null);
    const [summary, setSummary] = useState('');
    const [layout, setLayout] = useState(null);
    const [printing, setPrinting] = useState(false);
    const [loadingRows, setLoadingRows] = useState(false);
    const printingRef = useRef(false);
    const [printError, setPrintError] = useState('');

    async function print() {
        if (printingRef.current) return;
        printingRef.current = true;
        setPrinting(true);
        setPrintError('');
        try {
            await beforePrint?.();
            window.print();
        } catch {
            setPrintError('تعذّر تجهيز الطباعة. تحقق من الاتصال وأعد المحاولة.');
        } finally {
            printingRef.current = false;
            setPrinting(false);
        }
    }
    const templates = props.printTemplates ?? [];
    const supportsTemplates = Array.isArray(props.printTemplates);
    const canManageTemplates = Boolean(props.can?.managePrintTemplates) && supportsTemplates;
    const [start] = useState(() => {
        const requested = templates.find((template) => String(template.id) === settings.template);
        const chosen = requested ?? (settings.template === 'new' ? null : templates.find((template) => template.isDefault));

        if (chosen) {
            return { layout: chosen.layout, templateId: chosen.id };
        }

        return { layout: settings.template === 'new' ? null : rememberedLayout(pageKey), templateId: null };
    });
    const [activeTemplateId, setActiveTemplateId] = useState(start.templateId);
    const pendingTemplateName = useRef(null);
    const [zoom, setZoom] = useState('fit');
    const [fitZoom, setFitZoom] = useState(1);
    const list = provided ? { total: provided.total, data: provided.rows } : paginatedProp(props);
    const isCut = settings.allRows && list && list.total > list.data.length;
    const report = props.report;
    const statementPrint = Boolean(props.printOptions);
    const readingContext = provided?.context;
    const readingDetails = readingContext ? [
        { key: 'readingDate', label: 'موعد القراءة', value: [readingContext.readingDayName, readingContext.readingDate].filter(Boolean).join(' ') },
        { key: 'week', label: 'أسبوع القراءة', value: [readingContext.weekStart, readingContext.weekEnd].filter(Boolean).join(' — ') },
        { key: 'sheet', label: 'رقم الكشف', value: readingContext.sheetNumber },
    ].filter((field) => field.value) : [];
    const details = readingContext ? readingDetails : statementPrint ? statementPrintDetails(props) : report?.counts ? reportDetails(report) : [];
    const context = { appName: props.appName, branchName: readingContext?.branchName ?? (statementPrint ? props.subscription?.branchName : report?.branch ?? props.auth?.user?.branchName), userName: props.auth?.user?.name, details };
    const visibleColumns = layout?.columns.filter((column) => column.visible) ?? [];
    const widths = visibleColumns.reduce((sum, column) => sum + Math.max(0, Number(column.width) || 0), layout?.table.rowNumbers ? 8 : 0);
    const widthWarning = layout && widths > paperSize(layout).width - layout.margin * 2 ? 'عروض الأعمدة تتجاوز مساحة الورقة. قلّل العرض أو الهوامش أو اختر الاتجاه العرضي.' : '';

    useLayoutEffect(() => {
        const toolbar = toolbarRef.current;
        if (!toolbar) return;
        const update = () => setToolbarHeight(toolbar.offsetHeight);
        update();
        const observer = new ResizeObserver(update);
        observer.observe(toolbar);
        return () => observer.disconnect();
    }, []);

    function freshLayout(columns) {
        const fresh = defaultLayout({ columns, title: settings.title || document.title, company: props.appName ?? '' });
        Object.entries(provided?.defaults ?? {}).forEach(([key, value]) => {
            fresh[key] = value && typeof value === 'object' && !Array.isArray(value) ? { ...fresh[key], ...value } : value;
        });
        if (details.length > 0) fresh.header.reportFields = details.map((field) => field.key);
        if (report?.counts) {
            fresh.columns = fresh.columns.map((column) => ({ ...column, total: ['مبلغ الصندوق', 'وصل البنك', 'الفرق'].includes(column.key) }));
        }
        return fresh;
    }

    // The printout is light whatever the app's theme.
    useEffect(() => {
        const root = document.documentElement;
        root.classList.add('print-mode');
        root.classList.remove('dark');

        return () => root.classList.remove('print-mode');
    }, []);

    // Read the page's table now and whenever it redraws.
    useEffect(() => {
        if (provided) {
            setTable({ columns: provided.columns, rows: provided.rows });
            setSummary(provided.summary ?? '');
            setLayout((current) => fitLayout(current ?? start.layout, freshLayout(provided.columns)));
            return;
        }
        let frame = null;

        function read() {
            frame = null;
            const extracted = extractTable(sourceRef.current);

            if (extracted) {
                setTable(extracted);
                setSummary(extractSummary(sourceRef.current));
                setLayout((current) => {
                    const fresh = freshLayout(extracted.columns);

                    return fitLayout(current ?? start.layout, fresh);
                });
            }
        }

        read();
        const observer = new MutationObserver(() => {
            frame ??= requestAnimationFrame(read);
        });
        observer.observe(sourceRef.current, { childList: true, subtree: true, characterData: true });

        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [provided]);

    useEffect(() => {
        if (layout) {
            rememberLayout(pageKey, layout);
        }
    }, [layout, pageKey]);

    // "Fit" scales the sheet to the width beside the settings.
    useLayoutEffect(() => {
        if (!layout || !stageRef.current) {
            return;
        }

        const stage = stageRef.current;
        const update = () => setFitZoom(Math.min(1, (stage.clientWidth - 48) / (paperSize(layout).width * PX_PER_MM)));
        update();
        const observer = new ResizeObserver(update);
        observer.observe(stage);

        return () => observer.disconnect();
    }, [layout]);

    // A template just saved under a new name becomes the one in use.
    useEffect(() => {
        const saved = templates.find((template) => template.name === pendingTemplateName.current);

        if (saved) {
            pendingTemplateName.current = null;
            setActiveTemplateId(saved.id);
        }
    }, [templates]);

    const activeTemplate = templates.find((template) => template.id === activeTemplateId) ?? null;
    const isChanged = Boolean(activeTemplate && layout && table) && JSON.stringify(fitLayout(activeTemplate.layout, freshLayout(table.columns))) !== JSON.stringify(layout);

    /** Save to the server, then reload only the templates (the rows stay as they are). */
    const templateVisit = { preserveState: true, preserveScroll: true, only: ['printTemplates', 'status', 'activity', 'errors'] };

    function applyTemplate(id) {
        const template = templates.find((item) => String(item.id) === String(id));

        if (template && table) {
            setLayout(fitLayout(template.layout, freshLayout(table.columns)));
            setActiveTemplateId(template.id);
        }
    }

    const templateActions = {
        apply: applyTemplate,
        startNew: () => setActiveTemplateId(null),
        saveNew: (name, isDefault, onSuccess) => {
            pendingTemplateName.current = name;
            router.post('/print-templates', { page: pageKey, name, layout, is_default: isDefault }, { ...templateVisit, onSuccess });
        },
        saveChanges: () => router.put(`/print-templates/${activeTemplateId}`, { layout }, templateVisit),
        setDefault: (id, isDefault) => router.put(`/print-templates/${id}`, { is_default: isDefault }, templateVisit),
        remove: (id) =>
            router.delete(`/print-templates/${id}`, {
                ...templateVisit,
                onSuccess: () => id === activeTemplateId && setActiveTemplateId(null),
            }),
    };

    return (
        <div className="pd-root min-h-screen bg-gray-100 text-gray-900" style={{ '--pd-toolbar-height': `${toolbarHeight}px` }}>
            {layout && <style>{pageCss(layout)}</style>}

            <header
                ref={toolbarRef}
                aria-label="أدوات الطباعة"
                className="pd-toolbar sticky top-0 z-30 flex flex-wrap items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 sm:px-6"
            >
                <span className="flex items-center gap-2 font-bold text-gray-900">
                    <Icon name="printer" className="h-5 w-5 text-print-600" />
                    تصميم الطباعة
                </span>
                {readingContext && <span className="text-xs text-gray-600">{readingContext.title} · {readingContext.branchName} · {[readingContext.readingDayName, readingContext.readingDate].filter(Boolean).join(' ')} · {provided.rows.length.toLocaleString('en')} سجل</span>}
                {isCut && (
                    <span role="status" className="inline-flex items-center gap-1 text-sm font-semibold text-amber-700">
                        <Icon name="warning" className="h-4 w-4 shrink-0" />
                        تُطبع أول {list.data.length.toLocaleString('en')} نتيجة من {list.total.toLocaleString('en')}
                    </span>
                )}
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    {supportsTemplates && <select aria-label="القالب المختار" className="max-w-52 !py-1.5 text-sm" value={activeTemplateId ?? ''} onChange={(event) => applyTemplate(event.target.value)}>
                        <option value="">تصميم غير محفوظ</option>
                        {templates.map((template) => <option key={template.id} value={template.id}>{template.name}{template.isDefault ? ' (افتراضي)' : ''}</option>)}
                    </select>}
                    {canManageTemplates && <SecondaryButton disabled={!layout} onClick={() => {
                        if (activeTemplateId) { templateActions.saveChanges(); return; }
                        const section = document.getElementById('print-template-settings');
                        if (section) { section.open = true; section.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); section.querySelector('input[type=text]')?.focus(); }
                    }}><Icon name="check" className="h-4 w-4" />حفظ القالب{isChanged ? ' *' : ''}</SecondaryButton>}
                    {statementPrint && <label className="flex items-center gap-2 text-sm text-gray-600">عرض الكشف
                        <select aria-label="عرض الكشف المطبوع" value={props.printOptions.view} onChange={(event) => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('view', event.target.value);
                            if (props.subscription) url.searchParams.set('subscription', props.subscription.id);
                            router.get(url.href, {}, { preserveState: true, preserveScroll: true });
                        }} className="!py-1.5 text-sm">
                            {STATEMENT_VIEWS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </label>}
                    {report?.counts && <label className="flex items-center gap-2 text-sm text-gray-600">الحركات
                        <select aria-label="تصفية الحركات المطبوعة" value={report.filter} onChange={(event) => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('filter', event.target.value);
                            if (report.boxId) url.searchParams.set('box', report.boxId);
                            router.get(url.href, {}, { preserveState: true, preserveScroll: true });
                        }} className="!py-1.5 text-sm">
                            {Object.entries(reportFilters).map(([key, label]) => <option key={key} value={key}>{label} ({report.counts[key]})</option>)}
                        </select>
                    </label>}
                    <label className="flex items-center gap-2 text-sm text-gray-600">
                        <Icon name="search" className="h-4 w-4" />
                        <span className="sr-only sm:not-sr-only">التكبير</span>
                        <select value={zoom} onChange={(e) => setZoom(e.target.value)} className="!py-1.5 text-sm" aria-label="تكبير المعاينة">
                            {ZOOMS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {loadingRows && <span role="status" className="animate-pulse text-sm text-gray-600">جارٍ تحديث المعاينة...</span>}
                    {printError && <p role="alert" className="text-sm text-red-600">{printError}</p>}
                    {widthWarning && <p role="alert" className="max-w-xs text-xs text-amber-800">{widthWarning}</p>}
                    <ExcelButton title={settings.title || document.title} disabled={isCut || !table || loadingRows} storageKey={`table:${pageKey}`} loadParts={async () => [tableExcelPart(table, { name: settings.title || document.title })]} meta={{ lines: [context.branchName, summary].filter(Boolean), criteria: [['أعدّه', context.userName ?? '']] }} />
                    <PrimaryButton type="button" className="tone-print tone-solid" onClick={print} disabled={!layout || printing || loadingRows || !visibleColumns.length || Boolean(widthWarning) || !table?.rows.some((row) => row.type === 'row')} autoFocus>
                        <Icon name="printer" className="h-4 w-4" />
                        طباعة / حفظ PDF
                    </PrimaryButton>
                    <SecondaryButton onClick={() => window.close()}>
                        <Icon name="close" className="h-4 w-4" />
                        إغلاق
                    </SecondaryButton>
                </div>
            </header>

            {provided?.warnings?.length > 0 && <div role="status" className="pd-screen-only space-y-1 border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900">
                {provided.warnings.map((warning, index) => <p key={index}>{warning}</p>)}
            </div>}

            <div className="pd-body lg:grid lg:grid-cols-[380px_minmax(0,1fr)]">
                <aside
                    aria-label="إعدادات الطباعة"
                    className="pd-panel border-e border-gray-200 bg-white lg:sticky lg:top-[var(--pd-toolbar-height)] lg:h-[calc(100vh-var(--pd-toolbar-height))] lg:overflow-y-auto"
                >
                    {layout ? (
                        <PrintSettingsPanel
                            reportFields={details}
                            reportFieldsTitle={readingContext || statementPrint ? 'بيانات الكشف' : 'بيانات الصندوق'}
                            layout={layout}
                            onChange={setLayout}
                            scope={{
                                allRows: settings.allRows,
                                allRowsOnly: Boolean(provided?.allRowsOnly) || Boolean(report?.counts) || statementPrint,
                                selected: settings.scope === 'selected',
                                hasSelection: Boolean(settings.selection),
                                loading: loadingRows,
                                total: statementPrint ? table?.rows.filter((row) => row.type === 'row').length : report?.counts ? report.rows.length : list?.total,
                                onChange: (allRows) => router.get(printScopeUrl(allRows), {}, {
                                    preserveState: true, preserveScroll: true,
                                    onStart: () => setLoadingRows(true),
                                    onFinish: () => setLoadingRows(false),
                                }),
                            }}
                            templates={{
                                list: templates,
                                active: activeTemplate,
                                isChanged,
                                supported: supportsTemplates,
                                canManage: canManageTemplates,
                                errors: props.errors ?? {},
                                actions: templateActions,
                            }}
                            onReset={() => {
                                if (table) {
                                    setLayout(freshLayout(table.columns));
                                    setActiveTemplateId(null);
                                }
                            }}
                        />
                    ) : (
                        <p className="p-6 text-sm text-gray-500">جارٍ تجهيز الجدول...</p>
                    )}
                </aside>

                <main ref={stageRef} className="pd-stage overflow-auto p-6">
                    {layout && table ? (
                        <PrintPaper layout={layout} table={table} summary={summary} context={context} zoom={zoom === 'fit' ? fitZoom : Number(zoom)} />
                    ) : (
                        !table && <p className="text-center text-sm text-gray-500">لا يوجد جدول للطباعة في هذه الصفحة.</p>
                    )}
                </main>
            </div>

            {/* The page itself, out of sight, for its table to be read from. */}
            {!provided && <div ref={sourceRef} className="pd-source" aria-hidden="true" inert>
                {children}
            </div>}
        </div>
    );
}
