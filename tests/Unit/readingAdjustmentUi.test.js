import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let Dialog;
let History;
let Pricing;

before(async () => {
    const components = [];
    for (const file of ['Components/LastReadingConfirmDialog.jsx', 'Pages/Subscriptions/ReadingHistoryModal.jsx', 'Pages/Subscriptions/ReadingPricingField.jsx']) {
        const bundle = await rolldown({
            input: path.resolve(import.meta.dirname, '../../resources/js', file),
            platform: 'node', transform: { jsx: 'react-jsx' },
            external: ['axios'],
            resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
            plugins: [{
                name: 'render-modal-content-without-a-browser-portal',
                resolveId(id) {
                    if (/Components[/\\]Modal(?:\.jsx)?$/.test(id)) return '\0modal-content';
                    if (id.endsWith('.css')) return '\0empty-css';
                    if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
                },
                load(id) {
                    if (id === '\0modal-content') return 'export default function Modal({show, children}) { return show ? children : null; }';
                    if (id === '\0empty-css') return '';
                },
            }],
        });
        try {
            const { output } = await bundle.generate({ format: 'esm' });
            components.push((await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default);
        } finally {
            await bundle.close();
        }
    }
    [Dialog, History, Pricing] = components;
});

const week = { isApproved: true, unusual: false, before: { consumption: 10, amountDue: 5 }, after: { consumption: 5, amountDue: 2.5 } };
const editor = { phase: 'review', adjustmentType: 'correction', canContinue: true, value: '205', savedValue: '210', floor: 200, saving: false, preview: { reset: false, before: 210, after: 205, weeks: [week], clearsCurrentReading: true, removedCurrentAmount: 10 }, reason: '', cancel() {}, confirm() {}, setReason() {}, chooseType() {}, back() {}, beginReading() {}, review() {}, inputProps: { value: '205', onChange() {} } };

test('a correction confirmation explains billing and has no current-reading input', () => {
    const html = renderToStaticMarkup(createElement(Dialog, { editor, subscriptionName: 'أحمد' }));
    assert.match(html, /تصحيح خطأ إدخال/);
    assert.match(html, /أدخل القراءة الحالية لاحقًا من خانتها في الجدول/);
    assert.doesNotMatch(html, /<input[^>]*type="number"/);
    assert.doesNotMatch(html, /<textarea/);
    assert.doesNotMatch(html.match(/<button[^>]*>حفظ التصحيح<\/button>/)[0], /disabled=""/);
});

test('the purpose is chosen before a reading input appears', () => {
    const html = renderToStaticMarkup(createElement(Dialog, { editor: { ...editor, phase: 'reason', adjustmentType: '', canContinue: false }, subscriptionName: 'أحمد' }));
    assert.match(html, /لماذا تريد تعديل القراءة؟/);
    assert.match(html, /تصحيح خطأ إدخال/);
    assert.match(html, /عداد جديد \/ مستبدل/);
    assert.doesNotMatch(html, /<input[^>]*type="number"/);
    assert.match(html.match(/<button[^>]*>متابعة<\/button>/)[0], /disabled=""/);
});

test('the meter change reason is required before proceeding to enter a value', () => {
    const resetEditor = { ...editor, phase: 'reason', adjustmentType: 'meter_change', canContinue: false, preview: null };
    const html = renderToStaticMarkup(createElement(Dialog, { editor: resetEditor, subscriptionName: 'أحمد' }));
    assert.match(html, /<textarea[^>]*required=""/);
    assert.doesNotMatch(html, /<input[^>]*type="number"/);
    assert.match(html.match(/<button[^>]*>متابعة<\/button>/)[0], /disabled=""/);
    const ready = renderToStaticMarkup(createElement(Dialog, { editor: { ...resetEditor, reason: 'عداد جديد', canContinue: true }, subscriptionName: 'أحمد' }));
    assert.doesNotMatch(ready.match(/<button[^>]*>متابعة<\/button>/)[0], /disabled=""/);
});

test('review identifies a meter change and shows its reason without a current reading input', () => {
    const html = renderToStaticMarkup(createElement(Dialog, { editor: { ...editor, adjustmentType: 'meter_change', reason: 'تركيب عداد بديل', preview: { ...editor.preview, reset: true, after: 500 } }, subscriptionName: 'أحمد' }));
    assert.match(html, /الفاتورة السابقة وحركاتها محفوظة كما هي/);
    assert.match(html, /تركيب عداد بديل/);
    assert.match(html, /حفظ نقطة البداية/);
    assert.doesNotMatch(html, /<input[^>]*type="number"/);
});

test('the current field is blank on reopening history until a reading is manually entered', () => {
    const html = renderToStaticMarkup(createElement(History, {
        subscription: { id: 1, display_name: 'أحمد', canRecordReading: true, lastReading: 205, kilowattPrice: 0.5, weeklyMinimumPayment: 0, meterReadings: [{
            id: 3, weekStart: '2026-09-18', weekEnd: '2026-09-24', previous_reading: 205, current_reading: 230,
            requiresReentry: true, consumption: 0, amountDue: 0, discountAmount: 0, unitPrice: 0.5, minimumPayment: 0,
            status: 'pending', canUpdate: true,
        }] },
        readingWeekOptions: [{ value: '2026-09-18', end: '2026-09-24' }], onClose() {},
    }));
    assert.match(html, /<input[^>]*aria-label="القراءة الجديدة"[^>]*value=""/);
    assert.match(html, /بانتظار إدخال الحالية/);
    assert.doesNotMatch(html, /value="230"/);
});

test('each pricing pencil follows its own permission', () => {
    const reading = { id: 1, unitPrice: 0.5, minimumPayment: 12, canUpdateUnitPrice: true, canUpdateMinimumPayment: false };
    assert.match(renderToStaticMarkup(createElement(Pricing, { reading, field: 'unit_price' })), /aria-label="تعديل سعر الكيلو"/);
    assert.doesNotMatch(renderToStaticMarkup(createElement(Pricing, { reading, field: 'minimum_payment' })), /<button/);
});

test('history exposes the initial baseline editor with the same permission even when new entry is closed', () => {
    const subscription = {
        id: 1, display_name: 'أحمد', lastReading: 0, meterReadings: [], kilowattPrice: 0.5, weeklyMinimumPayment: 0,
        initialReading: { isInitial: true, value: 0, canEdit: true, weekStart: '2026-09-18', previewUrl: '/subscriptions/1/reading-baseline/preview', saveUrl: '/subscriptions/1/reading-baseline' },
    };
    const readingWeekOptions = [{ value: '2026-09-18', end: '2026-09-24' }];
    const render = (overrides) => renderToStaticMarkup(createElement(History, { subscription: { ...subscription, ...overrides }, readingWeekOptions, onClose() {} }));
    assert.match(render({ canRecordReading: true }), /aria-label="تعديل القراءة السابقة"/);
    assert.match(render({ canRecordReading: false }), /aria-label="تعديل القراءة السابقة"/);
    assert.doesNotMatch(render({ canRecordReading: false }), /aria-label="القراءة الجديدة"/);
    assert.doesNotMatch(render({ initialReading: { ...subscription.initialReading, canEdit: false } }), /aria-label="تعديل القراءة السابقة"/);
    const readOnlyCurrent = render({ canRecordReading: false, meterReadings: [{ id: 7, weekStart: '2026-09-18', weekEnd: '2026-09-24', previous_reading: 0, current_reading: 20, consumption: 20, amountDue: 10, status: 'approved', statusLabel: 'معتمدة', canUpdate: false }] });
    assert.match(readOnlyCurrent, /aria-label="تعديل القراءة السابقة"/);
    assert.doesNotMatch(readOnlyCurrent, /aria-label="القراءة الجديدة"/);
});
