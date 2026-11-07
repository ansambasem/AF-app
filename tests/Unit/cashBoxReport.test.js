import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reportDetails, reportRowTotals } from '../../resources/js/lib/cashBoxReport.js';
import { arrangeRows, defaultLayout, rowColumnTotal, fitLayout } from '../../resources/js/lib/printLayout.js';

function row(amount, bankAmount, currency, active = true) {
    return {
        type: 'row', currency,
        cells: { 'مبلغ الصندوق': amount, 'المشترك': 'مشترك', 'الفرق': bankAmount == null ? '—' : String(Number(amount) - Number(bankAmount)) },
        totals: reportRowTotals({ active, amount, bankAmount, difference: bankAmount == null ? null : String(Number(amount) - Number(bankAmount)) }),
    };
}

test('print totals exclude returns and keep each currency separate after sorting or grouping', () => {
    const rows = [row('100.10', '80', 'شيكل'), row('25', '25', 'شيكل', false), row('20', '25', 'دولار'), row('0.20', null, 'شيكل')];
    assert.equal(rowColumnTotal(rows, 'مبلغ الصندوق'), '100.30 شيكل · 20 دولار');
    assert.equal(rowColumnTotal(rows, 'وصل البنك'), '80 شيكل · 25 دولار');
    assert.equal(rowColumnTotal(rows, 'الفرق'), '20.10 شيكل · -5 دولار');
    assert.equal(rowColumnTotal(rows, 'المشترك'), null);
    const layout = defaultLayout({ columns: [{ key: 'الفرق', label: 'الفرق' }] });
    layout.group.key = 'المشترك';
    const groups = arrangeRows(rows, layout);
    assert.equal(rowColumnTotal(groups[0].rows, 'وصل البنك'), '80 شيكل · 25 دولار');
});

test('unknown bank amounts stay unknown while an audited missing payment totals to zero', () => {
    assert.equal(rowColumnTotal([row('10', null, 'شيكل')], 'وصل البنك'), null);
    assert.equal(rowColumnTotal([row('10', '0', 'شيكل')], 'وصل البنك'), '0 شيكل');
    assert.equal(rowColumnTotal([row('10', '0', 'شيكل', false)], 'وصل البنك'), null);
});

test('existing printable tables keep their previous totals', () => {
    assert.equal(rowColumnTotal([{ cells: { amount: '10 شيكل' } }, { cells: { amount: '20 شيكل' } }], 'amount'), '30 شيكل');
});

test('template detail visibility is saved without carrying one box’s values into another report', () => {
    const report = { number: '41', collector: 'المحصّل الأول', type: 'bank_transfer', branch: 'الفرع الأول', rows: [], counts: { all: 0 }, filter: 'active', auditStatus: 'اكتمل التدقيق' };
    const fresh = defaultLayout({ columns: [{ key: 'تاريخ الحركة', label: 'تاريخ الحركة' }] });
    const layout = fitLayout({ header: { reportFields: ['number', 'collector'] }, columns: [] }, fresh);
    const fields = reportDetails({ ...report, number: '42', collector: 'المحصّل الثاني' }).filter((field) => layout.header.reportFields.includes(field.key));
    assert.deepEqual(fields.map((field) => field.value), ['42', 'المحصّل الثاني']);
    assert.equal(JSON.stringify(fields).includes('المحصّل الأول'), false);
});
