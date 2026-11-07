import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let SubscriptionSwitcher;
before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Subscriptions/SubscriptionSwitcher.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'switcher', resolveId(id) {
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            if (id.endsWith('.css')) return '\0css';
        }, load(id) { if (id === '\0css') return ''; } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        ({ default: SubscriptionSwitcher } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

const subscription = { id: 1, accountNumber: '202600021', fullName: 'أحمد', meterBoxName: 'طبلون السوق أ', meterBoxNumber: '110303', areaName: 'المخيم', subAreaName: 'الحي الشرقي', tariffCategoryLabel: 'تجاري', status: 'active', statusLabel: 'نشط', balance: '-183' };
const render = (overrides = {}) => renderToStaticMarkup(createElement(SubscriptionSwitcher, { currentId: 1, onSelect() {}, subscriptions: [{ ...subscription, ...overrides }, { ...subscription, id: 2, accountNumber: '202600022', balance: '0' }] }));

test('subscription cards show the meter box name and suffix before its number, with both area levels', () => {
    const html = render();
    assert.match(html, /طبلون السوق أ<\/span><bdi[^>]*>\(110303\)/);
    assert.match(html, /المخيم \/ الحي الشرقي/);
    assert.match(html, /تجاري/);
    assert.match(html, /aria-current="true"/);
});

test('cards handle missing placement information without exposing undefined values', () => {
    const html = render({ meterBoxName: null, meterBoxNumber: null, areaName: null, subAreaName: null });
    assert.match(html, /غير محدد/);
    assert.match(html, /غير محددة/);
    assert.doesNotMatch(html, /undefined|null/);
});
