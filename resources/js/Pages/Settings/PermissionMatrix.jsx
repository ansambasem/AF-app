import Icon from '@/Components/Icon';
import Switch from '@/Components/Switch';
import { ICONS, permissionGuideEntries } from '@/lib/permissionGuide';
import {
    MATRIX_COLUMNS,
    RISK_LABELS,
    matchesQuery,
    matrixCells,
    matrixExtras,
    sectionIds,
    sectionSentence,
    withSectionsAll,
    withToggled,
} from '@/lib/permissions';

/** The icon, one-line description and page of each section (keyed like PermissionKey::resourceGroups()). */
export const SECTION_DETAILS = {
    subscriptions: { icon: 'users', description: 'بيانات المشتركين', page: 'المشتركون' },
    meter_boxes: { icon: 'table', description: 'الطبلونات ومواقعها', page: 'الطبلونات' },
    circuit_breakers: { icon: 'bolt', description: 'القواطع وأمبيراتها', page: 'القواطع' },
    tariffs: { icon: 'dollar', description: 'أسعار الكيلو والحد الأدنى', page: 'التعرفات' },
    meter_readings: { icon: 'gauge', description: 'قراءات العدادات الأسبوعية', page: 'القراءات الأسبوعية' },
    reading_assignments: { icon: 'layers', description: 'توزيع الطبلونات على القرّاء الميدانيين والكشوف الورقية', page: 'توزيع القراءات' },
    collections: { icon: 'card', description: 'السجل المالي والدفعات وحركات الحسابات', page: 'تسجيل الدفعات والسجل المالي' },
    financial_audit: { icon: 'shield', description: 'مراجعة الصناديق المغلقة ومبالغ البنك واعتماد فروق التدقيق', page: 'التدقيق المالي' },
    reports: { icon: 'trend', description: 'تقارير المال، كل تقرير بصلاحيته', page: 'التقارير' },
    disconnections: { icon: 'power', description: 'قوائم القطع والإنذارات وتأكيد القطع', page: 'قوائم القطع' },
    messages: { icon: 'messages', description: 'رسائل المشتركين', page: 'الرسائل' },
    print_templates: { icon: 'printer', description: 'قوالب الطباعة المشتركة لكل الشركة', page: 'قوالب الطباعة' },
    users: { icon: 'user', description: 'حسابات الموظفين', page: 'المستخدمون' },
    user_types: { icon: 'badge', description: 'أنواع المستخدمين', page: 'أنواع المستخدمين' },
    branches: { icon: 'pin', description: 'فروع الشركة', page: 'الفروع' },
    governorates: { icon: 'map', description: 'المحافظات', page: 'المحافظات' },
    areas: { icon: 'map', description: 'المناطق', page: 'المناطق' },
    sub_areas: { icon: 'map', description: 'منطقة 2 داخل منطقة الفرع', page: 'منطقة 2' },
};

export function sectionIcon(key) {
    return SECTION_DETAILS[key]?.icon ?? ICONS[key] ?? 'shield';
}

/** «حساس» or «خطير» beside a permission: a small label, never a coloured card. */
export function RiskBadge({ risk }) {
    if (!risk) {
        return null;
    }

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-md px-1.5 text-[12px] font-bold leading-[20px] ${
                risk === 'danger' ? 'bg-red-500/10 text-red-700 dark:text-red-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
            }`}
        >
            {RISK_LABELS[risk]}
        </span>
    );
}

/** A label, marked when the search matched it. */
function Mark({ text, query }) {
    return query && matchesQuery(text, query) ? <mark className="pm-mark">{text}</mark> : text;
}

/** One cell of the grid: a check that shows the permission's risk and whether it changed since saving. */
function MatrixCheck({ checked, dirty, risk, label, disabled, onChange, hit }) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={label}
            title={[label, risk ? RISK_LABELS[risk] : '', dirty ? 'لم يُحفظ بعد' : ''].filter(Boolean).join(' · ')}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`pm-check ${checked ? 'is-on' : ''} ${risk ? `is-${risk}` : ''} ${dirty ? 'is-dirty' : ''} ${hit ? 'is-hit' : ''}`}
        >
            <Icon name="check" className="h-4 w-4" strokeWidth={3} />
        </button>
    );
}

/** Grants or takes away a whole set at once: on, off, or partly on. */
export function TriToggle({ ids, selected, onChange, disabled, label }) {
    const held = ids.filter((id) => selected.includes(id)).length;
    const state = ids.length === 0 ? 'none' : held === ids.length ? 'on' : held > 0 ? 'mixed' : 'off';

    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={state === 'on' ? 'true' : state === 'mixed' ? 'mixed' : 'false'}
            aria-label={label}
            title={state === 'on' ? 'سحب الكل' : 'منح الكل'}
            disabled={disabled || state === 'none'}
            onClick={() => onChange(state !== 'on')}
            className={`pm-tri is-${state}`}
        >
            {state === 'on' && <Icon name="check" className="h-3.5 w-3.5" strokeWidth={3} />}
            {state === 'mixed' && <i aria-hidden="true" className="pm-tri-dash" />}
        </button>
    );
}

/** One option off the grid: its switch, name and risk, the step it needs first (off until that step is granted) and «ⓘ» for what it does. */
function OptionItem({ section, item, selected, saved, onChange, disabled, onExplain, query }) {
    const on = selected.includes(item.id);
    const needed = item.needs > 0 && section.ladder ? section.ladder.ids[item.needs - 1] : null;
    const locked = !on && needed !== null && !selected.includes(needed);
    const hit = query && (matchesQuery(item.label, query) || matchesQuery(item.hint ?? '', query));

    return (
        <div className={`pm-option ${on !== saved.includes(item.id) ? 'is-dirty' : ''} ${hit ? 'is-hit' : ''} ${locked ? 'is-locked' : ''}`}>
            <Switch
                checked={on}
                onChange={(next) => onChange(withToggled(selected, item.id, next))}
                ariaLabel={`${section.label}: ${item.label}`}
                disabled={disabled || locked}
            />
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-gray-900">
                    <Mark text={item.label} query={query} />
                    <RiskBadge risk={item.risk} />
                </p>
                {locked && (
                    <p className="mt-0.5 text-[13px] text-blue-700 dark:text-blue-500">
                        يتطلب «{section.ladder.labels[item.needs - 1]}» أولًا
                    </p>
                )}
            </div>
            {onExplain && (
                <button type="button" aria-label={`شرح ${item.label}`} onClick={onExplain} className="pm-icon-button">
                    <Icon name="info" className="h-4 w-4" />
                </button>
            )}
        </div>
    );
}

/**
 * One section as a row of the grid: its name and what the chosen
 * permissions let them do, a check per column (view, add, edit, delete,
 * export), its other options behind «خيارات» and a switch for all of it.
 * Each check is its own grant: ticking edit never grants view or add.
 * Opening the row (never changing anything) shows the options
 * by kind, with what each one opens; `children` take their place for a
 * section with its own card (the financial audit), with its own `summary`
 * line and `changed` for what changed in that card. With `explaining`
 * on, the row also says what the section covers and which page it opens,
 * and each check gets an «ⓘ» that opens what that permission does.
 */
export function SectionRow({
    section,
    selected,
    saved,
    onChange,
    disabled,
    open = false,
    onToggle,
    onExplain,
    query = '',
    summary,
    changed: alsoChanged = false,
    explaining = false,
    children,
}) {
    const ids = sectionIds(section);
    const cells = matrixCells(section);
    const extras = matrixExtras(section);
    const options = extras.flatMap((group) => group.items);
    const heldOptions = options.filter((item) => selected.includes(item.id)).length;
    const held = ids.filter((id) => selected.includes(id)).length;
    const changed = alsoChanged || ids.some((id) => selected.includes(id) !== saved.includes(id));
    const guide = permissionGuideEntries([section]);
    const describe = (id) => guide.find((entry) => entry.id === id);
    const explain = (id) => (onExplain ? () => onExplain(describe(id)) : undefined);
    const needle = query.trim();
    const bodyId = `permission-section-${section.key}`;
    const cellItems = cells.filter((cell) => cell.kind);

    function toggleCell(cell, on) {
        onChange(withToggled(selected, cell.id, on));
    }

    return (
        <div className={`pm-row ${open ? 'is-open' : ''} ${changed ? 'is-changed' : ''}`} data-section={section.key}>
            <div className="pm-section">
                <span className="pm-section-icon">
                    <Icon name={sectionIcon(section.key)} className="h-5 w-5" />
                </span>
                <span className="pm-section-copy">
                    <b>
                        <Mark text={section.label} query={needle} />
                        {changed && <span className="permission-change-label">تغيّر</span>}
                    </b>
                    {explaining && SECTION_DETAILS[section.key] && (
                        <span className="pm-section-what">
                            {SECTION_DETAILS[section.key].description} · صفحة «{SECTION_DETAILS[section.key].page}»
                        </span>
                    )}
                    <small>{summary ?? sectionSentence(section, selected)}</small>
                </span>
                <bdi dir="ltr" className="pm-count" title="الممنوح من صلاحيات القسم">
                    {held}/{ids.length}
                </bdi>
            </div>

            {cells.map((cell) => (
                <div key={cell.column.key} className={`pm-cell ${cell.kind ? '' : 'is-empty'}`} title={cell.kind ? undefined : `${cell.column.label}: غير متاحة لهذا القسم`}>
                    {cell.kind ? (
                        <>
                            <MatrixCheck
                                checked={selected.includes(cell.id)}
                                dirty={selected.includes(cell.id) !== saved.includes(cell.id)}
                                risk={cell.risk}
                                label={`${section.label}: ${cell.label}`}
                                disabled={disabled}
                                hit={needle && matchesQuery(cell.label, needle)}
                                onChange={(on) => toggleCell(cell, on)}
                            />
                            <span className="pm-cell-label">{cell.label}</span>
                            {explaining && onExplain && (
                                <button type="button" aria-label={`ماذا تتيح «${cell.label}» في ${section.label}`} onClick={explain(cell.id)} className="pm-cell-why">
                                    <Icon name="info" className="h-4 w-4" />
                                </button>
                            )}
                        </>
                    ) : (
                        <span aria-hidden="true">—</span>
                    )}
                </div>
            ))}

            <div className="pm-cell pm-more-cell">
                <button
                    type="button"
                    className={`pm-more ${heldOptions > 0 ? 'has-held' : ''}`}
                    aria-expanded={open}
                    aria-controls={bodyId}
                    aria-label={`خيارات ${section.label}`}
                    onClick={() => onToggle?.(!open)}
                >
                    {options.length > 0 ? (
                        <>
                            <bdi dir="ltr">
                                {heldOptions}/{options.length}
                            </bdi>
                            {options.some((item) => item.risk === 'danger') && <i className="pm-dot is-danger" aria-hidden="true" />}
                            {options.some((item) => item.risk === 'sensitive') && <i className="pm-dot is-sensitive" aria-hidden="true" />}
                        </>
                    ) : (
                        <span>{children ? 'إعداد' : 'شرح'}</span>
                    )}
                    <Icon name="chevron-down" className="pm-chevron h-4 w-4" />
                </button>
            </div>

            <div className="pm-cell pm-master-cell">
                <TriToggle
                    ids={ids}
                    selected={selected}
                    onChange={(on) => onChange(withSectionsAll([section], selected, on))}
                    disabled={disabled}
                    label={`كل صلاحيات ${section.label}`}
                />
                <span className="pm-cell-label">كل القسم</span>
            </div>

            <div id={bodyId} className="permission-panel-body" hidden={!open}>
                <div className="pm-body">
                    {children && <div className="pm-body-wide">{children}</div>}
                    {!children && cellItems.length > 0 && (
                        <section className="pm-option-group">
                            <h5>خانات الجدول</h5>
                            {cellItems.map((cell) => (
                                <div key={cell.id} className="pm-explained">
                                    <i className={`pm-held-dot ${selected.includes(cell.id) ? 'is-held' : ''}`} aria-hidden="true" />
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-gray-900">
                                            <span className="text-gray-500">{cell.column.label}:</span> {describe(cell.id)?.label ?? cell.label}
                                            <RiskBadge risk={cell.risk} />
                                        </p>
                                    </div>
                                    {onExplain && (
                                        <button type="button" aria-label={`شرح ${cell.label}`} onClick={explain(cell.id)} className="pm-icon-button">
                                            <Icon name="info" className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </section>
                    )}
                    {extras.map((group) => {
                        const items = children ? group.items.filter((item) => item.action !== 'audit') : group.items;

                        return items.length === 0 ? null : (
                            <section key={group.title} className="pm-option-group">
                                <h5>{group.title}</h5>
                                {items.map((item) => (
                                    <OptionItem
                                        key={item.id}
                                        section={section}
                                        item={{ ...item, hint: describe(item.id)?.description ?? item.hint }}
                                        selected={selected}
                                        saved={saved}
                                        onChange={onChange}
                                        disabled={disabled}
                                        onExplain={explain(item.id)}
                                        query={needle}
                                    />
                                ))}
                            </section>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

/** The column names over the grid; they stay in view while scrolling. */
export function MatrixHead() {
    return (
        <div className="pm-head" aria-hidden="true">
            <span>القسم</span>
            {MATRIX_COLUMNS.map((column) => (
                <span key={column.key}>{column.label}</span>
            ))}
            <span>خيارات</span>
            <span>الكل</span>
        </div>
    );
}

/** What the grid's colours and marks mean. */
export function MatrixLegend() {
    return (
        <div className="pm-legend">
            <span>
                <i className="pm-check is-on pm-legend-check" aria-hidden="true" />
                ممنوحة
            </span>
            <span>
                <i className="pm-dot is-sensitive" aria-hidden="true" />
                حساسة
            </span>
            <span>
                <i className="pm-dot is-danger" aria-hidden="true" />
                خطيرة، تحتاج تأكيدًا عند الحفظ
            </span>
            <span>
                <i className="pm-dot is-dirty" aria-hidden="true" />
                لم تُحفظ بعد
            </span>
            <span>
                <b className="text-gray-400">—</b> غير متاحة لهذا القسم
            </span>
        </div>
    );
}
