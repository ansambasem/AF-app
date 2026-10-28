import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let SheetRow;
let Dialog;
let History;
before(async () => {
    const bundle = await rolldown({
        input: 'reading-approval-components', platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'reading-components', resolveId(id) {
            if (id === 'exceljs') return { id: import.meta.resolve(id), external: true };
            if (id === 'reading-approval-components') return '\0entry';
            if (/^react(?:-dom)?(?:\/|$)/.test(id) || id === '@inertiajs/react') return { id: import.meta.resolve(id), external: true };
            if (id.endsWith('.css')) return '\0css';
            if (/Components[/\\]Modal(?:\.jsx)?$/.test(id)) return '\0modal';
        }, load(id) {
            if (id === '\0entry') return "export {SheetRow} from '@/Pages/MeterReadings/Index'; export {default as Dialog} from '@/Pages/MeterReadings/ReadingApprovalDialog'; export {default as History} from '@/Pages/Subscriptions/ReadingHistoryModal';";
            if (id === '\0css') return '';
            if (id === '\0modal') return 'export default function Modal({show, children}) { return show ? children : null; }';
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        ({ SheetRow, Dialog, History } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

const row = { id: 1, fullName: 'أحمد', previousReading: 0, canApprove: true, canEdit: true, unitPrice: '0.5', minimumPayment: '0', reading: { id: 7, currentReading: 23, consumption: 23, amountDue: '11.50', readingFee: '11.50', status: 'pending' } };
const renderRow = (overrides = {}, props = {}) => renderToStaticMarkup(createElement(SheetRow, { row: { ...row, ...overrides }, approvable: true, week: '2026-10-16', selected: false, onApprove() {}, onToggleSelected() {}, ...props }));

test('an initial baseline including zero offers the previous-reading pencil only when permitted', () => {
    const initialReading = { isInitial: true, weekStart: '2026-10-16', previousReading: 0, canEdit: true, previewUrl: '/subscriptions/1/reading-baseline/preview', saveUrl: '/subscriptions/1/reading-baseline' };
    assert.match(renderRow({ reading: null, canApprove: false, initialReading }), /aria-label="تعديل القراءة السابقة لـ أحمد"/);
    assert.doesNotMatch(renderRow({ reading: null, canApprove: false, initialReading: { ...initialReading, canEdit: false } }), /aria-label="تعديل القراءة السابقة لـ أحمد"/);
});

test('the saved pending status is an approval button without removing its selection checkbox', () => {
    const html = renderRow();
    assert.match(html, /<button[^>]*aria-label="اعتماد قراءة أحمد"/);
    assert.match(html, /aria-label="تحديد قراءة أحمد للاعتماد"/);
    assert.match(html, /re-pill pending re-pill-action/);
});

test('status stays informational without permission or when the current reading needs reentry', () => {
    for (const html of [renderRow({ canApprove: false }), renderRow({}, { approvable: false }), renderRow({ reading: { ...row.reading, requiresReentry: true } }), renderRow({ reading: { ...row.reading, status: 'approved', statusLabel: 'معتمدة' } })]) {
        assert.doesNotMatch(html, /aria-label="اعتماد قراءة أحمد"/);
    }
    assert.match(renderRow({}, { approving: true }), /<button[^>]*disabled=""[^>]*aria-label="اعتماد قراءة أحمد"/);
});

test('individual confirmation identifies one subscriber and shows amounts and unusual-reading review', () => {
    const html = renderToStaticMarkup(createElement(Dialog, { row: { ...row, reading: { ...row.reading, unusual: { usual: 2 } } }, onConfirm() {}, onCancel() {} }));
    assert.match(html, /يشمل هذا التأكيد هذه القراءة فقط/);
    assert.match(html, /القراءة الجديدة/);
    assert.match(html, /23/);
    assert.match(html, /أعلى بكثير من الاستهلاك المعتاد/);
    assert.match(html, /اعتماد القراءة/);
});

const historyReading = { id: 7, weekStart: '2026-10-09', weekEnd: '2026-10-15', previous_reading: 200, current_reading: 223, consumption: 23, amountDue: 11.5, discountAmount: 0, unitPrice: 0.5, minimumPayment: 0, status: 'pending', canApprove: true };
const renderHistory = (reading = {}) => renderToStaticMarkup(createElement(History, {
    subscription: { id: 1, display_name: 'أحمد', lastReading: 223, kilowattPrice: 0.5, weeklyMinimumPayment: 0, canRecordReading: true, meterReadings: [{ ...historyReading, ...reading }] },
    readingWeekOptions: [{ value: '2026-10-09', end: '2026-10-15' }], onClose() {},
}));

test('subscriber history offers status approval on both editable and historical reading rows', () => {
    for (const canUpdate of [true, false]) {
        const html = renderHistory({ canUpdate });
        assert.match(html, /<button[^>]*aria-label="اعتماد قراءة أحمد"/);
        assert.match(html, /بانتظار الاعتماد/);
    }
});

test('subscriber history has no approval action for unauthorized, approved, or reentry readings', () => {
    for (const canUpdate of [true, false]) {
        for (const reading of [{ canApprove: false }, { status: 'approved', statusLabel: 'معتمدة' }, { requiresReentry: true }]) {
            assert.doesNotMatch(renderHistory({ ...reading, canUpdate }), /aria-label="اعتماد قراءة أحمد"/);
        }
    }
});
