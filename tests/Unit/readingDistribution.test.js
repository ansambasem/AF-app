import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';
import { distributionAreas, distributionAreaBreakdown } from '../../resources/js/lib/readingBoard.js';

let DistributionTable;
let DistributionAreas;
let DistributionSummaryModal;
let DistributionActionModal;
let DistributionAreaBreakdown;
async function loadComponent(name) {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, `../../resources/js/Pages/ReadingCycles/${name}.jsx`),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'distribution', resolveId(id) {
            if (id === '@/Components/Modal') return '\0modal';
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
        }, load(id) { if (id === '\0modal') return 'export default function Modal({ children }) { return children; }'; } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        return (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default;
    } finally { await bundle.close(); }
}
before(async () => {
    [DistributionTable, DistributionAreas, DistributionSummaryModal, DistributionActionModal, DistributionAreaBreakdown] = await Promise.all(['DistributionTable', 'DistributionAreas', 'DistributionSummaryModal', 'DistributionActionModal', 'DistributionAreaBreakdown'].map(loadComponent));
});

const box = { id: 1, number: 'B1', name: 'السوق', subAreaId: 7, subAreaName: 'الحي الشرقي', subscribers: 12, read: 3, column: 'reader:5', status: 'approved', recurringUserId: 5, recurringChannel: 'mobile' };
const render = (overrides = {}) => renderToStaticMarkup(createElement(DistributionTable, {
    boxes: [box], readers: [{ id: 5, name: 'أحمد' }], selected: new Set(), canSelect: true, busy: false,
    onToggle() {}, onToggleGroup() {}, ...overrides,
}));

test('area groups contain each meter box and show current and standing readers separately', () => {
    const html = render({ boxes: [{ ...box, column: 'reader:6' }], readers: [{ id: 5, name: 'أحمد' }, { id: 6, name: 'محمد' }] });
    assert.ok(html.indexOf('الحي الشرقي') < html.indexOf('السوق'));
    assert.match(html, /محمد/);
    assert.match(html, /الثابت: أحمد/);
    assert.match(html, /تحديد المنطقة كاملة/);
});

test('paper work is distinct from unassigned work and can be selected for printing', () => {
    const html = render({ boxes: [{ ...box, column: 'paper', recurringChannel: 'paper', recurringUserId: null }, { ...box, id: 2, number: 'B2', column: 'available', status: null, recurringChannel: null, recurringUserId: null }] });
    assert.match(html, /كشف ورقي/);
    assert.match(html, /غير موزع/);
    assert.match(html, /aria-label="تحديد الطبلون B1"/);
    assert.doesNotMatch(render({ canSelect: false }), /type="checkbox"/);
});

test('an unavailable reader is identified instead of leaving an empty assignment', () => {
    assert.match(render({ readers: [] }), /القارئ غير متاح/);
});

test('the meter box name and suffix sit beside its prominent number in parentheses', () => {
    const html = render({ boxes: [{ ...box, name: 'أبو ذر 8', number: '110303' }] });
    assert.match(html, /أبو ذر 8 <bdi[^>]*text-base[^>]*>\(110303\)<\/bdi>/);
});

test('area selection includes matching boxes on other pages instead of only the displayed page', () => {
    const another = { ...box, id: 2, number: 'B2', name: 'المدرسة' };
    const html = render({ boxes: [box], allBoxes: [box, another], selected: new Set([1]) });
    assert.match(html, /2 طبلون ضمن النتائج/);
    assert.match(html, /تحديد المنطقة كاملة/);
    assert.doesNotMatch(html, /إلغاء تحديد المنطقة/);
    assert.doesNotMatch(html, /المدرسة/);
});

test('the unassigned sidebar provides status filters and shows the remaining boxes in mixed areas', () => {
    const boxes = [box, { ...box, id: 2, column: 'available', subscribers: 5 }, { ...box, id: 3, column: 'paper', subAreaId: 8, subAreaName: 'الحي الغربي' }];
    const html = renderToStaticMarkup(createElement(DistributionAreas, {
        areas: distributionAreas(boxes, 'available'), chosen: [], filter: 'available', counts: { all: 3, assigned: 2, available: 1 },
        onChange() {}, onFilterChange() {},
    }));
    assert.match(html, /تصفية المناطق حسب حالة التوزيع/);
    assert.match(html, /aria-pressed="true"[^>]*><span>غير موزع<\/span>/);
    assert.match(html, /الحي الشرقي/);
    assert.doesNotMatch(html, /الحي الغربي/);
    assert.match(html, /1 طبلون مطابق · 5 مشترك/);
    assert.match(html, /إجمالي المنطقة: 1 موزع، 1 غير موزع/);
});

test('expanded summary lists retain full meter box names, suffixes and separate numbers', () => {
    const boxes = [{ ...box, name: 'المخيم 1', number: '110301' }, { ...box, id: 2, name: 'أبو ذر 5', number: '110302' }];
    const html = renderToStaticMarkup(createElement(DistributionAreaBreakdown, { areas: distributionAreaBreakdown(boxes, boxes) }));

    assert.match(html, /المخيم 1 <bdi[^>]*>\(110301\)<\/bdi>/);
    assert.match(html, /أبو ذر 5 <bdi[^>]*>\(110302\)<\/bdi>/);
    assert.match(html, /كاملة/);
});

test('summary distinguishes created and printed sheets and limits approval to authorized users', () => {
    const props = { boards: [{ ...box, status: 'draft' }], readers: [{ id: 5, name: 'أحمد' }], cycle: { branchName: 'المخيم', readingDate: '2026-10-29' }, paperSheets: [{ id: 1, boxes: 3, printCount: 2 }, { id: 2, boxes: 3, printCount: 0 }], abilities: { approve: true, printSheets: true }, errors: {} };
    const html = renderToStaticMarkup(createElement(DistributionSummaryModal, props));

    assert.match(html, /2 كشف منشأ: 1 مطبوع، 1 لم يُطبع/);
    assert.match(html, /اعتماد كل الجاهز \(1\)/);
    assert.doesNotMatch(renderToStaticMarkup(createElement(DistributionSummaryModal, { ...props, abilities: {} })), /اعتماد كل الجاهز/);
});

test('action dialog preserves hidden selection warning and print-only permission boundaries', () => {
    const props = { boards: [box], selectedBoxes: [box], readers: [], abilities: { printSheets: true }, errors: {}, target: '', hiddenSelected: 1 };
    const html = renderToStaticMarkup(createElement(DistributionActionModal, props));

    assert.match(html, /1 طبلون خارج النتائج الظاهرة/);
    assert.match(html, /متابعة التحديد/);
    assert.match(html, /إنشاء الكشف يتطلب/);
    assert.doesNotMatch(html, /حفظ التوزيع|distribution-mode|distribution-reader/);
});
