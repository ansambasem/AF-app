import { before, beforeEach, afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';
import { defaultLayout } from '../../resources/js/lib/printLayout.js';
import { currentPrintSettings, printScopeUrl } from '../../resources/js/lib/print.js';

let Button, Paper, Panel, readingPrintUrl, state;
let oldWindow;
before(async () => {
    const bundle = await rolldown({
        input: 'print-customization-tests', platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'print-test-boundaries', resolveId(id) {
            if (id === 'exceljs') return { id: import.meta.resolve(id), external: true };
            if (id === 'print-customization-tests') return '\0entry';
            if (id === '@inertiajs/react') return '\0inertia';
            if (id === 'react/jsx-runtime') return '\0jsx';
            if (id.startsWith('file:')) return { id, external: true };
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
        }, load(id) {
            if (id === '\0entry') return "export {default as Button,readingPrintUrl} from '@/Components/PrintButton'; export {default as Paper} from '@/Components/Print/PrintPaper'; export {default as Panel} from '@/Components/Print/PrintSettingsPanel'; export {state} from '@inertiajs/react';";
            if (id === '\0inertia') return `export const state={calls:[],fail:false,click:null}; export function usePage(){return {props:{can:{printAuditReports:true}}}}; export function useHttp(){let body;return {processing:false,transform(fn){body=fn},async post(url){state.calls.push([url,body?.()]);if(state.fail)throw Error('offline');return {token:'11111111-1111-4111-a111-111111111111'}}}};`;
            if (id === '\0jsx') return `import * as runtime from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))}; import {state} from '@inertiajs/react'; export const Fragment=runtime.Fragment;function capture(type,props){if(type==='button'&&Array.isArray(props.children)&&props.children[0]==='طباعة المحدد (')state.click=props.onClick}export function jsx(type,props,key){capture(type,props);return runtime.jsx(type,props,key)}export function jsxs(type,props,key){capture(type,props);return runtime.jsxs(type,props,key)}`;
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        ({ Button, Paper, Panel, readingPrintUrl, state } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});
beforeEach(() => {
    oldWindow = globalThis.window;
    state.calls = []; state.fail = false; state.click = null; state.closed = false; state.target = null;
    globalThis.window = { location: { href: 'https://power.test/meter-readings?search=abc', origin: 'https://power.test', search: '?search=abc' }, open() { return { opener: null, close() { state.closed = true; }, location: { replace(url) { state.target = url; } } }; } };
});
afterEach(() => { globalThis.window = oldWindow; });

test('selected printing sends IDs in an authorized request body and opens only one preview', async () => {
    renderToStaticMarkup(createElement(Button, { title: 'قراءات', selectionPage: '/meter-readings', selectedIds: [7, 8] }));
    assert.equal(typeof state.click, 'function');
    const first = state.click();
    await state.click();
    await first;
    assert.deepEqual(state.calls, [['/print-selections', { page: '/meter-readings', ids: [7, 8] }]]);
    const url = new URL(state.target);
    assert.equal(url.searchParams.get('print_scope'), 'selected');
    assert.equal(url.searchParams.get('search'), 'abc');
    assert.equal(url.searchParams.has('ids'), false);
});

test('an unsuccessful selection request closes its empty popup', async () => {
    state.fail = true;
    renderToStaticMarkup(createElement(Button, { title: 'قراءات', selectionPage: '/meter-readings', selectedIds: [7] }));
    await state.click();
    assert.equal(state.closed, true);
    assert.equal(state.target, null);
});

test('print filters and the original page survive a distribution preview URL', () => {
    const url = new URL(readingPrintUrl('التوزيع', '/reading-cycles/print', { cycle: 3, area_ids: ['none', '4'], assignment: 'reader:8', print_page: 2, per_page: 50 }));
    assert.deepEqual(url.searchParams.getAll('area_ids[]'), ['none', '4']);
    assert.equal(url.searchParams.get('print_page'), '2');
    assert.equal(url.searchParams.get('assignment'), 'reader:8');
    window.location.href = `${url.href}&print_selection=token&print_scope=selected`;
    window.location.search = new URL(window.location.href).search;
    assert.equal(currentPrintSettings().scope, 'selected');
    const page = new URL(printScopeUrl(false));
    assert.equal(page.searchParams.get('print_scope'), 'page');
    assert.equal(page.searchParams.get('print_selection'), 'token');
    assert.equal(page.searchParams.get('page'), '2');
});

test('a handwritten reading column stays empty while names, phones and labels are escaped', () => {
    const layout = defaultLayout({ columns: [{ key: 'name', label: 'المشترك' }, { key: 'current', label: 'القراءة الحالية' }] });
    layout.columns[0].secondaryKey = 'phone'; layout.columns[0].width = 45; layout.columns[1].blank = true;
    const html = renderToStaticMarkup(createElement(Paper, { layout, table: { rows: [{ type: 'row', cells: { name: '<script>bad</script>', phone: '0599000111', current: '999999' } }] }, summary: '', context: {}, zoom: 1 }));
    assert.ok(html.includes('0599000111'));
    assert.ok(html.includes('&lt;script&gt;bad&lt;/script&gt;'));
    assert.equal(html.includes('<script>'), false);
    assert.equal(html.includes('999999'), false);
    assert.ok(html.includes('45mm'));
});

test('column settings expose print-only fields, widths, handwriting and nested grouping', () => {
    const layout = defaultLayout({ columns: [{ key: 'name', label: 'الاسم' }, { key: 'field:phone', label: 'الهاتف', extra: true }] });
    layout.group.key = 'name';
    const html = renderToStaticMarkup(createElement(Panel, { layout, onChange() {}, onReset() {}, scope: { allRows: true, total: 1, onChange() {} }, templates: { list: [], active: null, supported: true, canManage: false, errors: {}, actions: {} } }));
    for (const label of ['البحث في أعمدة الطباعة', 'العرض (مم)', 'خانة فارغة للكتابة', 'السطر الإضافي لعمود', 'إضافة عمود فارغ', 'إضافة مستوى تجميع', 'الهاتف']) assert.ok(html.includes(label), label);
});

test('letterhead renders independent text directions, logo placement and typography with escaped text', () => {
    const layout = defaultLayout({ columns: [{ key: 'name', label: 'المشترك' }], company: 'شركة أبو زايد', title: 'ورقة مقاصة' });
    Object.assign(layout.header, { layout: 'letterhead', companyEnglish: 'Fares <script>bad</script> Sons', companyDetails: 'إدارة الشركة', logoPosition: 'center', logoWidth: 40, titleSize: 24, titleFont: 'messiri', ruleStyle: 'double', ruleColor: '#A51D26' });
    layout.fontFamily = 'alexandria';
    const render = () => renderToStaticMarkup(createElement(Paper, { layout, table: { rows: [] }, summary: '', context: {}, zoom: 1 }));
    const html = render();

    assert.ok(html.includes('data-print-letterhead'));
    assert.ok(html.includes('minmax(0, 1fr) auto minmax(0, 1fr)'));
    assert.ok(html.includes('width:40mm'));
    assert.ok(html.includes('font-size:24px'));
    assert.ok(html.includes('Alexandria, sans-serif'));
    assert.ok(html.includes('dir="ltr"'));
    assert.ok(html.includes('dir="rtl"'));
    assert.ok(html.includes('border-bottom:2px solid #A51D26'));
    assert.ok(html.includes('&lt;script&gt;bad&lt;/script&gt;'));
    assert.equal(html.includes('<script>'), false);
    layout.header.logo = false;
    assert.equal(render().includes('<img'), false);
    assert.ok(render().includes('minmax(0, 1fr) auto minmax(0, 1fr)'));
});

test('print settings offer named starter designs and independent letterhead controls', () => {
    const layout = defaultLayout({ columns: [{ key: 'name', label: 'المشترك' }] });
    layout.header.layout = 'letterhead';
    const html = renderToStaticMarkup(createElement(Panel, { layout, onChange() {}, onReset() {}, scope: { allRows: true, total: 1, onChange() {} }, templates: { list: [], active: null, supported: true, canManage: true, errors: {}, actions: {} } }));
    for (const label of ['ورقة مقاصة', 'كشف تحصيل', 'اسم الشركة والتفاصيل بالإنجليزية', 'موضع الشعار', 'حجم النص العربي', 'محاذاة النص الإنجليزي', 'خط عنوان التقرير', 'عرض الشعار', 'خط محتوى التقرير']) assert.ok(html.includes(label), label);
});
