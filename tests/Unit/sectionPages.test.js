import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';
import { navigationGroups, pageTrail, topBarItems } from '../../resources/js/lib/navigation.js';

let SectionPages;
let MegaMenu;

before(async () => {
    const bundle = await rolldown({
        input: '\0navigation-components',
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'render-section-links',
            resolveId(id) {
                if (id === '\0navigation-components') return id;
                if (id === '@inertiajs/react') return '\0inertia-link';
                if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            },
            load(id) {
                if (id === '\0navigation-components') return `export { SectionPages } from ${JSON.stringify(path.resolve(import.meta.dirname, '../../resources/js/Components/TopBar/PageTrail.jsx'))}; export { default as MegaMenu } from ${JSON.stringify(path.resolve(import.meta.dirname, '../../resources/js/Components/TopBar/MegaMenu.jsx'))};`;
                if (id === '\0inertia-link') return "import { createElement } from 'react'; export function Link({ children, prefetch, ...props }) { return createElement('a', props, children); }";
            },
        }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        ({ SectionPages, MegaMenu } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

function render(can, url) {
    return renderToStaticMarkup(createElement(SectionPages, { trail: pageTrail(navigationGroups(can), url), url, badges: {} }));
}

test('a user with subscriber access sees that single sub-navigation link and keeps every main section', () => {
    const can = { viewSubscriptions: true };
    const html = render(can, '/subscriptions');
    assert.match(html, /aria-label="صفحات المشتركون"/);
    assert.deepEqual([...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1]), ['/subscriptions']);
    assert.deepEqual(topBarItems(navigationGroups(can)).map((item) => item.key), ['home', 'daily', 'finance', 'reports', 'infrastructure', 'administration']);
});

test('financial sub-navigation includes only the individually authorized destinations', () => {
    const html = render({ recordPayments: true, viewOwnCashBoxes: true }, '/payments');
    assert.deepEqual([...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1]), ['/payments', '/payments/summary']);
    assert.doesNotMatch(html, /aria-disabled|بلا صلاحية|الصناديق المالية|السجل المالي|أعمار الديون/);
});

test('a section with no permitted pages has no sub-navigation strip', () => {
    assert.equal(render({}, '/subscriptions'), '');
    assert.equal(render({}, '/ledger'), '');
});

test('the desktop section menu filters its pages while an empty section stays identifiable', () => {
    const groups = navigationGroups({ viewSubscriptions: true });
    const menu = (group) => renderToStaticMarkup(createElement(MegaMenu, { group, url: '/subscriptions', badges: {}, width: 420 }));
    const subscribers = menu(groups.find((group) => group.id === 'daily'));
    assert.deepEqual([...subscribers.matchAll(/href="([^"]+)"/g)].map((match) => match[1]), ['/subscriptions']);
    const finance = menu(groups.find((group) => group.id === 'finance'));
    assert.match(finance, /المالية والتحصيل/);
    assert.doesNotMatch(finance, /href=/);
});
