import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildPartSheet, defaultExcelSettings } from '../../resources/js/lib/excelSheet.js';
import { boxColumns, debtColumns, detailExcelPart, statementColumns } from '../../resources/js/lib/reportDetailParts.js';

function sheet(key, columns, rows) {
    const part = detailExcelPart(key, key, columns, rows);
    return buildPartSheet(part, { ...defaultExcelSettings([part]), header: false });
}

test('statement exports preserve signed balances and total only the positive debit and credit columns', () => {
    const model = sheet('statement', statementColumns, [
        { description: 'opening', debit: '0', credit: '0', balance: '100' },
        { description: 'payment', debit: '0', credit: '150', balance: '-50' },
        { description: 'closing', debit: '0', credit: '0', balance: '-50' },
    ]);
    const payment = model.rows.find((row) => row.kind === 'data' && row.cells[2].v === 'payment');
    assert.deepEqual(payment.cells.slice(3).map((cell) => cell.v), [0, 150, -50]);
    const total = model.rows.at(-1);
    assert.equal(total.kind, 'total');
    assert.equal(total.cells[4].v, 150);
    assert.equal(total.cells[5].f, undefined);
    assert.notEqual(total.cells[5].v, -100);
});

test('statement exports keep a pair cancelled within the period out of the debit and credit totals', () => {
    const model = sheet('statement', statementColumns, [
        { description: 'charge', debit: '100.00', credit: '0.00', balance: '100.00' },
        { description: 'payment', debit: '0.00', credit: '15.00', balance: '85.00', netted: true },
        { description: 'reversal', debit: '15.00', credit: '0.00', balance: '100.00', netted: true },
        { description: 'payment', debit: '0.00', credit: '40.00', balance: '60.00' },
    ]);
    const cancelled = model.rows.find((row) => row.kind === 'data' && String(row.cells[2].v).startsWith('reversal'));
    assert.match(cancelled.cells[2].v, /ملغاة مع قيدها العكسي \(15.00\)/);
    assert.deepEqual(cancelled.cells.slice(3).map((cell) => cell.v), [0, 0, 100]);
    const total = model.rows.at(-1);
    assert.deepEqual([total.cells[3].v, total.cells[4].v], [100, 40]);
});

test('box exports keep currencies separate without summing their amounts or adding a shekel format', () => {
    const model = sheet('boxes', boxColumns, [
        { number: 'BOX-1', currency: 'USD', amount: '12.50', count: 1 },
        { number: 'BOX-2', currency: 'ILS', amount: '50', count: 2 },
    ]);
    assert.equal(model.columns.find((column) => column.key === 'amount').type, 'decimal');
    const total = model.rows.at(-1);
    assert.equal(total.cells[6].f, undefined);
    assert.equal(total.cells[7].v, 3);
});

test('debt exports sum the aging buckets and the amounts owed across subscribers', () => {
    const model = sheet('debts', debtColumns, [
        { name: 'A', current: '50', older: '80', balance: '130' },
        { name: 'B', current: '20', older: '0', balance: '20' },
    ]);
    const total = model.rows.at(-1);
    assert.deepEqual([total.cells[3].v, total.cells[6].v, total.cells[7].v], [70, 80, 150]);
});
