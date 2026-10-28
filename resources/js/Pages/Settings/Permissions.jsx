import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AuditAccessCard, { AuditWorkSummary, auditScopeLabel, auditSettingsChanges } from './AuditAccessCard';
import Icon from '@/Components/Icon';
import ConfirmDialog from '@/Components/ConfirmDialog';
import PermissionGuide, { PermissionExplanation } from './PermissionGuide';
import EmployeeList, { EmployeeAvatar, RoleChip } from './PermissionPeople';
import { MatrixHead, MatrixLegend, SectionRow, TriToggle } from './PermissionMatrix';
import { PermissionCompare, PermissionSummary, RiskGauge } from './PermissionInsights';
import GrantRightPanel, { grantRightChanges } from './PermissionDelegation';
import { CATEGORIES } from '@/lib/permissionGuide';
import './PermissionDesign.css';
import { useDataTable } from '@/hooks/useDataTable';
import {
    copyWorkPermissions,
    filterSections,
    individualChanges,
    matchesQuery,
    matrixExtras,
    permissionEntries,
    permissionsWord,
    riskCounts,
    riskLevel,
    roleColors,
    samePermissions,
    sectionIds,
    sectionModel,
    withGroupAccess,
    withSectionsAll,
    withToggled,
} from '@/lib/permissions';
import PageHeader from '@/Components/PageHeader';
import { MenuButton, MenuItem } from './PermissionMenu';
import { Delta, PermissionChangeReview, PermissionReviewDialog, PermissionSaveBar } from './PermissionReview';
import Switch from '@/Components/Switch';

export { SectionRow, PermissionSummary, PermissionChangeReview };

const defaultColorOf = roleColors([]);

const MODES = [
    ['all', 'الكل'],
    ['enabled', 'المفعّلة'],
    ['sensitive', 'فيها صلاحيات خاصة'],
    ['changed', 'المعدّلة'],
];

/** What each group of sections covers, shown with «الشرح» on. */
const GROUP_NOTES = {
    basic: 'بيانات العمل اليومي: المشتركون والطبلونات والقواطع وأسعار الكيلو.',
    readings: 'إدخال القراءات الأسبوعية واعتمادها، وتوزيع الطبلونات على القرّاء الميدانيين والكشوف الورقية.',
    finance: 'الدفعات والحركات المالية والتقارير وقوائم القطع. أغلب خياراتها حساسة لأنها تغيّر أرصدة المشتركين.',
    audit: 'مراجعة الصناديق المغلقة ومبالغ البنك واعتماد الفروق. يمنحه السوبر أدمن لأشخاص محددين مع فروع تدقيقهم.',
    communication: 'رسائل المشتركين وقوالب الطباعة المشتركة لكل الشركة.',
    administration: 'حسابات الموظفين والفروع والمناطق. يدير أغلبها السوبر أدمن من اللوحة المركزية.',
};

/** Whether the grid's explanations were left on, remembered per browser. */
function storedExplaining() {
    try {
        return localStorage.getItem('permissions.explaining') === '1';
    } catch {
        return false;
    }
}

const TABS = [
    { key: 'matrix', label: 'الصلاحيات', icon: 'table' },
    { key: 'summary', label: 'ماذا يستطيع؟', icon: 'sparkles' },
    { key: 'compare', label: 'مقارنة', icon: 'repeat' },
];

/**
 * One employee's permissions. A dark header with who they are, how much
 * they hold and how risky it is, and ways to copy a colleague or print
 * their guide; then three views: the grid of every section by action, what
 * they can do in plain words, and a comparison with a colleague. Once
 * something changes, a bar offers to review and save; dangerous grants
 * must be acknowledged in the review before they are saved.
 */
export function PermissionEditor({
    employee,
    sections,
    templates,
    copySources,
    scopedToOwnBranch,
    onDirtyChange,
    auditOptions,
    delegation = null,
    colorOf = defaultColorOf,
    onSaved,
}) {
    const saved = employee.permissionIds;
    const savedKeys = employee.grantableIds ?? [];
    const savedAudit =
        auditOptions && !saved.includes(auditOptions.permissionId) && employee.auditSettingsConfigured === false
            ? { scope: 'selected', branch_ids: employee.branchId ? [employee.branchId] : [], actions: [] }
            : employee.auditSettings;
    const { data, setData, put, transform, processing, errors } = useForm({
        permissions: { [employee.id]: [...saved] },
        ...(auditOptions ? { audit_settings: { [employee.id]: savedAudit } } : {}),
        ...(delegation ? { grantable: { [employee.id]: [...savedKeys] } } : {}),
    });
    const selected = data.permissions[employee.id];
    // The right to hand on is kept only for permissions the employee still holds, and given only to whoever manages permissions.
    const canDelegate = Boolean(delegation && selected.includes(delegation.managePermissionsId));
    const keys = (data.grantable?.[employee.id] ?? []).filter((id) => selected.includes(id));
    const keyChanges = delegation ? grantRightChanges(savedKeys, keys) : { added: 0, removed: 0 };
    const auditValue = data.audit_settings?.[employee.id];
    const auditEnabled = Boolean(auditOptions && selected.includes(auditOptions.permissionId));
    const auditDraftInitialized = useRef(Boolean(auditOptions && (saved.includes(auditOptions.permissionId) || employee.auditSettingsConfigured)));
    const auditChanges = auditOptions ? auditSettingsChanges(savedAudit, auditValue, auditOptions) : [];
    const [tab, setTab] = useState('matrix');
    const [query, setQuery] = useState('');
    const [mode, setMode] = useState('all');
    const [openSections, setOpenSections] = useState(new Set());
    const [guideAudience, setGuideAudience] = useState(null);
    const [explanation, setExplanation] = useState(null);
    const [explaining, setExplainingState] = useState(storedExplaining);
    const [reviewing, setReviewing] = useState(false);
    const [acknowledged, setAcknowledged] = useState(false);
    const changes = useMemo(() => individualChanges(sections, saved, selected), [sections, saved, selected]);
    const auditGrants =
        auditEnabled && !saved.includes(auditOptions.permissionId)
            ? auditOptions.actions
                  .filter((action) => auditValue.actions.includes(action.value) && action.value.startsWith('approve_'))
                  .map((action) => ({ ...action, added: true, risk: 'danger' }))
            : auditEnabled
              ? auditChanges
              : [];
    const dangerousGrants = [...changes, ...auditGrants].filter((change) => change.added && change.risk === 'danger');
    const dirty = !samePermissions(saved, selected) || auditChanges.length > 0 || keyChanges.added + keyChanges.removed > 0;
    const tabs = canDelegate ? [...TABS, { key: 'delegation', label: 'حق المنح', icon: 'key' }] : TABS;
    const activeTab = tabs.some((item) => item.key === tab) ? tab : 'matrix';
    const takenFromOthers = changes.filter((change) => !change.added && employee.grantsGiven?.[change.id] > 0);
    const grantedBy = Object.entries(
        Object.values(employee.grantedBy ?? {}).reduce((counts, name) => ({ ...counts, [name]: (counts[name] ?? 0) + 1 }), {}),
    );
    const template = templates.find((item) => item.role === employee.role);
    const deviations = template ? individualChanges(sections, template.permissionIds, selected).length : null;
    const color = colorOf(employee.roleLabel);
    const counts = riskCounts(sections, selected);
    const heroRisk = riskLevel(sections, selected);
    const allCount = permissionEntries(sections).length;
    const locked = employee.lockedPermissions ?? [];
    const firstName = employee.name.split(' ')[0];
    const needle = query.trim();

    const matching = filterSections(sections, selected, saved, query, mode);
    if (mode === 'changed' && auditChanges.length > 0 && !matching.some((section) => section.key === 'financial_audit')) {
        matching.push(
            ...filterSections(
                sections.filter((section) => section.key === 'financial_audit'),
                selected,
                saved,
                query,
                'all',
            ),
        );
    }
    const listed = new Set(CATEGORIES.flatMap((category) => category.sections));
    const groups = [
        ...CATEGORIES.filter((category) => category.key !== 'all').map((category) => ({
            key: category.key,
            label: category.label,
            icon: category.icon,
            sections: category.sections.map((key) => matching.find((section) => section.key === key)).filter(Boolean),
        })),
        { key: 'other', label: 'أقسام أخرى', icon: 'layers', sections: matching.filter((section) => !listed.has(section.key)) },
    ].filter((group) => group.sections.length > 0);
    const allOpen = matching.length > 0 && matching.every((section) => openSections.has(section.key));

    function setExplaining(on) {
        setExplainingState(on);
        try {
            localStorage.setItem('permissions.explaining', on ? '1' : '0');
        } catch {
            // Private windows may refuse storage; the switch still works for this visit.
        }
    }

    /** Sets the selected permissions; switching the audit on for the first time starts its work at the employee's own branch. */
    function setSelected(ids) {
        if (
            auditOptions &&
            !auditDraftInitialized.current &&
            ids.includes(auditOptions.permissionId) &&
            !selected.includes(auditOptions.permissionId)
        ) {
            setAudit({ scope: 'selected', branch_ids: employee.branchId ? [employee.branchId] : [], actions: [] });
            auditDraftInitialized.current = true;
        }
        setData('permissions', { [employee.id]: ids });
    }

    function setAudit(value) {
        setData('audit_settings', { [employee.id]: value });
    }

    function setKeys(ids) {
        setData('grantable', { [employee.id]: ids });
    }

    /** Copy a colleague's work permissions; financial audit access and its scope stay a separate decision. */
    function copyFrom(user) {
        setSelected(copyWorkPermissions(sections, selected, user.permissionIds));
    }

    function discard() {
        setData((current) => ({
            ...current,
            permissions: { [employee.id]: [...saved] },
            ...(auditOptions ? { audit_settings: { [employee.id]: savedAudit } } : {}),
            ...(delegation ? { grantable: { [employee.id]: [...savedKeys] } } : {}),
        }));
    }

    /** Searching opens the sections whose match is among their options, so it can be seen. */
    function search(value) {
        setQuery(value);
        const text = value.trim();
        setOpenSections(
            new Set(
                text
                    ? sections
                          .filter((section) =>
                              matrixExtras(section).some((group) =>
                                  group.items.some((item) => matchesQuery(item.label, text) || matchesQuery(item.hint ?? '', text)),
                              ),
                          )
                          .map((section) => section.key)
                    : [],
            ),
        );
    }

    function toggleSection(key, next) {
        setOpenSections((old) => {
            const updated = new Set(old);
            next ? updated.add(key) : updated.delete(key);
            return updated;
        });
    }

    function save() {
        if (dirty && !processing) {
            setAcknowledged(false);
            setReviewing(true);
        }
    }

    function confirmSave() {
        if (dirty && !processing && (dangerousGrants.length === 0 || acknowledged)) {
            const previous = {
                permissions: { [employee.id]: [...saved] },
                ...(auditOptions ? { audit_settings: { [employee.id]: savedAudit } } : {}),
                ...(delegation ? { grantable: { [employee.id]: [...savedKeys] } } : {}),
            };
            transform((current) => (current.grantable ? { ...current, grantable: { [employee.id]: keys } } : current));
            put('/settings/permissions', {
                preserveScroll: true,
                onSuccess: () => {
                    setReviewing(false);
                    onSaved?.({ employeeId: employee.id, name: employee.name, previous });
                },
                onError: () => setReviewing(false),
            });
        }
    }

    function onTabKeyDown(event) {
        const index = tabs.findIndex((item) => item.key === activeTab);
        const step = event.key === 'ArrowLeft' ? 1 : event.key === 'ArrowRight' ? -1 : 0;

        if (step) {
            event.preventDefault();
            const next = tabs[(index + step + tabs.length) % tabs.length];
            setTab(next.key);
            document.getElementById(`permission-tab-${next.key}`)?.focus();
        }
    }

    useEffect(() => {
        onDirtyChange(dirty);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        function warnBeforeUnload(event) {
            event.preventDefault();
            event.returnValue = '';
        }

        window.addEventListener('beforeunload', warnBeforeUnload);
        return () => window.removeEventListener('beforeunload', warnBeforeUnload);
    }, [dirty]);

    // Ctrl+S (⌘S) saves.
    useEffect(() => {
        function onKeyDown(event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                if (!reviewing) {
                    save();
                }
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    });

    const added = changes.filter((item) => item.added).length;
    const removed = changes.length - added;
    const after = riskCounts(sections, selected);
    const before = riskCounts(sections, saved);

    return (
        <div className="min-w-0 space-y-4">
            <section className="pm-hero rise-in" style={{ '--role': color }} aria-label={`صلاحيات ${employee.name}`}>
                <div className="pm-hero-main">
                    <EmployeeAvatar name={employee.name} color={color} size="lg" />
                    <div className="min-w-0 flex-1">
                        <h3 className="break-words text-2xl font-bold">{employee.name}</h3>
                        <p className="pm-hero-meta">
                            <RoleChip label={employee.roleLabel} color={color} className="on-dark" />
                            <span className="inline-flex items-center gap-1">
                                <Icon name="office" className="h-4 w-4" />
                                {employee.branchName ?? 'بلا فرع'}
                            </span>
                            <span className="font-display" dir="ltr">
                                @{employee.username}
                            </span>
                            {employee.isActive === false && <span className="pm-hero-flag">الحساب موقوف</span>}
                        </p>
                        <p className="pm-hero-stats">
                            <span>
                                <Icon name="key" className="h-4 w-4" />
                                <b className="font-display">{counts.total}</b> من <b className="font-display">{allCount}</b>{' '}
                                {permissionsWord(allCount)}
                            </span>
                            <span className="is-danger">
                                <Icon name="alert" className="h-4 w-4" />
                                <b className="font-display">{counts.danger}</b> خطيرة
                            </span>
                            <span className="is-sensitive">
                                <Icon name="info" className="h-4 w-4" />
                                <b className="font-display">{counts.sensitive}</b> حساسة
                            </span>
                            <span className={`pm-hero-risk is-${heroRisk.level}`}>خطورة {heroRisk.label}</span>
                            {grantedBy.map(([name, count]) => (
                                <span key={name}>
                                    <Icon name="user" className="h-4 w-4" />
                                    منحه {name} <b className="font-display">{count}</b>
                                </span>
                            ))}
                            {locked.length > 0 && (
                                <span title={locked.join('، ')}>
                                    <Icon name="lock" className="h-4 w-4" />
                                    <b className="font-display">{locked.length}</b> خارج نطاق إدارتك
                                </span>
                            )}
                        </p>
                    </div>
                    <RiskGauge sections={sections} selected={selected} onDark />
                </div>

                <div className="pm-hero-actions">
                    <button type="button" className="pm-hero-button" onClick={() => setGuideAudience('selected')}>
                        <Icon name="document" className="h-4 w-4" />
                        دليل الموظف
                    </button>
                    <MenuButton icon="users" label="نسخ من موظف" className="pm-hero-button">
                        {(close) =>
                            copySources.length === 0 ? (
                                <p className="px-3 py-4 text-center text-sm text-gray-500">لا يوجد موظفون آخرون في هذه الصفحة.</p>
                            ) : (
                                copySources.map((user) => {
                                    const diff = individualChanges(sections, selected, copyWorkPermissions(sections, selected, user.permissionIds));

                                    return (
                                        <MenuItem
                                            key={user.id}
                                            leading={<EmployeeAvatar name={user.name} color={colorOf(user.roleLabel)} size="sm" />}
                                            title={user.name}
                                            subtitle={`${user.roleLabel} · ${user.branchName ?? 'بلا فرع'}`}
                                            trailing={
                                                <Delta
                                                    added={diff.filter((item) => item.added).length}
                                                    removed={diff.filter((item) => !item.added).length}
                                                />
                                            }
                                            onSelect={() => {
                                                copyFrom(user);
                                                close();
                                            }}
                                        />
                                    );
                                })
                            )
                        }
                    </MenuButton>
                </div>

                {template && (
                    <p className="pm-hero-template">
                        <Icon name="shield" className="h-[18px] w-[18px]" />
                        {deviations === 0 ? (
                            <span>
                                مطابق تمامًا لقالب <b>«{template.label}»</b>
                            </span>
                        ) : (
                            <span>
                                مخصّص: <b className="font-display">{deviations}</b> {deviations === 1 ? 'اختلاف' : 'اختلافات'} عن قالب{' '}
                                <b>«{template.label}»</b>
                            </span>
                        )}
                        {deviations > 0 && (
                            <button
                                type="button"
                                onClick={() => setSelected([...template.permissionIds])}
                                disabled={processing}
                                className="ms-auto font-semibold underline-offset-4 hover:underline"
                            >
                                إعادة إلى القالب
                            </button>
                        )}
                    </p>
                )}

                <div className="pm-tabs" role="tablist" aria-label="عرض صلاحيات الموظف">
                    {tabs.map((item) => (
                        <button
                            key={item.key}
                            id={`permission-tab-${item.key}`}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === item.key}
                            aria-controls={`permission-view-${item.key}`}
                            tabIndex={activeTab === item.key ? 0 : -1}
                            onClick={() => setTab(item.key)}
                            onKeyDown={onTabKeyDown}
                            className={`pm-tab ${activeTab === item.key ? 'is-active' : ''}`}
                        >
                            <Icon name={item.icon} className="h-4 w-4" />
                            {item.label}
                            {item.key === 'matrix' && dirty && <span className="pm-unsaved" title="تغييرات لم تُحفظ" />}
                        </button>
                    ))}
                </div>
            </section>

            {activeTab === 'matrix' && (
                <div id="permission-view-matrix" role="tabpanel" aria-labelledby="permission-tab-matrix" className="space-y-4">

                    <section className="pm-card pm-matrix" aria-label="جدول الصلاحيات">
                        <div className="pm-tools">
                            <div className="pm-search">
                                <Icon
                                    name="search"
                                    className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400"
                                />
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(event) => search(event.target.value)}
                                    placeholder="ابحث بكلمة: دفعة، خصم، اعتماد، حذف..."
                                    aria-label="ابحث عن صلاحية"
                                    className="block w-full py-2 pe-3 ps-10 text-sm"
                                />
                            </div>
                            <div className="pm-modes" role="group" aria-label="تصفية الأقسام">
                                {MODES.map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => setMode(value)}
                                        aria-pressed={mode === value}
                                        className="pm-mode"
                                    >
                                        {label}
                                        <span className="font-display">{filterSections(sections, selected, saved, '', value).length}</span>
                                    </button>
                                ))}
                            </div>
                            <div className="pm-tool-actions">
                                <span className="me-2 inline-flex items-center">
                                    <Switch checked={explaining} onChange={setExplaining} label="الشرح" ariaLabel="إظهار شرح الأقسام والأزرار" />
                                </span>
                                <button
                                    type="button"
                                    className="pm-ghost-button"
                                    disabled={processing || matching.length === 0}
                                    title="يجعل كل قسم معروض للعرض فقط"
                                    onClick={() => setSelected(withGroupAccess(matching, selected, true))}
                                >
                                    <Icon name="eye" className="h-4 w-4" />
                                    عرض فقط
                                </button>
                                <button
                                    type="button"
                                    className="pm-ghost-button"
                                    disabled={processing || matching.length === 0}
                                    title="يسحب صلاحيات الأقسام المعروضة"
                                    onClick={() => setSelected(withSectionsAll(matching, selected, false))}
                                >
                                    <Icon name="close" className="h-4 w-4" />
                                    سحب الكل
                                </button>
                                <button
                                    type="button"
                                    className="pm-ghost-button"
                                    onClick={() => setOpenSections(allOpen ? new Set() : new Set(matching.map((section) => section.key)))}
                                >
                                    <Icon name={allOpen ? 'collapse' : 'expand'} className="h-4 w-4" />
                                    {allOpen ? 'طيّ الكل' : 'فتح الكل'}
                                </button>
                                <button type="button" className="pm-ghost-button is-brand" onClick={() => setGuideAudience('all')}>
                                    <Icon name="document" className="h-4 w-4" />
                                    دليل الصلاحيات
                                </button>
                            </div>
                        </div>
                        {needle && matching.length > 0 && (
                            <p className="pm-found" aria-live="polite">
                                تطابق <b className="font-display">{matching.length}</b> {matching.length === 1 ? 'قسمًا' : 'أقسام'}
                            </p>
                        )}

                        {explaining && (
                            <div className="pm-explain-note">
                                <p>
                                    كل خانة صلاحية مستقلة: تحديد «تعديل» لا يمنح «عرض» أو «إضافة» معه. الخيارات الخاصة بكل قسم تفتح من «خيارات»،
                                    و«ⓘ» تحت أي خانة يشرح ما تتيحه وحدودها.{' '}
                                    {scopedToOwnBranch
                                        ? 'تمنح وتسحب الصلاحيات المتاحة هنا لموظفي فرعك فقط؛ التفويض الإداري والصلاحيات العامة يديرها السوبر أدمن.'
                                        : 'لتمكين مدير الفرع من إضافة موظفين وتوزيع صلاحياتهم، فعّل له «إضافة المستخدمين» و«إدارة صلاحيات موظفي الفرع». المسمى الوظيفي وحده لا يمنح صلاحيات.'}
                                </p>
                                <dl>
                                    <dt>عرض فقط</dt>
                                    <dd>يترك لكل قسم ظاهر صلاحية العرض فقط ويسحب ما سواها.</dd>
                                    <dt>سحب الكل</dt>
                                    <dd>يسحب كل صلاحيات الأقسام الظاهرة.</dd>
                                    <dt>فتح الكل</dt>
                                    <dd>يفتح خيارات كل الأقسام دفعة واحدة دون تغيير شيء.</dd>
                                    <dt>الكل</dt>
                                    <dd>آخر عمود: يمنح أو يسحب كل صلاحيات القسم أو المجموعة.</dd>
                                    <dt>دليل الصلاحيات</dt>
                                    <dd>دليل كامل قابل للبحث والطباعة لكل الصلاحيات.</dd>
                                </dl>
                            </div>
                        )}

                        <div className={`pm-grid ${explaining ? 'is-explaining' : ''}`}>
                            <MatrixHead />
                            {groups.length === 0 ? (
                                <div className="px-5 py-12 text-center text-sm text-gray-500">
                                    <Icon name="search" className="mx-auto h-8 w-8 text-gray-300" />
                                    <p className="mt-2">لا توجد صلاحيات تطابق البحث أو التصفية.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            search('');
                                            setMode('all');
                                        }}
                                        className="mt-2 font-semibold text-brand-600"
                                    >
                                        عرض كل الصلاحيات
                                    </button>
                                </div>
                            ) : (
                                groups.map((group) => {
                                    const ids = group.sections.flatMap(sectionIds);
                                    const held = ids.filter((id) => selected.includes(id)).length;

                                    return (
                                        <section key={group.key} className="pm-group" aria-label={group.label}>
                                            <div className="pm-group-head">
                                                <span className="pm-group-icon">
                                                    <Icon name={group.icon} className="h-4 w-4" />
                                                </span>
                                                <h4>{group.label}</h4>
                                                <span className="pm-group-count" style={{ '--fill': `${ids.length ? Math.round((held / ids.length) * 100) : 0}%` }}>
                                                    <b className="font-display">{held}</b> من <b className="font-display">{ids.length}</b>
                                                    <i aria-hidden="true" />
                                                </span>
                                                <span className="flex-1" />
                                                {group.sections.some(
                                                    (section) => section.ladder?.actions.includes('view') || section.key === 'reports',
                                                ) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setSelected(withGroupAccess(group.sections, selected, true))}
                                                        disabled={processing}
                                                        className="pm-link-button"
                                                    >
                                                        عرض فقط للمجموعة
                                                    </button>
                                                )}
                                                <TriToggle
                                                    ids={ids}
                                                    selected={selected}
                                                    onChange={(on) => setSelected(withSectionsAll(group.sections, selected, on))}
                                                    disabled={processing}
                                                    label={`كل صلاحيات ${group.label}`}
                                                />
                                                {explaining && GROUP_NOTES[group.key] && <p className="pm-group-note">{GROUP_NOTES[group.key]}</p>}
                                            </div>
                                            {group.sections.map((section) =>
                                                section.key === 'financial_audit' && auditOptions ? (
                                                    <SectionRow
                                                        key={section.key}
                                                        section={section}
                                                        selected={selected}
                                                        saved={saved}
                                                        onChange={setSelected}
                                                        disabled={processing}
                                                        open={openSections.has(section.key)}
                                                        onToggle={(next) => toggleSection(section.key, next)}
                                                        onExplain={setExplanation}
                                                        explaining={explaining}
                                                        query={query}
                                                        summary={
                                                            auditEnabled
                                                                ? `مفعّل · ${auditScopeLabel(auditValue, auditOptions.branches)}`
                                                                : 'غير مفعّل · يمنحه السوبر أدمن لأشخاص محددين'
                                                        }
                                                        changed={auditChanges.length > 0}
                                                    >
                                                        <AuditAccessCard
                                                            embedded
                                                            employee={employee}
                                                            options={auditOptions}
                                                            value={auditValue}
                                                            enabled={auditEnabled}
                                                            onEnabledChange={(checked) =>
                                                                setSelected(withToggled(selected, auditOptions.permissionId, checked))
                                                            }
                                                            onChange={setAudit}
                                                            disabled={processing}
                                                        />
                                                    </SectionRow>
                                                ) : (
                                                    <SectionRow
                                                        key={section.key}
                                                        section={section}
                                                        selected={selected}
                                                        saved={saved}
                                                        onChange={setSelected}
                                                        disabled={processing}
                                                        open={openSections.has(section.key)}
                                                        onToggle={(next) => toggleSection(section.key, next)}
                                                        onExplain={setExplanation}
                                                        explaining={explaining}
                                                        query={query}
                                                    />
                                                ),
                                            )}
                                        </section>
                                    );
                                })
                            )}
                        </div>

                        <MatrixLegend />
                    </section>
                </div>
            )}

            {activeTab === 'summary' && (
                <div
                    id="permission-view-summary"
                    role="tabpanel"
                    aria-labelledby="permission-tab-summary"
                    className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_320px]"
                >
                    <PermissionSummary
                        employee={employee}
                        sections={sections}
                        selected={selected}
                        template={template}
                        scopedToOwnBranch={scopedToOwnBranch}
                    />
                    <div className="space-y-4">
                        <div className="pm-card flex flex-col items-center gap-2 p-5 text-center">
                            <h4 className="font-bold text-gray-900">مستوى الخطورة</h4>
                            <RiskGauge sections={sections} selected={selected} large />
                            <p className="text-[13.5px] leading-6 text-gray-500">
                                يزيد مع الصلاحيات التي تحرّك المال أو تغيّر الخدمة. اسحب ما لا يحتاجه {firstName} لعمله.
                            </p>
                        </div>
                        {auditOptions && auditEnabled && (
                            <AuditWorkSummary employee={employee} options={auditOptions} value={auditValue} enabled={auditEnabled} />
                        )}
                    </div>
                </div>
            )}

            {activeTab === 'compare' && (
                <div id="permission-view-compare" role="tabpanel" aria-labelledby="permission-tab-compare">
                    <PermissionCompare
                        employee={employee}
                        sections={sections}
                        selected={selected}
                        colleagues={copySources}
                        colorOf={colorOf}
                        disabled={processing}
                        onAddMissing={(ids) => setSelected([...new Set([...selected, ...ids])])}
                        onMatch={copyFrom}
                    />
                </div>
            )}

            {activeTab === 'delegation' && (
                <div id="permission-view-delegation" role="tabpanel" aria-labelledby="permission-tab-delegation">
                    <GrantRightPanel
                        sections={sections}
                        selected={selected}
                        grantable={keys}
                        delegableIds={delegation.delegableIds}
                        grantsGiven={employee.grantsGiven}
                        firstName={firstName}
                        disabled={processing}
                        onChange={setKeys}
                    />
                </div>
            )}

            <PermissionExplanation entry={explanation} onClose={() => setExplanation(null)} />
            {guideAudience && (
                <PermissionGuide
                    key={guideAudience}
                    show
                    onClose={() => setGuideAudience(null)}
                    sections={sections}
                    employee={employee}
                    selected={selected}
                    scopedToOwnBranch={scopedToOwnBranch}
                    auditOptions={auditOptions}
                    auditValue={auditValue}
                    initialAudience={guideAudience}
                />
            )}
            {/* Room at the end of the page, so the bar never hides the last section. */}
            {dirty && <div className="h-20" aria-hidden="true" />}

            <PermissionSaveBar
                dirty={dirty}
                errors={errors}
                changes={changes}
                auditChanges={auditChanges}
                firstName={firstName}
                added={added}
                removed={removed}
                dangerousGrants={dangerousGrants}
                processing={processing}
                onDiscard={discard}
                onSave={save}
            />

            <PermissionReviewDialog
                show={reviewing}
                employee={employee}
                changes={changes}
                auditChanges={auditChanges}
                keyChanges={keyChanges}
                takenFromOthers={takenFromOthers}
                dangerousGrants={dangerousGrants}
                added={added}
                removed={removed}
                before={before}
                after={after}
                heroRisk={heroRisk}
                firstName={firstName}
                acknowledged={acknowledged}
                onAcknowledgedChange={setAcknowledged}
                dirty={dirty}
                processing={processing}
                onCancel={() => setReviewing(false)}
                onConfirm={confirmSave}
            />
        </div>
    );
}

export default function Permissions({
    users,
    selectedUser,
    permissionGroups,
    roleTemplates,
    filters,
    filterOptions,
    scopedToOwnBranch,
    auditOptions = null,
    delegation = null,
}) {
    const hasUnsavedChanges = useRef(false);
    const [selectedDirty, setSelectedDirty] = useState(false);
    const editorRef = useRef(null);
    const searchRef = useRef(null);
    // The employee picked while the current one has unsaved changes, waiting on "discard them?".
    const [pendingEmployee, setPendingEmployee] = useState(null);
    // The last save, kept for a short while so it can be undone.
    const [lastSave, setLastSave] = useState(null);
    const [undoing, setUndoing] = useState(false);
    const { search, setSearch, filterValues, setFilter } = useDataTable('/settings/permissions', filters, { selected: selectedUser?.id });
    const sections = useMemo(() => permissionGroups.map(sectionModel).filter((section) => sectionIds(section).length > 0), [permissionGroups]);
    const colorOf = useMemo(
        () => roleColors((filterOptions.find((group) => group.key === 'user_type_id')?.options ?? []).map((option) => option.label)),
        [filterOptions],
    );
    const allCount = useMemo(() => permissionEntries(sections).length, [sections]);

    function statsOf(user) {
        const counts = riskCounts(sections, user.permissionIds);
        const risk = riskLevel(sections, user.permissionIds);

        return { ...counts, share: allCount ? Math.round((counts.total / allCount) * 100) : 0, level: risk.level, label: risk.label };
    }

    useEffect(() => {
        if (!lastSave) {
            return;
        }

        const timer = setTimeout(() => setLastSave(null), 15000);
        return () => clearTimeout(timer);
    }, [lastSave]);

    function undoLastSave() {
        if (!lastSave || undoing) {
            return;
        }

        setUndoing(true);
        router.put('/settings/permissions', lastSave.previous, {
            preserveScroll: true,
            onFinish: () => {
                setUndoing(false);
                setLastSave(null);
            },
        });
    }

    function selectEmployee(user) {
        if (user.id === selectedUser?.id) {
            return;
        }

        if (hasUnsavedChanges.current) {
            setPendingEmployee(user);
        } else {
            openEmployee(user);
        }
    }

    function openEmployee(user) {
        setPendingEmployee(null);
        router.get(
            '/settings/permissions',
            {
                search,
                sort: filters.sort,
                direction: filters.direction,
                per_page: filters.per_page,
                filter: filterValues,
                page: users.current_page,
                selected: user.id,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['selectedUser'],
                onSuccess: () => {
                    // On narrow screens the editor sits below the list: bring it into view.
                    if (window.innerWidth < 1024) {
                        editorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                },
            },
        );
    }

    // "/" jumps to the employee search; J and K move to the next and previous employee.
    useEffect(() => {
        function onKeyDown(event) {
            if (event.target.closest?.('input, textarea, select, [contenteditable="true"]') || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                searchRef.current?.focus();
            } else if (event.key === 'j' || event.key === 'k') {
                const index = users.data.findIndex((user) => user.id === selectedUser?.id);
                const next = users.data[index + (event.key === 'j' ? 1 : -1)];

                if (next) {
                    selectEmployee(next);
                }
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    });

    return (
        <SettingsLayout
            header={
                <PageHeader title="إدارة صلاحيات الموظفين" info="اختر موظفًا، وحدّد ما يستطيع فعله في كل قسم، ثم راجع التغييرات وأكّد حفظها." />
            }
        >
            <Head title="الصلاحيات" />

            <div className="grid items-start gap-5 lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[320px_minmax(0,1fr)]">
                <EmployeeList
                    users={users}
                    selectedId={selectedUser?.id}
                    selectedDirty={selectedDirty}
                    filters={filters}
                    filterOptions={filterOptions}
                    search={search}
                    onSearchChange={setSearch}
                    filterValues={filterValues}
                    onFilterChange={setFilter}
                    onSelect={selectEmployee}
                    searchRef={searchRef}
                    colorOf={colorOf}
                    statsOf={statsOf}
                />

                <div ref={editorRef} className="min-w-0 scroll-mt-24">
                    {selectedUser ? (
                        <PermissionEditor
                            // A fresh editor per employee and per saved state, so the levels always start from what's stored.
                            key={`${selectedUser.id}:${selectedUser.permissionIds.join(',')}:${(selectedUser.grantableIds ?? []).join(',')}:${JSON.stringify(selectedUser.auditSettings)}`}
                            employee={selectedUser}
                            auditOptions={auditOptions}
                            delegation={delegation}
                            sections={sections}
                            templates={roleTemplates}
                            copySources={users.data.filter((user) => user.id !== selectedUser.id)}
                            scopedToOwnBranch={scopedToOwnBranch}
                            colorOf={colorOf}
                            onSaved={setLastSave}
                            onDirtyChange={(dirty) => {
                                hasUnsavedChanges.current = dirty;
                                setSelectedDirty(dirty);
                            }}
                        />
                    ) : (
                        <div className="flex min-h-[20rem] flex-col items-center justify-center rounded-card border border-dashed border-gray-200 bg-surface p-8 text-center">
                            <Icon name="shield" className="h-10 w-10 text-gray-300" />
                            <p className="mt-3 text-sm font-medium text-gray-600">لا يوجد موظفون لإدارة صلاحياتهم.</p>
                        </div>
                    )}
                </div>
            </div>

            <p className="mt-4 hidden flex-wrap gap-4 text-xs text-gray-500 lg:flex">
                <span>
                    <span className="kbd">/</span> بحث عن موظف
                </span>
                <span>
                    <span className="kbd">J</span> <span className="kbd">K</span> الموظف التالي والسابق
                </span>
                <span>
                    <span className="kbd">Ctrl</span> + <span className="kbd">S</span> مراجعة وحفظ
                </span>
            </p>

            {lastSave && !selectedDirty && (
                <div className="pm-toast" role="status">
                    <Icon name="check" className="h-5 w-5 shrink-0 text-emerald-400" strokeWidth={2.5} />
                    <span className="min-w-0 flex-1">حُفظت صلاحيات {lastSave.name}.</span>
                    <button type="button" onClick={undoLastSave} disabled={undoing} className="pm-toast-action">
                        <Icon name="undo" className="h-4 w-4" />
                        {undoing ? 'جارٍ التراجع...' : 'تراجع عن الحفظ'}
                    </button>
                    <button
                        type="button"
                        onClick={() => setLastSave(null)}
                        aria-label="إخفاء"
                        className="rounded-lg p-1 text-white/70 hover:text-white"
                    >
                        <Icon name="close" className="h-4 w-4" />
                    </button>
                </div>
            )}

            <ConfirmDialog
                show={Boolean(pendingEmployee)}
                onConfirm={() => openEmployee(pendingEmployee)}
                onCancel={() => setPendingEmployee(null)}
                title="تجاهل التغييرات؟"
                message={`لديك تعديلات على صلاحيات ${selectedUser?.name ?? 'هذا الموظف'} لم تُحفظ بعد. إذا انتقلت إلى موظف آخر فستفقدها.`}
                confirmLabel="تجاهل التغييرات"
                cancelLabel="البقاء ومتابعة التعديل"
                icon="alert"
                tone="danger"
            />
        </SettingsLayout>
    );
}
