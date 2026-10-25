import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';
import { individualChanges, sectionModel } from '../../resources/js/lib/permissions.js';

let PermissionSummary;
let PermissionChangeReview;
let PermissionEditor;
let SectionRow;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Settings/Permissions.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'render-permission-components',
            resolveId(id) {
                if (/^react(?:-dom)?(?:\/|$)/.test(id) || id === '@inertiajs/react') {
                    return { id: import.meta.resolve(id), external: true };
                }
                if (id.endsWith('.css')) { return '\0stylesheet'; }
            },
            load(id) { if (id === '\0stylesheet') { return ''; } },
        }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        ({ PermissionSummary, PermissionChangeReview, PermissionEditor, SectionRow } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

const employee = { name: 'Test Collector', roleLabel: 'محصل', branchName: 'فرع الاختبار', lockedPermissions: ['عرض كل الفروع والتقارير'] };
const payments = sectionModel({ key: 'collections', label: 'الدفعات والحركات المالية', actions: [
    { action: 'view', permission: { id: 1, label: 'عرض السجل المالي' } },
    { action: 'record', permission: { id: 2, label: 'إضافة دفعة' } },
    { action: 'force_delete', permission: { id: 3, label: 'الحذف النهائي للحركة' } },
] });

test('the auditor editor allows individually assigned work and keeps copying permissions without role templates', () => {
    const auditor = { ...employee, id: 1, username: 'audit', role: 'financial_auditor', roleLabel: 'مدقق مالي', permissionIds: [999], auditSettings: { scope: 'selected', branch_ids: [1], actions: ['review_transfers'] } };
    const audit = sectionModel({ key: 'financial_audit', label: 'التدقيق المالي', actions: [{ action: 'audit', permission: { id: 999, key: 'closings.audit', label: 'التدقيق المالي', centralOnly: true } }] });
    const html = renderToStaticMarkup(createElement(PermissionEditor, { employee: auditor, sections: [payments, audit], templates: [], copySources: [], scopedToOwnBranch: false, onDirtyChange() {}, auditOptions: { permissionId: 999, branches: [{ id: 1, name: 'فرع العمل' }], actions: [{ value: 'review_transfers', label: 'مراجعة الحوالات', hint: 'تسجيل مبلغ البنك' }] } }));

    assert.match(html, /عمل التدقيق المالي/);
    assert.doesNotMatch(html, /الصلاحيات التشغيلية مقفلة لدور المدقق/);
    assert.match(html, /فرع العمل/);
    assert.doesNotMatch(html, /مقفل لهذا الدور/);
    assert.match(html, /لا يملك عرض السجل أو إضافة دفعة/);
    assert.match(html, /نسخ من موظف/);
    assert.doesNotMatch(html, /تطبيق قالب/);
    assert.doesNotMatch(html, /aria-expanded="true"/);
});

test('reports and custom permission combinations start collapsed while retaining their exact controls', () => {
    const reports = sectionModel({ key: 'reports', label: 'التقارير', actions: [{ action: 'view', permission: { id: 5, label: 'عرض التقارير' } }] });
    for (const section of [reports, payments]) {
        const html = renderToStaticMarkup(createElement(SectionRow, { section, selected: [2, 5], saved: [2, 5], onChange() {} }));
        assert.match(html, /aria-expanded="false"/);
        assert.match(html, /class="permission-panel-body" hidden=""/);
        assert.doesNotMatch(html, /aria-expanded="true"/);
    }
    const opened = renderToStaticMarkup(createElement(SectionRow, { section: reports, selected: [5], saved: [5], open: true, onChange() {}, onToggle() {} }));
    assert.match(opened, /aria-expanded="true"/);
    assert.doesNotMatch(opened, /class="permission-panel-body" hidden/);
});

test('matrix shows an edit-only employee with independent accessible checkboxes', () => {
    const section = sectionModel({ key: 'subscriptions', label: 'المشتركون', actions: [
        { action: 'view', permission: { id: 10, label: 'عرض المشتركين' } },
        { action: 'create', permission: { id: 11, label: 'إضافة المشتركين' } },
        { action: 'update', permission: { id: 12, label: 'تعديل المشتركين' } },
    ] });
    const html = renderToStaticMarkup(createElement(SectionRow, { section, selected: [12], saved: [12], onChange() {} }));

    assert.match(html, /role="checkbox" aria-checked="true" aria-label="المشتركون: تعديل المشتركين"/);
    assert.match(html, /role="checkbox" aria-checked="false" aria-label="المشتركون: عرض المشتركين"/);
    assert.match(html, /role="checkbox" aria-checked="false" aria-label="المشتركون: إضافة المشتركين"/);
    assert.doesNotMatch(html, /role="radiogroup"/);
});

test('the editor has keyboard-addressable tabs and keeps an unchanged save bar inert', () => {
    const html = renderToStaticMarkup(createElement(PermissionEditor, { employee: { ...employee, permissionIds: [1] }, sections: [payments], templates: [], copySources: [], scopedToOwnBranch: true, onDirtyChange() {} }));

    assert.match(html, /role="tablist" aria-label="عرض صلاحيات الموظف"/);
    assert.match(html, /id="permission-tab-matrix" type="button" role="tab" aria-selected="true" aria-controls="permission-view-matrix" tabindex="0"/);
    assert.match(html, /id="permission-tab-summary" type="button" role="tab" aria-selected="false" aria-controls="permission-view-summary" tabindex="-1"/);
    assert.match(html, /id="permission-view-matrix" role="tabpanel" aria-labelledby="permission-tab-matrix"/);
    assert.doesNotMatch(html, /id="permission-view-summary"/);
    assert.match(html, /aria-label="تغييرات لم تُحفظ"[^>]*aria-hidden="true" inert=""/);
});

test('an employee without configured audit work starts with their own branch and no audit actions selected', () => {
    const audit = sectionModel({ key: 'financial_audit', label: 'التدقيق المالي', actions: [{ action: 'audit', permission: { id: 999, key: 'closings.audit', label: 'التدقيق المالي', centralOnly: true } }] });
    const actor = { ...employee, id: 1, branchId: 1, username: 'employee', permissionIds: [], auditSettingsConfigured: false, auditSettings: { scope: 'all', branch_ids: [], actions: ['approve_boxes'] } };
    const html = renderToStaticMarkup(createElement(PermissionEditor, { employee: actor, sections: [audit], templates: [], copySources: [], scopedToOwnBranch: false, onDirtyChange() {}, auditOptions: { permissionId: 999, branches: [{ id: 1, name: 'فرع العمل' }], actions: [{ value: 'approve_boxes', label: 'اعتماد الصندوق', hint: 'ترحيل الفروق' }] } }));
    assert.match(html, /aria-label="تفعيل التدقيق المالي"[^>]*aria-checked="false"|aria-checked="false"[^>]*aria-label="تفعيل التدقيق المالي"/);
    assert.match(html, /aria-label="اعتماد الصندوق"[^>]*disabled=""/);
    assert.doesNotMatch(html, /aria-checked="true"[^>]*aria-label="اعتماد الصندوق"/);
    assert.match(html, /type="radio"[^>]*checked=""/);
});

test('summary clearly limits its count to editable grants and identifies preserved rights', () => {
    const html = renderToStaticMarkup(createElement(PermissionSummary, { employee, sections: [payments], selected: [1, 2], template: { label: 'محصل', permissionIds: [1] }, scopedToOwnBranch: true }));
    assert.match(html, /المعروض هو ما يمكنك تعديله لموظفي فرعك/);
    assert.match(html, /صلاحية خارج نطاق إدارتك/);
    assert.match(html, /عرض كل الفروع والتقارير/);
    assert.match(html, /اختلاف عن قالب الدور/);
    assert.doesNotMatch(html, /%/);
});

test('full review names every removed and added action by section, with its consequences and risk', () => {
    const changes = individualChanges([payments], [1, 2], [1, 3]);
    const html = renderToStaticMarkup(createElement(PermissionChangeReview, { employee, changes }));
    assert.match(html, /إضافة دفعة/);
    assert.match(html, /الحذف النهائي للحركة/);
    assert.match(html, /الدفعات والحركات المالية/);
    assert.match(html, /سيُلغى/);
    assert.match(html, /سيُمنح/);
    assert.match(html, /خطير/);
    assert.match(html, /لا يحذف القراءة/);
    assert.match(html, /جميع تغييرات الصلاحيات/);
});

test('the grid shows each action as its own check, the options behind «خيارات», and the risk in the header', () => {
    const subscriptions = sectionModel({ key: 'subscriptions', label: 'المشتركون', actions: [
        { action: 'view', permission: { id: 11, label: 'عرض المشتركين' } },
        { action: 'create', permission: { id: 12, label: 'إضافة المشتركين' } },
        { action: 'update', permission: { id: 13, label: 'تعديل المشتركين' } },
        { action: 'delete', permission: { id: 14, label: 'حذف المشتركين' } },
        { action: 'minimum_charge', permission: { id: 15, label: 'الحد الأدنى' } },
    ] });
    const user = { ...employee, id: 7, username: 'collector', role: 'collector', permissionIds: [13, 14] };
    const html = renderToStaticMarkup(createElement(PermissionEditor, { employee: user, sections: [subscriptions, payments], templates: [], copySources: [], scopedToOwnBranch: false, onDirtyChange() {} }));

    assert.match(html, /role="checkbox" aria-checked="false" aria-label="المشتركون: عرض المشتركين"/);
    assert.match(html, /role="checkbox" aria-checked="true" aria-label="المشتركون: تعديل المشتركين"/);
    assert.match(html, /role="checkbox" aria-checked="true" aria-label="المشتركون: الحذف"/);
    assert.match(html, /aria-expanded="false"[^>]*aria-label="خيارات المشتركون"/);
    assert.match(html, />1<\/b> خطيرة/);
    assert.doesNotMatch(html, /aria-expanded="true"/);
});

test('a row explains its section and each check only while explanations are on', () => {
    const quiet = renderToStaticMarkup(createElement(SectionRow, { section: payments, selected: [1], saved: [1], onChange() {}, onExplain() {} }));
    assert.doesNotMatch(quiet, /pm-section-what|pm-cell-why/);

    const explained = renderToStaticMarkup(createElement(SectionRow, { section: payments, selected: [1], saved: [1], onChange() {}, onExplain() {}, explaining: true }));
    assert.match(explained, /السجل المالي والدفعات وحركات الحسابات/);
    assert.match(explained, /ماذا تتيح «عرض السجل المالي» في الدفعات والحركات المالية/);
});
