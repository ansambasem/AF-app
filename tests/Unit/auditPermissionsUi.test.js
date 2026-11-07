import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let Card;
let Summary;
let changes;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Settings/AuditAccessCard.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'use-installed-react', resolveId(id) {
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        const module = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`);
        Card = module.default;
        Summary = module.AuditWorkSummary;
        changes = module.auditSettingsChanges;
    } finally { await bundle.close(); }
});

const employee = { id: 1, name: 'مدقق الاختبار', role: 'financial_auditor', roleLabel: 'مدقق مالي', branchName: 'الإدارة الرئيسية', isActive: true };
const options = { permissionId: 2, branches: [{ id: 1, name: 'فرع نابلس' }, { id: 2, name: 'فرع جنين' }], actions: [
    { value: 'review_transfers', label: 'مراجعة الحوالات', hint: 'حفظ مبلغ البنك والسبب' },
    { value: 'approve_boxes', label: 'اعتماد الصناديق', hint: 'ترحيل فروق التدقيق' },
    { value: 'approve_periods', label: 'اعتماد الفترة', hint: 'اعتماد صناديق الفترة معًا' },
] };
const value = { scope: 'selected', branch_ids: [2], actions: ['review_transfers'] };

test('the audit card distinguishes the administrative branch from audit branches and locks period approval until box approval', () => {
    const html = renderToStaticMarkup(createElement(Card, { employee, options, value, enabled: true, onEnabledChange() {}, onChange() {} }));

    assert.match(html, /الفرع الإداري للموظف/);
    assert.match(html, /الإدارة الرئيسية/);
    assert.match(html, /فرع جنين/);
    assert.match(html, /aria-label="مراجعة الحوالات"[^>]*aria-checked="true"|aria-checked="true"[^>]*aria-label="مراجعة الحوالات"/);
    assert.match(html, /aria-label="اعتماد الفترة"[^>]*disabled=""/);
    assert.match(html, /فعّل اعتماد الصناديق أولًا/);
});

test('turning audit access off locks its controls and clearly removes the work scope from the summary', () => {
    const html = renderToStaticMarkup(createElement(Card, { employee, options, value, enabled: false, onEnabledChange() {}, onChange() {} }));
    assert.match(html, /<fieldset[^>]*disabled=""/);
    assert.match(html, /aria-label="مراجعة الحوالات"[^>]*disabled=""/);
    const summary = renderToStaticMarkup(createElement(Summary, { employee, options, value, enabled: false }));
    assert.match(summary, /التدقيق غير مفعّل/);
    assert.doesNotMatch(summary, /فرع جنين/);
    assert.doesNotMatch(summary, /مقفلة لهذا الدور/);
});

test('the work summary names the selected audit branch and only the actions granted', () => {
    const html = renderToStaticMarkup(createElement(Summary, { employee, options, value, enabled: true }));
    assert.match(html, /فرع جنين/);
    assert.match(html, /مراجعة الحوالات/);
    assert.doesNotMatch(html, /اعتماد الصناديق|اعتماد الفترة|فرع نابلس/);
});

test('the save review detects branch changes by identifier and ignores selection order', () => {
    assert.deepEqual(changes(value, { ...value, branch_ids: [2, 1] }, options), [{ key: 'scope', label: 'فروع التدقيق', before: 'فرع جنين', after: 'فرع نابلس، فرع جنين' }]);
    assert.deepEqual(changes({ ...value, branch_ids: [1, 2] }, { ...value, branch_ids: [2, 1] }, options), []);
    const duplicatedNames = { ...options, branches: [{ id: 1, name: 'فرع' }, { id: 2, name: 'فرع' }] };
    assert.equal(changes(value, { ...value, branch_ids: [1] }, duplicatedNames).length, 1);
});

test('granting approval is included as a dangerous action in the review', () => {
    assert.deepEqual(changes(value, { ...value, actions: ['review_transfers', 'approve_boxes'] }, options), [{ key: 'approve_boxes', label: 'اعتماد الصناديق', before: 'غير مسموح', after: 'مسموح', added: true, risk: 'danger' }]);
});
