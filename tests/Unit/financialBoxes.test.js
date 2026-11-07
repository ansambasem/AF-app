import { test } from 'node:test';
import assert from 'node:assert/strict';
import { auditApprovalSummary, boxBankOptions, filterBoxEntries, boxEntryMatches, financialBoxMatches, boxStatus, entryStatus, financialBoxTotals, financialBoxesTotals, filterFinancialBoxes, sortFinancialBoxes } from '../../resources/js/lib/financialBoxes.js';

test('bank options count each receiving bank or wallet across all entries, including legacy and unspecified banks', () => {
    const entries = [
        { ledger: { bankName: 'بنك فلسطين' }, transaction: { bank_name: 'اسم قديم', sender_bank_name: 'جوال باي' } },
        { transaction: { bank_name: ' بنك فلسطين ', status: 'cancelled' } },
        { transaction: { bank_name: 'جوال باي' } },
        { ledger: { bankName: 'محفظة بالباي' } },
        { transaction: { bank_name: ' ' } },
    ];
    assert.deepEqual(new Map(boxBankOptions(entries).map(({ value, count }) => [value, count])), new Map([
        ['بنك فلسطين', 2], ['جوال باي', 1], ['محفظة بالباي', 1], ['بنك غير محدد', 1],
    ]));
    assert.deepEqual(boxBankOptions([]), []);
    assert.equal(filterBoxEntries(entries, { bank: 'بنك غير محدد' }).length, 1);
});

test('bank selection uses exact receiving banks and combines with status and search without changing entries', () => {
    const entries = [
        { id: 1, transaction: { bank_name: 'بنك فلسطين', sender_bank_name: 'جوال باي', reference_number: 'BANK-123', status: 'active' } },
        { id: 2, transaction: { bank_name: 'بنك فلسطين', reference_number: 'BANK-123', status: 'cancelled' } },
        { id: 3, status: 'missing', ledger: { bankName: 'بنك فلسطين' }, transaction: { reference_number: 'BANK-456', status: 'active' } },
        { id: 4, transaction: { bank_name: 'جوال باي', status: 'active' } },
        { id: 5, transaction: { bank_name: 'بنك فلسطين الإضافي', status: 'active' } },
    ];
    const ids = (filters) => filterBoxEntries(entries, filters).map((entry) => entry.id);
    assert.deepEqual(ids({ bank: 'بنك فلسطين' }), [1, 2, 3]);
    assert.deepEqual(ids({ bank: 'بنك فلسطين', state: 'active', search: 'bank-١٢٣' }), [1]);
    assert.deepEqual(ids({ bank: 'بنك فلسطين', state: 'cancelled' }), [2]);
    assert.deepEqual(ids({ bank: 'بنك فلسطين', state: 'rejected' }), [3]);
    assert.deepEqual(ids({ bank: 'جوال باي' }), [4]);
    assert.deepEqual(ids({ bank: 'غير موجود' }), []);
    assert.deepEqual(ids({ bank: '' }), [1, 2, 3, 4, 5]);
    assert.deepEqual(entries.map((entry) => entry.id), [1, 2, 3, 4, 5]);
});

test('display totals exclude cancelled payments and keep foreign currencies separate', () => {
    const boxes = [{ payments: [
        { amount: '150.25', currency: 'ILS', transaction: { status: 'active' } },
        { amount: '90', currency: 'ILS', transaction: { status: 'cancelled' } },
        { amount: '12', currency: 'USD' },
    ] }, { totals: { ILS: '25', JOD: '3' } }];

    assert.deepEqual(financialBoxTotals(boxes[0]), { ILS: 150.25, USD: 12, JOD: 0 });
    assert.deepEqual(financialBoxesTotals(boxes), { ILS: 175.25, USD: 12, JOD: 3 });
    assert.deepEqual(financialBoxesTotals([]), { ILS: 0, USD: 0, JOD: 0 });
});

test('status, employee and trimmed search filters work together', () => {
    const boxes = [
        { number: 12, status: 'pending', user: { id: 3, name: 'أحمد سالم' } },
        { number: 13, status: 'open', user: { id: 3, name: 'أحمد سالم' } },
        { number: 14, status: 'pending', user: { id: 4, name: 'أحمد علي' } },
    ];

    assert.deepEqual(filterFinancialBoxes(boxes, { status: 'open', person: '3', search: ' أحمد ' }).map((box) => box.number), [12, 13]);
    assert.deepEqual(filterFinancialBoxes(boxes, { search: '13' }).map((box) => box.number), [13]);
    assert.deepEqual(filterFinancialBoxes(boxes, { person: '9' }), []);
});

test('sorting groups open boxes before closed boxes and supports numeric amounts, numbers and dates', () => {
    const boxes = [
        { number: '10', status: 'received', totals: { ILS: 50 }, opened_at: '2026-10-01', closed_at: '2026-10-05' },
        { number: '2', status: 'pending', totals: { ILS: 100 }, opened_at: '2026-10-02', closed_at: '2026-10-03' },
        { number: '3', status: 'open', totals: { ILS: 20 }, opened_at: '2026-10-04' },
    ];

    assert.deepEqual(sortFinancialBoxes(boxes).map((box) => box.number), ['2', '3', '10']);
    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'number', direction: 'asc' }).map((box) => box.number), ['2', '3', '10']);
    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'total', direction: 'desc' }).map((box) => box.number), ['2', '10', '3']);
    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'dates', direction: 'asc' }).map((box) => box.number), ['2', '3', '10']);
    assert.deepEqual(boxes.map((box) => box.number), ['10', '2', '3']);
});

test('boxes show only open or closed, including legacy waiting boxes and boxes sent to audit', () => {
    assert.deepEqual(boxStatus({ status: 'open' }), ['مفتوح', 'blue']);
    assert.deepEqual(boxStatus({ status: 'pending' }), ['مفتوح', 'blue']);
    assert.deepEqual(boxStatus({ status: 'received' }), ['مُغلق', 'green']);
    assert.deepEqual(boxStatus({ status: 'received', sent_at: '2026-10-18' }), ['مُغلق', 'green']);
    assert.deepEqual(sortFinancialBoxes([{ number: '5', status: 'pending' }, { number: '2', status: 'open' }]).map((box) => box.number), ['2', '5']);
});

test('audit ordering follows approval dates in both directions and leaves boxes awaiting approval last', () => {
    const boxes = [
        { number: '1', status: 'received', closed_at: '2026-10-01', audit: { audited_at: '2026-10-23T09:00:00+03:00' } },
        { number: '2', status: 'received', closed_at: '2026-10-20', audit: { audited_at: '2026-10-21T09:00:00+03:00' } },
        { number: '3', status: 'received', closed_at: '2026-10-22' },
        { number: '4', status: 'received', audit: { audited_at: 'invalid' } },
    ];

    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'audited', direction: 'desc' }).map((box) => box.number), ['1', '2', '3', '4']);
    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'audited', direction: 'asc' }).map((box) => box.number), ['2', '1', '3', '4']);
    assert.deepEqual(sortFinancialBoxes(boxes, { key: 'dates', direction: 'desc' }).map((box) => box.number), ['3', '2', '1', '4']);
    assert.deepEqual(sortFinancialBoxes([
        { number: '5', status: 'received', closed_at: '2026-10-01', closedAt: '2026-10-23' },
        { number: '6', status: 'received', closed_at: '2026-10-20', closedAt: '2026-10-21' },
    ], { key: 'dates', direction: 'desc' }).map((box) => box.number), ['5', '6']);
    assert.deepEqual(boxes.map((box) => box.number), ['1', '2', '3', '4']);
});

test('an entry is in effect until cancelled, whether or not the audit has checked it yet', () => {
    assert.deepEqual(entryStatus({ status: 'pending', transactionStatus: 'active' }), ['سارية', 'green']);
    assert.deepEqual(entryStatus({ status: 'confirmed', transaction: { status: 'active' } }), ['سارية', 'green']);
    assert.deepEqual(entryStatus({ status: 'cash' }), ['سارية', 'green']);
    assert.deepEqual(entryStatus({ status: 'missing', transactionStatus: 'active' }), ['غير موجودة', 'red']);
    assert.deepEqual(entryStatus({ status: 'rejected', transaction: { status: 'active' } }), ['مرفوضة', 'red']);
    assert.deepEqual(entryStatus({ status: 'missing', transactionStatus: 'cancelled' }), ['ملغاة', 'gray']);
});


test('auditors can find a box by the transfer, subscriber, account or voucher using either digit script', () => {
    const entry = { transaction: { subscription: { subscription_name: 'أم محمد', account_number: 'ACC-320' }, reference_number: 'BANK-123', voucher_number: 'V-850' } };
    const box = { number: 77, user: { name: 'أحمد' }, payments: [entry] };
    for (const query of [' أم محمد ', 'acc-٣٢٠', ' bank-١٢٣ ', 'v-850', '٧٧', 'أحمد']) {
        assert.equal(financialBoxMatches(box, query), true);
    }
    assert.equal(boxEntryMatches(entry, '٧٧'), false);
    assert.equal(financialBoxMatches(box, 'BANK-999'), false);
    assert.equal(financialBoxMatches({ number: 1 }, 'unknown'), false);
    assert.equal(financialBoxMatches(box, ''), true);
});

test('approval preview separates new debits and credits and excludes finalized and cancelled effects', () => {
    const draft = (difference, extra = {}) => ({ transaction: { status: 'active' }, audit: { difference, ...extra } });
    const summary = auditApprovalSummary([
        { status: 'received', type: 'bank_transfer', payments: [draft('50.25'), draft('-20.50'), draft('0'), { transaction: { status: 'active' } }, draft('100', { posted_at: '2026-10-18' }), { ...draft('200'), transaction: { status: 'cancelled' } }] },
        { status: 'received', type: 'cash' },
        { status: 'received', type: 'bank_transfer', audit: {}, payments: [draft('999')] },
        { status: 'open', type: 'bank_transfer', payments: [draft('999')] },
    ]);
    assert.deepEqual(summary, { boxes: 2, transfers: 5, exceptions: 2, automaticMatches: 1, debit: 50.25, credit: 20.5 });
});
