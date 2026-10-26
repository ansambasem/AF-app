import { useId, useState } from 'react';
import Icon from '@/Components/Icon';
import ChoiceChips from '@/Components/ChoiceChips';
import Switch from '@/Components/Switch';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { moveColumn, PAPER_SIZES, PRINT_FONTS, PRINT_PRESETS, applyPrintPreset } from '@/lib/printLayout';

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

const ORIENTATIONS = [
    { value: 'portrait', label: 'طولي', icon: 'portrait' },
    { value: 'landscape', label: 'عرضي', icon: 'landscape' },
];

const DENSITIES = [
    { value: 'compact', label: 'مضغوطة', icon: 'rows-compact' },
    { value: 'normal', label: 'عادية', icon: 'rows-comfortable' },
    { value: 'relaxed', label: 'واسعة', icon: 'list' },
];

const BORDERS = [
    { value: 'grid', label: 'شبكة كاملة', icon: 'table' },
    { value: 'rows', label: 'خطوط أفقية', icon: 'list' },
    { value: 'none', label: 'بدون خطوط', icon: 'close' },
];

const DIRECTIONS = [
    { value: 'asc', label: 'تصاعدي', icon: 'arrow-up' },
    { value: 'desc', label: 'تنازلي', icon: 'arrow-down' },
];

const ALIGNMENTS = [
    { value: 'auto', label: 'تلقائي' },
    { value: 'start', label: 'يمين' },
    { value: 'center', label: 'وسط' },
    { value: 'end', label: 'يسار' },
];

const ACCENTS = [
    { value: '#111827', label: 'أسود' },
    { value: '#A51D26', label: 'عنابي' },
    { value: '#1D4ED8', label: 'أزرق' },
    { value: '#047857', label: 'أخضر' },
    { value: '#6B7280', label: 'رمادي' },
];

/** One part of the settings, opened or folded by its title. */
function Section({ icon, title, children, open = false, id }) {
    return (
        <details id={id} open={open} className="group border-b border-gray-100">
            <summary
                className={`flex cursor-pointer list-none items-center gap-3 px-5 py-3.5 text-sm font-bold text-gray-900 hover:bg-gray-50 ${FOCUS_RING} [&::-webkit-details-marker]:hidden`}
            >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-700">
                    <Icon name={icon} className="h-4 w-4" />
                </span>
                <span className="flex-1">{title}</span>
                <Icon name="chevron-down" className="h-4 w-4 text-gray-400 transition-transform group-open:rotate-180" />
            </summary>
            <div className="space-y-4 px-5 pb-5 pt-1">{children}</div>
        </details>
    );
}

function Field({ label, children, hint }) {
    const id = useId();

    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-xs font-semibold text-gray-700">
                {label}
            </label>
            {children(id)}
            {hint && <p className="mt-1 text-xs text-gray-500">{hint}</p>}
        </div>
    );
}

function Group({ label, children }) {
    return (
        <div>
            <span className="mb-1.5 block text-xs font-semibold text-gray-700">{label}</span>
            {children}
        </div>
    );
}

function FontField({ label, value, onChange }) {
    return <Field label={label}>{(id) => <select id={id} value={value ?? 'plex'} onChange={(event) => onChange(event.target.value)} className="block w-full text-sm">
        {Object.entries(PRINT_FONTS).map(([key, font]) => <option key={key} value={key}>{font.label}</option>)}
    </select>}</Field>;
}

function NumberField({ label, value, onChange, min, max, step = 1 }) {
    return <Field label={label}>{(id) => <input id={id} type="number" min={min} max={max} step={step} value={value} onChange={(event) => onChange(Math.min(max, Math.max(min, Number(event.target.value))))} className="block w-full text-sm" />}</Field>;
}

function HeaderTypography({ label, header, onChange, font, size, bold, align }) {
    return <fieldset className="space-y-3 border-t border-gray-100 pt-3">
        <legend className="px-1 text-xs font-bold text-gray-900">{label}</legend>
        <div className="grid grid-cols-2 gap-3">
            <FontField label={`خط ${label}`} value={header[font]} onChange={(value) => onChange(font, value)} />
            <NumberField label={`حجم ${label} (بكسل)`} value={header[size]} min={8} max={48} onChange={(value) => onChange(size, value)} />
        </div>
        <Field label={`محاذاة ${label}`}>{(id) => <select id={id} value={header[align]} onChange={(event) => onChange(align, event.target.value)} className="block w-full text-sm">
            <option value="right">يمين</option><option value="center">وسط</option><option value="left">يسار</option>
        </select>}</Field>
        <Switch label={`${label} بخط عريض`} checked={header[bold]} onChange={(value) => onChange(bold, value)} />
    </fieldset>;
}

/** A small icon-only button, named for screen readers and in its tooltip. */
function IconButton({ icon, label, onClick, disabled = false, tone = 'gray' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-surface transition hover:border-gray-300 disabled:cursor-not-allowed disabled:opacity-30 ${
                tone === 'danger' ? 'text-brand-600' : 'text-gray-600 hover:text-gray-900'
            } ${FOCUS_RING}`}
        >
            <Icon name={icon} className="h-4 w-4" />
        </button>
    );
}

/**
 * The company's print templates for this list: open one, save the design
 * into the one in use or as a new one, make one the list's default, or
 * delete it. Without the permission to manage them they can only be
 * opened. A list that can't keep templates says so.
 */
function TemplatesSection({ templates, onReset, onPreset }) {
    const { list, active, isChanged, supported, canManage, errors, actions } = templates;
    const [selected, setSelected] = useState('');
    const [newName, setNewName] = useState('');
    const [newIsDefault, setNewIsDefault] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    return (
        <Section id="print-template-settings" icon="folder" title="قوالب الطباعة" open>
            <Field label="البدء من تصميم جاهز" hint="اختر المظهر ثم عدّله واحفظه بالاسم الذي تريده. بيانات التقرير تبقى من القائمة الحالية.">
                {(id) => <select id={id} value="" onChange={(event) => {
                    const preset = PRINT_PRESETS.find((item) => item.id === event.target.value);
                    if (!preset) return;
                    onPreset(preset.id);
                    setNewName(preset.name);
                    setNewIsDefault(false);
                }} className="block w-full text-sm">
                    <option value="">— اختر تصميمًا —</option>
                    {PRINT_PRESETS.map((preset) => <option key={preset.id} value={preset.id}>{preset.name} — {preset.description}</option>)}
                </select>}
            </Field>
            {!supported ? (
                <p className="text-xs text-gray-500">هذه الصفحة لا تحفظ قوالب. يُحفظ آخر تصميم لها في هذا المتصفح.</p>
            ) : (
                <>
                    <div className="rounded-control border border-gray-200 bg-gray-50 px-3 py-2 text-sm" aria-live="polite">
                        {active ? (
                            <span className="flex flex-wrap items-center gap-2">
                                <Icon name="folder" className="h-4 w-4 text-gray-500" />
                                القالب: <b>{active.name}</b>
                                {active.isDefault && <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">افتراضي</span>}
                                {isChanged && <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-800">تعديلات غير محفوظة</span>}
                            </span>
                        ) : (
                            <span className="text-gray-600">تصميم غير محفوظ في قالب</span>
                        )}
                    </div>

                    {active && canManage && isChanged && (
                        <PrimaryButton type="button" onClick={actions.saveChanges} className="w-full">
                            <Icon name="check" className="h-4 w-4" />
                            حفظ التعديلات في «{active.name}»
                        </PrimaryButton>
                    )}

                    {list.length > 0 ? (
                        <Field label="فتح قالب">
                            {(id) => (
                                <div className="flex gap-2">
                                    <select id={id} value={selected} onChange={(e) => setSelected(e.target.value)} className="block min-w-0 flex-1 text-sm">
                                        <option value="">— اختر قالبًا —</option>
                                        {list.map((template) => (
                                            <option key={template.id} value={template.id}>
                                                {template.name}
                                                {template.isDefault ? ' (افتراضي)' : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <SecondaryButton onClick={() => actions.apply(selected)} disabled={selected === ''}>
                                        <Icon name="check" className="h-4 w-4" />
                                        فتح
                                    </SecondaryButton>
                                </div>
                            )}
                        </Field>
                    ) : (
                        <p className="text-xs text-gray-500">لا توجد قوالب محفوظة لهذه القائمة بعد.</p>
                    )}

                    {canManage && (
                        <>
                            {active && (
                                <div className="flex gap-2">
                                    <SecondaryButton onClick={() => actions.setDefault(active.id, !active.isDefault)} className="flex-1 !px-2 text-xs">
                                        <Icon name={active.isDefault ? 'close' : 'check'} className="h-4 w-4" />
                                        {active.isDefault ? 'إلغاء كونه الافتراضي' : 'جعله الافتراضي لهذه القائمة'}
                                    </SecondaryButton>
                                    <IconButton icon="trash" tone="danger" label={`حذف القالب «${active.name}»`} onClick={() => setConfirmingDelete(true)} />
                                </div>
                            )}
                            <Field label="حفظ التصميم كقالب جديد">
                                {(id) => (
                                    <form
                                        className="space-y-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            actions.saveNew(newName.trim(), newIsDefault, () => {
                                                setNewName('');
                                                setNewIsDefault(false);
                                            });
                                        }}
                                    >
                                        <div className="flex gap-2">
                                            <input
                                                id={id}
                                                type="text"
                                                value={newName}
                                                onChange={(e) => setNewName(e.target.value)}
                                                placeholder="مثلًا: ورقة مقاصة — فرع مخيم 2"
                                                aria-invalid={errors.name ? true : undefined}
                                                className="block min-w-0 flex-1 text-sm"
                                            />
                                            <PrimaryButton type="submit" disabled={newName.trim() === ''}>
                                                <Icon name="plus" className="h-4 w-4" />
                                                حفظ
                                            </PrimaryButton>
                                        </div>
                                        {errors.name && <p className="text-xs text-brand-600">{errors.name}</p>}
                                        <Switch checked={newIsDefault} onChange={setNewIsDefault} label="اجعله الافتراضي لهذه القائمة" />
                                    </form>
                                )}
                            </Field>
                            <a
                                href="/settings/print-templates"
                                target="_blank"
                                rel="noopener"
                                className={`flex items-center gap-1.5 rounded text-xs font-semibold text-brand-600 hover:underline ${FOCUS_RING}`}
                            >
                                <Icon name="external" className="h-4 w-4" />
                                كل قوالب الطباعة (صفحة القوالب)
                            </a>
                        </>
                    )}
                    {!canManage && <p className="text-xs text-gray-500">حفظ القوالب يحتاج صلاحية «إدارة قوالب الطباعة».</p>}
                </>
            )}

            <SecondaryButton onClick={onReset} className="w-full">
                <Icon name="undo" className="h-4 w-4" />
                إرجاع كل الإعدادات إلى الأصل
            </SecondaryButton>

            <ConfirmDialog
                show={confirmingDelete}
                onConfirm={() => {
                    actions.remove(active.id);
                    setConfirmingDelete(false);
                }}
                onCancel={() => setConfirmingDelete(false)}
                title="حذف القالب؟"
                message={`سيُحذف القالب «${active?.name ?? ''}» من قوالب الشركة، لكل المستخدمين.`}
                confirmLabel="نعم، احذف"
                icon="trash"
                tone="danger"
            />
        </Section>
    );
}

/**
 * Every setting of the printout, in folding sections: templates, which
 * rows, the paper, the heading, the columns (shown, named, ordered,
 * aligned, totalled), sorting and grouping, the table's look and the
 * footer. Each change shows
 * in the preview at once.
 */
export default function PrintSettingsPanel({ layout, onChange, scope, templates, onReset, reportFields = [], reportFieldsTitle = 'بيانات الصندوق' }) {
    const [columnSearch, setColumnSearch] = useState('');
    const [confirming, setConfirming] = useState(null);

    function set(key, value) {
        onChange({ ...layout, [key]: value });
    }

    function setIn(group, key, value) {
        onChange({ ...layout, [group]: { ...layout[group], [key]: value } });
    }

    function setColumn(index, changes) {
        set(
            'columns',
            layout.columns.map((column, position) => (position === index ? { ...column, ...changes } : column)),
        );
    }

    function setSignature(index, label) {
        setIn(
            'footer',
            'signatures',
            layout.footer.signatures.map((item, position) => (position === index ? label : item)),
        );
    }

    function setSortLevel(index, changes) {
        set(
            'sort',
            layout.sort.map((level, position) => (position === index ? { ...level, ...changes } : level)),
        );
    }

    const shownCount = layout.columns.filter((column) => column.visible).length;
    const fieldOptions = layout.columns.filter((column) => !column.key.startsWith('blank:')).map((column) => (
        <option key={column.key} value={column.key}>
            {column.label}
        </option>
    ));

    return (
        <div>
            <TemplatesSection templates={templates} onReset={() => setConfirming('reset')} onPreset={(id) => {
                onChange(applyPrintPreset(layout, id));
                templates.actions.startNew?.();
            }} />

            <Section icon="list" title="الصفوف" open>
                <fieldset disabled={scope.loading}>
                {scope.allRowsOnly ? <p className="text-sm font-semibold text-gray-700">كل الحركات المطابقة للتصفية ({scope.total})</p> : <ChoiceChips
                    options={[
                        { value: 'all', label: `كل النتائج${!scope.selected && typeof scope.total === 'number' ? ` (${scope.total.toLocaleString('en')})` : ''}`, icon: 'list' },
                        { value: 'page', label: 'الصفحة المعروضة فقط', icon: 'table' },
                        ...(scope.hasSelection ? [{ value: 'selected', label: 'العناصر المحددة', icon: 'check' }] : []),
                    ]}
                    value={scope.selected ? 'selected' : scope.allRows ? 'all' : 'page'}
                    onChange={(value) => scope.onChange(value === 'selected' ? 'selected' : value === 'all')}
                    label="الصفوف المطبوعة"
                />}
                </fieldset>
                <p className="text-xs text-gray-500">بنفس البحث والترتيب والفلاتر التي كانت على الشاشة.</p>
            </Section>

            {reportFields.length > 0 && <Section icon="wallet" title={reportFieldsTitle}>
                {reportFields.map((field) => <Switch key={field.key} label={field.label} checked={!layout.header.reportFields || layout.header.reportFields.includes(field.key)} onChange={(visible) => {
                    const selected = layout.header.reportFields ?? reportFields.map((item) => item.key);
                    setIn('header', 'reportFields', visible ? [...selected, field.key] : selected.filter((key) => key !== field.key));
                }} />)}
            </Section>}

            <Section icon="document" title="الورق">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="حجم الورق">
                        {(id) => (
                            <select id={id} value={layout.paper} onChange={(e) => set('paper', e.target.value)} className="block w-full text-sm">
                                {Object.entries(PAPER_SIZES).map(([value, paper]) => (
                                    <option key={value} value={value}>
                                        {paper.label} ({paper.width}×{paper.height} مم)
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                    <Field label="الهوامش (مم)">
                        {(id) => (
                            <input
                                id={id}
                                type="number"
                                min="0"
                                max="40"
                                value={layout.margin}
                                onChange={(e) => set('margin', Number(e.target.value))}
                                className="block w-full text-sm"
                            />
                        )}
                    </Field>
                </div>
                <Group label="اتجاه الورقة">
                    <ChoiceChips options={ORIENTATIONS} value={layout.orientation} onChange={(value) => set('orientation', value)} label="اتجاه الورقة" />
                </Group>
                <FontField label="خط محتوى التقرير" value={layout.fontFamily} onChange={(value) => set('fontFamily', value)} />
                <NumberField label="تباعد أسطر التقرير" value={layout.lineHeight ?? 1.5} min={1} max={2.5} step={0.1} onChange={(value) => set('lineHeight', value)} />
                <Field label={`حجم الخط: ${layout.fontSize} بكسل`}>
                    {(id) => (
                        <input
                            id={id}
                            type="range"
                            min="8"
                            max="18"
                            value={layout.fontSize}
                            onChange={(e) => set('fontSize', Number(e.target.value))}
                            className="w-full accent-brand-600"
                        />
                    )}
                </Field>
                <Group label="المسافة بين الصفوف">
                    <ChoiceChips options={DENSITIES} value={layout.density} onChange={(value) => set('density', value)} label="المسافة بين الصفوف" />
                </Group>
            </Section>

            <Section icon="page-header" title="الترويسة">
                <Group label="ترتيب الترويسة">
                    <ChoiceChips options={[
                        { value: 'standard', label: 'الترويسة المعتادة' },
                        { value: 'letterhead', label: 'نص على جانبي الشعار' },
                    ]} value={layout.header.layout} onChange={(value) => setIn('header', 'layout', value)} label="ترتيب الترويسة" />
                </Group>
                <Field label="اسم الشركة">
                    {(id) => (
                        <input id={id} type="text" value={layout.header.company} onChange={(e) => setIn('header', 'company', e.target.value)} className="block w-full text-sm" />
                    )}
                </Field>
                <Field label="تفاصيل الشركة بالعربية">
                    {(id) => <textarea id={id} rows={2} value={layout.header.companyDetails} onChange={(event) => setIn('header', 'companyDetails', event.target.value)} placeholder="الإدارة، العنوان أو أرقام التواصل" className="block w-full text-sm" />}
                </Field>
                <Field label="اسم الشركة والتفاصيل بالإنجليزية">
                    {(id) => <textarea id={id} dir="ltr" rows={3} value={layout.header.companyEnglish} onChange={(event) => setIn('header', 'companyEnglish', event.target.value)} placeholder={'Company for General Trade\nFares Abu Zayed Sons'} className="block w-full text-sm" />}
                </Field>
                <Field label="عنوان الطباعة">
                    {(id) => (
                        <input id={id} type="text" value={layout.header.title} onChange={(e) => setIn('header', 'title', e.target.value)} className="block w-full text-sm" />
                    )}
                </Field>
                <Field label="سطر إضافي تحت العنوان">
                    {(id) => (
                        <textarea
                            id={id}
                            rows={2}
                            value={layout.header.subtitle}
                            onChange={(e) => setIn('header', 'subtitle', e.target.value)}
                            placeholder="مثلًا: عن شهر تشرين الأول"
                            className="block w-full text-sm"
                        />
                    )}
                </Field>
                <div className="grid grid-cols-1 gap-3">
                    <Switch checked={layout.header.logo} onChange={(value) => setIn('header', 'logo', value)} label="إظهار الشعار" />
                    <Switch checked={layout.header.branch} onChange={(value) => setIn('header', 'branch', value)} label="إظهار اسم الفرع" />
                    <Switch checked={layout.header.date} onChange={(value) => setIn('header', 'date', value)} label="إظهار تاريخ ووقت الطباعة" />
                    <Switch checked={layout.header.user} onChange={(value) => setIn('header', 'user', value)} label="إظهار اسم من طبع" />
                    <Switch checked={layout.header.count} onChange={(value) => setIn('header', 'count', value)} label="إظهار عدد الصفوف" />
                    {layout.header.layout === 'standard' && <Switch checked={layout.header.centered} onChange={(value) => setIn('header', 'centered', value)} label="ترويسة في المنتصف" />}
                    <Switch
                        checked={layout.header.repeatTitle}
                        onChange={(value) => setIn('header', 'repeatTitle', value)}
                        label="تكرار العنوان أعلى كل صفحة"
                    />
                </div>
                {layout.header.layout === 'letterhead' && <>
                    <Field label="موضع الشعار">
                        {(id) => <select id={id} value={layout.header.logoPosition} onChange={(event) => setIn('header', 'logoPosition', event.target.value)} className="block w-full text-sm">
                            <option value="right">يمين</option><option value="center">وسط بين النصين</option><option value="left">يسار</option>
                        </select>}
                    </Field>
                    <Field label="المحاذاة العمودية للترويسة">
                        {(id) => <select id={id} value={layout.header.verticalAlign} onChange={(event) => setIn('header', 'verticalAlign', event.target.value)} className="block w-full text-sm">
                            <option value="top">أعلى</option><option value="center">وسط</option><option value="bottom">أسفل</option>
                        </select>}
                    </Field>
                    <NumberField label="المسافة بين الشعار والنص (مم)" value={layout.header.gap} min={0} max={15} onChange={(value) => setIn('header', 'gap', value)} />
                </>}
                <div className="grid grid-cols-2 gap-3">
                    <NumberField label="عرض الشعار (مم)" value={layout.header.logoWidth} min={10} max={80} onChange={(value) => setIn('header', 'logoWidth', value)} />
                    <NumberField label="ارتفاع الشعار (مم)" value={layout.header.logoHeight} min={8} max={60} onChange={(value) => setIn('header', 'logoHeight', value)} />
                </div>
                <HeaderTypography label="النص العربي" header={layout.header} onChange={(key, value) => setIn('header', key, value)} font="companyFont" size="companySize" bold="companyBold" align="companyAlign" />
                <HeaderTypography label="النص الإنجليزي" header={layout.header} onChange={(key, value) => setIn('header', key, value)} font="englishFont" size="englishSize" bold="englishBold" align="englishAlign" />
                <HeaderTypography label="عنوان التقرير" header={layout.header} onChange={(key, value) => setIn('header', key, value)} font="titleFont" size="titleSize" bold="titleBold" align="titleAlign" />
                <NumberField label="حجم السطر الإضافي (بكسل)" value={layout.header.subtitleSize} min={8} max={36} onChange={(value) => setIn('header', 'subtitleSize', value)} />
                <Field label="الفاصل أسفل الترويسة">
                    {(id) => <select id={id} value={layout.header.ruleStyle} onChange={(event) => setIn('header', 'ruleStyle', event.target.value)} className="block w-full text-sm">
                        <option value="none">بدون فاصل</option><option value="single">خط واحد</option><option value="double">خطان بلونين</option>
                    </select>}
                </Field>
                {layout.header.ruleStyle !== 'none' && <NumberField label="سماكة الفاصل (بكسل)" value={layout.header.ruleWidth} min={0.5} max={4} step={0.5} onChange={(value) => setIn('header', 'ruleWidth', value)} />}
                {layout.header.ruleStyle === 'double' && <Field label="لون الخط الثاني">{(id) => <input id={id} type="color" value={layout.header.ruleColor} onChange={(event) => setIn('header', 'ruleColor', event.target.value)} className="h-9 w-full" />}</Field>}
            </Section>

            <Section icon="columns" title={`الأعمدة (${shownCount} من ${layout.columns.length})`} open>
                <p className="text-xs text-gray-500">
                    أظهر أو أخفِ، غيّر الاسم، رتّب بالأسهم، واختر المحاذاة أو المجموع. «الحقول الإضافية» تفاصيل منفصلة (مثل رقم الجوال أو اسم الطبلون) مخفية في البداية — فعّلها لتبني طباعتك كما تريد.
                </p>
                <div className="flex gap-2">
                    <SecondaryButton onClick={() => set('columns', layout.columns.map((column) => ({ ...column, visible: true })))} className="flex-1 !px-2 !py-1.5 text-xs">
                        إظهار الكل
                    </SecondaryButton>
                    <SecondaryButton onClick={() => set('columns', layout.columns.map((column) => ({ ...column, visible: false })))} className="flex-1 !px-2 !py-1.5 text-xs">
                        إخفاء الكل
                    </SecondaryButton>
                </div>
                <input type="search" aria-label="البحث في أعمدة الطباعة" placeholder="ابحث عن عمود أو حقل إضافي..." value={columnSearch} onChange={(event) => setColumnSearch(event.target.value)} className="mb-3 block w-full text-sm" />
                <ol className="space-y-2">
                    {layout.columns.map((column, index) => ({ column, index })).filter(({ column }) => column.label.includes(columnSearch.trim())).map(({ column, index }) => (
                        <li
                            key={column.key}
                            className={`rounded-control border p-2.5 ${column.visible ? 'border-gray-200 bg-surface' : 'border-dashed border-gray-200 bg-gray-50'}`}
                        >
                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={column.visible}
                                    onChange={(e) => setColumn(index, { visible: e.target.checked })}
                                    aria-label={`طباعة عمود «${column.label}»`}
                                    title="إظهار العمود في الطباعة"
                                    className="rounded border-gray-300 text-brand-600"
                                />
                                <input
                                    type="text"
                                    value={column.label}
                                    onChange={(e) => setColumn(index, { label: e.target.value })}
                                    aria-label={`اسم العمود «${column.key}» في الطباعة`}
                                    className={`block min-w-0 flex-1 !py-1 text-sm ${column.visible ? '' : 'text-gray-400'}`}
                                />
                                {column.extra && (
                                    <span className="shrink-0 rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-800">حقل إضافي</span>
                                )}
                                <IconButton icon="arrow-up" label={`تحريك «${column.label}» قبل العمود السابق`} disabled={index === 0} onClick={() => set('columns', moveColumn(layout.columns, index, -1))} />
                                <IconButton
                                    icon="arrow-down"
                                    label={`تحريك «${column.label}» بعد العمود التالي`}
                                    disabled={index === layout.columns.length - 1}
                                    onClick={() => set('columns', moveColumn(layout.columns, index, 1))}
                                />
                                {column.key.startsWith('blank:') && <IconButton icon="trash" label={`حذف «${column.label}»`} tone="danger" onClick={() => set('columns', layout.columns.filter((item) => item.key !== column.key))} />}
                            </div>
                            {column.visible && (
                                <div className="mt-2 flex flex-wrap items-center gap-3 ps-6 text-xs">
                                    <label className="flex items-center gap-1.5 text-gray-600">العرض (مم)
                                        <input type="number" min="0" max="120" step="1" value={column.width ?? 0} onChange={(event) => setColumn(index, { width: Math.max(0, Math.min(120, Number(event.target.value))) })} className="w-20 !py-0.5 text-xs" aria-label={`عرض عمود ${column.label}`} />
                                        <span>0 تلقائي</span>
                                    </label>
                                    <label className="flex items-center gap-1.5 text-gray-600"><input type="checkbox" checked={Boolean(column.blank)} onChange={(event) => setColumn(index, { blank: event.target.checked, total: false })} className="rounded border-gray-300 text-brand-600" />خانة فارغة للكتابة</label>
                                    {!column.blank && <label className="flex items-center gap-1.5 text-gray-600">سطر إضافي تحته
                                        <select aria-label={`السطر الإضافي لعمود ${column.label}`} value={column.secondaryKey ?? ''} onChange={(event) => setColumn(index, { secondaryKey: event.target.value })} className="max-w-44 !py-0.5 text-xs">
                                            <option value="">بدون</option>{layout.columns.filter((item) => item.key !== column.key && !item.key.startsWith('blank:')).map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
                                        </select>
                                    </label>}
                                    <label className="flex items-center gap-1.5 text-gray-600">
                                        المحاذاة
                                        <select value={column.align} onChange={(e) => setColumn(index, { align: e.target.value })} className="!py-0.5 text-xs">
                                            {ALIGNMENTS.map((alignment) => (
                                                <option key={alignment.value} value={alignment.value}>
                                                    {alignment.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="flex items-center gap-1.5 text-gray-600">
                                        <input
                                            type="checkbox"
                                            checked={column.total}
                                            onChange={(e) => setColumn(index, { total: e.target.checked })}
                                            className="rounded border-gray-300 text-brand-600"
                                        />
                                        مجموع في الأسفل
                                    </label>
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
                <SecondaryButton className="mt-3 w-full" disabled={layout.columns.filter((column) => column.key.startsWith('blank:')).length >= 10} onClick={() => set('columns', [...layout.columns, { key: `blank:${crypto.randomUUID()}`, label: 'ملاحظات', blank: true, extra: true, visible: true, align: 'start', total: false, width: 25 }])}><Icon name="plus" className="h-4 w-4" />إضافة عمود فارغ</SecondaryButton>
            </Section>

            <Section icon="sort" title="الترتيب والتجميع" open>
                <p className="text-xs text-gray-500">رتّب الصفوف بأي حقل ولو كان مخفيًا، وجمّعها مثلًا حسب الطبلون. بدون ترتيب تبقى بترتيب الجدول.</p>
                <Field label="تجميع الصفوف حسب">
                    {(id) => (
                        <select id={id} value={layout.group.key} onChange={(event) => set('group', { ...layout.group, key: event.target.value, then: (layout.group.then ?? []).filter((key) => key !== event.target.value) })} className="block w-full text-sm">
                            <option value="">بدون تجميع</option>
                            {fieldOptions}
                        </select>
                    )}
                </Field>
                {layout.group.key !== '' && (
                    <div className="grid grid-cols-1 gap-3">
                        {(layout.group.then ?? []).map((key, index) => <div key={index} className="flex items-center gap-2">
                            <label className="text-xs text-gray-600">ثم حسب</label>
                            <select aria-label={`التجميع الإضافي ${index + 1}`} value={key} onChange={(event) => setIn('group', 'then', layout.group.then.map((value, position) => position === index ? event.target.value : value))} className="min-w-0 flex-1 text-sm">
                                {layout.columns.filter((column) => column.key !== layout.group.key && !(layout.group.then ?? []).some((value, position) => value === column.key && position !== index) && !column.key.startsWith('blank:')).map((column) => <option key={column.key} value={column.key}>{column.label}</option>)}
                            </select>
                            <IconButton icon="trash" label={`حذف التجميع ${index + 1}`} onClick={() => setIn('group', 'then', layout.group.then.filter((_, position) => position !== index))} />
                        </div>)}
                        {(layout.group.then ?? []).length < 2 && <SecondaryButton onClick={() => {
                            const next = layout.columns.find((column) => column.key !== layout.group.key && !(layout.group.then ?? []).includes(column.key) && !column.key.startsWith('blank:'));
                            if (next) setIn('group', 'then', [...(layout.group.then ?? []), next.key]);
                        }}>إضافة مستوى تجميع</SecondaryButton>}
                        <Switch checked={layout.group.newPage} onChange={(value) => setIn('group', 'newPage', value)} label="كل مجموعة في صفحة جديدة" />
                        <Switch
                            checked={layout.group.subtotals}
                            onChange={(value) => setIn('group', 'subtotals', value)}
                            label="مجموع فرعي لكل مجموعة (للأعمدة ذات المجموع)"
                        />
                    </div>
                )}
                <Group label={layout.group.key !== '' ? 'ترتيب الصفوف داخل كل مجموعة' : 'ترتيب الصفوف'}>
                    <ol className="space-y-2">
                        {layout.sort.map((level, index) => (
                            <li key={index} className="rounded-control border border-gray-200 p-2.5">
                                <div className="flex items-center gap-2">
                                    <span className="w-14 shrink-0 text-xs font-semibold text-gray-600">{index === 0 ? 'حسب' : 'ثم حسب'}</span>
                                    <select
                                        value={level.key}
                                        onChange={(e) => setSortLevel(index, { key: e.target.value })}
                                        aria-label={`حقل الترتيب ${index + 1}`}
                                        className="block min-w-0 flex-1 !py-1 text-sm"
                                    >
                                        {fieldOptions}
                                    </select>
                                    <IconButton
                                        icon="trash"
                                        tone="danger"
                                        label={`حذف مستوى الترتيب ${index + 1}`}
                                        onClick={() => set('sort', layout.sort.filter((_, position) => position !== index))}
                                    />
                                </div>
                                <div className="mt-2 ps-16">
                                    <ChoiceChips
                                        options={DIRECTIONS}
                                        value={level.direction}
                                        onChange={(value) => setSortLevel(index, { direction: value })}
                                        label={`اتجاه الترتيب ${index + 1}`}
                                    />
                                </div>
                            </li>
                        ))}
                    </ol>
                    {layout.sort.length < 3 && (
                        <SecondaryButton
                            onClick={() => set('sort', [...layout.sort, { key: layout.columns[0]?.key ?? '', direction: 'asc' }])}
                            className="mt-2 w-full"
                        >
                            <Icon name="plus" className="h-4 w-4" />
                            {layout.sort.length === 0 ? 'إضافة ترتيب' : 'إضافة مستوى ترتيب آخر'}
                        </SecondaryButton>
                    )}
                </Group>
            </Section>

            <Section icon="swatch" title="شكل الجدول">
                <Group label="خطوط الجدول">
                    <ChoiceChips options={BORDERS} value={layout.table.borders} onChange={(value) => setIn('table', 'borders', value)} label="خطوط الجدول" />
                </Group>
                <Group label="اللون الأساسي (العنوان ورأس الجدول)">
                    <div role="radiogroup" aria-label="اللون الأساسي" className="flex flex-wrap items-center gap-2">
                        {ACCENTS.map((accent) => (
                            <button
                                key={accent.value}
                                type="button"
                                role="radio"
                                aria-checked={layout.table.accent.toLowerCase() === accent.value.toLowerCase()}
                                aria-label={accent.label}
                                title={accent.label}
                                onClick={() => setIn('table', 'accent', accent.value)}
                                className={`h-8 w-8 rounded-full border-2 ${
                                    layout.table.accent.toLowerCase() === accent.value.toLowerCase() ? 'border-gray-900 ring-2 ring-white ring-offset-2 ring-offset-gray-900' : 'border-white shadow'
                                } ${FOCUS_RING}`}
                                style={{ backgroundColor: accent.value }}
                            />
                        ))}
                        <label className="flex items-center gap-1.5 text-xs text-gray-600">
                            لون آخر
                            <input
                                type="color"
                                value={layout.table.accent}
                                onChange={(e) => setIn('table', 'accent', e.target.value)}
                                className="h-8 w-10 cursor-pointer rounded border border-gray-200 p-0.5"
                            />
                        </label>
                    </div>
                </Group>
                <div className="grid grid-cols-1 gap-3">
                    <Switch checked={layout.table.headerShade} onChange={(value) => setIn('table', 'headerShade', value)} label="تظليل رأس الجدول" />
                    <Switch checked={layout.table.zebra} onChange={(value) => setIn('table', 'zebra', value)} label="تلوين الصفوف بالتناوب" />
                    <Switch checked={layout.table.rowNumbers} onChange={(value) => setIn('table', 'rowNumbers', value)} label="ترقيم الصفوف (#)" />
                    <Switch checked={layout.table.wrap} onChange={(value) => setIn('table', 'wrap', value)} label="التفاف النص الطويل في الخلايا" />
                </div>
            </Section>

            <Section icon="page-footer" title="التذييل والتواقيع">
                <Field label="نص أسفل كل صفحة">
                    {(id) => (
                        <input
                            id={id}
                            type="text"
                            value={layout.footer.text}
                            onChange={(e) => setIn('footer', 'text', e.target.value)}
                            placeholder="مثلًا: العنوان ورقم الهاتف"
                            className="block w-full text-sm"
                        />
                    )}
                </Field>
                <div className="grid grid-cols-1 gap-3">
                    <Switch checked={layout.footer.pageNumbers} onChange={(value) => setIn('footer', 'pageNumbers', value)} label="ترقيم الصفحات (صفحة 1 من 3)" />
                    <Switch checked={layout.footer.summary} onChange={(value) => setIn('footer', 'summary', value)} label="إظهار ملخص الصفحة (المجاميع) إن وُجد" />
                </div>
                <Field label="ملاحظات في نهاية الطباعة">
                    {(id) => (
                        <textarea id={id} rows={3} value={layout.footer.notes} onChange={(e) => setIn('footer', 'notes', e.target.value)} className="block w-full text-sm" />
                    )}
                </Field>
                <Group label={`خانات التوقيع (${layout.footer.signatures.length})`}>
                    <div className="space-y-2">
                        {layout.footer.signatures.map((label, index) => (
                            <div key={index} className="flex gap-2">
                                <input
                                    type="text"
                                    value={label}
                                    onChange={(e) => setSignature(index, e.target.value)}
                                    aria-label={`اسم خانة التوقيع ${index + 1}`}
                                    placeholder="مثلًا: المحاسب"
                                    className="block min-w-0 flex-1 text-sm"
                                />
                                <IconButton
                                    icon="trash"
                                    tone="danger"
                                    label={`حذف خانة التوقيع ${label || index + 1}`}
                                    onClick={() =>
                                        setIn(
                                            'footer',
                                            'signatures',
                                            layout.footer.signatures.filter((_, position) => position !== index),
                                        )
                                    }
                                />
                            </div>
                        ))}
                        {layout.footer.signatures.length < 4 && (
                            <SecondaryButton onClick={() => setIn('footer', 'signatures', [...layout.footer.signatures, ''])} className="w-full">
                                <Icon name="plus" className="h-4 w-4" />
                                إضافة خانة توقيع
                            </SecondaryButton>
                        )}
                    </div>
                </Group>
            </Section>

            <ConfirmDialog
                show={confirming === 'reset'}
                onConfirm={() => {
                    onReset();
                    setConfirming(null);
                }}
                onCancel={() => setConfirming(null)}
                title="إرجاع الإعدادات إلى الأصل؟"
                message="ستعود كل الأعمدة والترويسة والشكل كما كانت أول مرة. القوالب المحفوظة تبقى كما هي."
                confirmLabel="نعم، أرجعها"
                icon="undo"
                tone="danger"
            />
        </div>
    );
}
