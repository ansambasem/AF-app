import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildPartSheet, buildWorkbookModel, defaultExcelSettings } from '../../resources/js/lib/excelSheet.js';
import { reportExcelParts } from '../../resources/js/lib/reportExcelParts.js';

const flow = {
    opening: '1000.00',
    charges: [{ type: 'reading', label: 'قراءة', count: 3, total: '300.00' }],
    chargesTotal: '300.00',
    credits: [{ type: 'payment', label: 'دفعة', count: 2, total: '250.00' }],
    creditsTotal: '250.00',
    corrections: { count: 1, total: '-10.00' },
    closing: '1040.00',
    change: '40.00',
};
const collections = {
    count: 2,
    total: '250.00',
    cash: '200.00',
    nonCash: '50.00',
    accounts: [{ key: 'cash', label: 'الصندوق النقدي', count: 1, total: '200.00' }, { key: 'بنك فلسطين', label: 'بنك فلسطين', count: 1, total: '50.00' }],
    currencies: [{ currency: 'ILS', label: 'شيكل', count: 2, total: '250.00', amount: '250.00' }],
    collectors: [{ name: 'سامي', count: 2, cash: '200.00', total: '250.00' }],
};
const days = [
    { day: '2026-10-24', charges: '300.00', payments: '0.00', discounts: '0.00', corrections: '0.00', lines: 3, balance: '1300.00' },
    { day: '2026-10-25', charges: '0.00', payments: '250.00', discounts: '0.00', corrections: '-10.00', lines: 3, balance: '1040.00' },
];
const lines = [
    { id: 1, day: '2026-10-24', time: '09:10', branchName: 'البريج', voucherNumber: '7', subscriptionName: 'أحمد', accountNumber: '101', typeLabel: 'قراءة', account: null, isCredit: false, isCancelled: false, amount: '300.00', recordedBy: 'سامي' },
    { id: 2, day: '2026-10-25', time: '10:00', branchName: 'البريج', voucherNumber: '8', subscriptionName: 'أحمد', accountNumber: '101', typeLabel: 'دفعة', account: 'الصندوق النقدي', isCredit: true, isCancelled: true, amount: '200.00', recordedBy: 'سامي' },
];

test('the report becomes its parts in the page order, with the lines once fetched', () => {
    const parts = reportExcelParts({ flow, collections, readings: { entered: 3, consumption: 120, approved: 3, billed: '300.00', pending: 0 }, days, lines, branchSummary: [] });

    assert.deepEqual(parts.map((part) => part.key), ['summary', 'flow', 'days', 'accounts', 'collectors', 'currencies', 'readings', 'lines']);
    assert.deepEqual(reportExcelParts({ flow: null, collections: null, readings: null }), []);
});

test('the summary uses the page words and the period figures', () => {
    const [summary] = reportExcelParts({ flow, collections });

    assert.deepEqual(summary.rows.map((row) => [row.label, row.value]), [
        ['الرصيد الافتتاحي', '1000.00'],
        ['التحميلات', '300.00'],
        ['إجمالي المحصّل', '250.00'],
        ['نقدي', '200.00'],
        ['تحويلات وشيكات', '50.00'],
        ['الرصيد الختامي', '1040.00'],
        ['التغيّر خلال الفترة', '40.00'],
    ]);
    assert.equal(summary.rows[5].note, 'زاد');
});

test('the lines split into عليه and له, cancelled lines say so, and the totals add up', () => {
    const part = reportExcelParts({ flow, collections, lines }).find((candidate) => candidate.key === 'lines');
    const settings = defaultExcelSettings([part]);
    settings.header = false;
    const sheet = buildPartSheet(part, settings);

    assert.deepEqual(sheet.columns.map((column) => column.key), ['day', 'time', 'voucherNumber', 'subscriptionName', 'accountNumber', 'typeLabel', 'account', 'debit', 'credit', 'recordedBy', 'status']);
    assert.deepEqual(sheet.rows[2].cells.slice(7).map((cell) => cell.v), ['', 200, 'سامي', 'ملغى']);
    assert.deepEqual(sheet.rows.at(-1).cells.slice(7, 9).map((cell) => [cell.f, cell.v]), [['SUBTOTAL(9,H2:H3)', 300], ['SUBTOTAL(9,I2:I3)', 200]]);
});

test('the branch column and grouping by branch appear only when every branch is reported', () => {
    const one = reportExcelParts({ lines }).at(-1);
    const all = reportExcelParts({ lines, showBranch: true }).at(-1);

    assert.equal(one.columns.find((column) => column.key === 'branchName').default, false);
    assert.equal(all.columns.find((column) => column.key === 'branchName').default, true);
    assert.equal(one.groupBys.some((group) => group.key === 'branchName'), false);
    assert.equal(all.groupBys.some((group) => group.key === 'branchName'), true);
});

test('branches side by side total across branches; a one-day report leaves the days sheet out', () => {
    const branchSummary = [
        { id: 1, name: 'البريج', flow, collections },
        { id: 2, name: 'النصيرات', flow: { ...flow, closing: '60.00' }, collections: { ...collections, total: '5.50' } },
    ];
    const parts = reportExcelParts({ flow, collections, days: [days[0]], branchSummary });
    const branches = parts.find((part) => part.key === 'branches');
    const settings = defaultExcelSettings(parts);
    settings.header = false;
    settings.criteria = false;
    const sheet = buildPartSheet(branches, settings);

    assert.deepEqual(sheet.rows.at(-1).cells.slice(1, 3).map((cell) => cell.v), [1100, 255.5]);
    assert.equal(buildWorkbookModel(parts, settings).some((built) => built.key === 'days'), false);
});

test('foreign currency amounts carry no shekel sign', () => {
    const currencies = reportExcelParts({ collections: { ...collections, currencies: [{ currency: 'USD', label: 'دولار', count: 1, total: '37.00', amount: '10.00' }] } }).find((part) => part.key === 'currencies');

    assert.equal(currencies.default, true);
    assert.equal(currencies.columns.find((column) => column.key === 'amount').type, 'decimal');
});

test('the Excel summary carries the same comparison figures and period as the screen', () => {
    const comparison = { from: '2026-09-01', to: '2026-09-02', total: '200.00', difference: '50.00', percentage: 25 };

    const [summary] = reportExcelParts({ flow, collections, comparison });

    assert.deepEqual(summary.rows.slice(-2).map((row) => [row.value, row.note]), [['200.00', '01/09/2026 – 02/09/2026'], ['50.00', '25%']]);
});
