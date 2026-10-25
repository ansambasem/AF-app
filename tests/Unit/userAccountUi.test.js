import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let UserForm;
let userFormData;
before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Users/UserForm.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'installed-react', resolveId(id) {
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        ({ default: UserForm, userFormData } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

const render = (overrides = {}) => renderToStaticMarkup(createElement(UserForm, {
    data: userFormData({ name: 'أحمد', username: 'ahmed', role: 'branch_admin', branch_id: 2, user_type_id: 3 }),
    errors: {}, isEdit: true, branches: [{ id: 2, name: 'الفرع الرئيسي' }], canChooseBranch: true,
    userTypeOptions: [{ value: 3, label: 'مشرف' }], setData() {}, ...overrides,
}));

test('account data sends job title and branch without a system role or automatic permission grant', () => {
    const data = userFormData({ role: 'financial_auditor', user_type_id: 3, branch_id: 2 });
    assert.equal(data.user_type_id, 3);
    assert.equal(data.branch_id, 2);
    assert.equal(Object.hasOwn(data, 'role'), false);
    assert.equal(Object.hasOwn(data, 'permissions'), false);
    const html = render();
    assert.match(html, /المسمى الوظيفي/);
    assert.doesNotMatch(html, /دور النظام|id="role"/);
    assert.match(html, /انسخ صلاحيات موظف آخر/);
});

test('editing an account exposes password inputs only with the separate password permission', () => {
    assert.doesNotMatch(render(), /id="password"/);
    const html = render({ canUpdatePassword: true });
    assert.match(html, /id="password"/);
    assert.match(html, /id="password_confirmation"/);
    assert.match(html, /اتركها فارغة للاحتفاظ بالحالية/);
});

test('device limits default to one and only account managers can change them or end sessions', () => {
    assert.equal(userFormData().max_devices, 1);
    assert.equal(userFormData({max_devices: 3}).max_devices, 3);
    assert.equal(userFormData({max_devices: 3}).reset_devices, false);
    assert.doesNotMatch(render(), /id="max_devices"|id="reset_devices"/);
    const html = render({canUpdatePassword: true});
    assert.match(html, /id="max_devices"/);
    assert.match(html, /id="reset_devices"/);
    assert.match(html, /خروج الجهاز الأقدم تلقائيًا/);
    assert.equal((html.match(/id="reset_devices"/g) ?? []).length, 1);
});
