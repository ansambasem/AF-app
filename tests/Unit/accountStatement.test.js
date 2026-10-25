import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    compactStatementEntries,
    describeBalance,
    discountAmount,
    filterStatementEntries,
    netOfReadingDiscount,
    paymentInShekels,
    relatedLineChains,
    statementCsv,
    withReadingDiscounts,
} from '../../resources/js/lib/accountStatement.js';

const entries = [
    {
        id: 1,
        date: '2026-08-20 09:15',
        description: 'رسوم اشتراك جديد',
        type: 'subscription_fee',
        typeLabel: 'رسوم اشتراك',
        isCredit: false,
        amount: '50.00',
        recordedByName: 'سارة',
    },
    {
        id: 2,
        date: '2026-08-30 12:40',
        description: 'دفعة نقدية',
        type: 'payment',
        typeLabel: 'دفعة',
        isCredit: true,
        paymentMethod: 'cash',
        voucherNumber: '4471',
        systemVoucherNumber: '000118',
        manualVoucherNumber: '4471',
        amount: '20.00',
        recordedByName: 'Mohammed',
    },
    {
        id: 3,
        date: '2026-09-13 14:20',
        description: 'دفعة بتحويل بنكي',
        type: 'payment',
        typeLabel: 'دفعة',
        isCredit: true,
        paymentMethod: 'bank_transfer',
        bankName: 'بنك فلسطين',
        senderBankName: 'البنك الإسلامي الفلسطيني',
        referenceNumber: 'TRX-88214',
        amount: '49.30',
        recordedByName: 'علي',
    },
    {
        id: 4,
        date: '2026-09-20 08:00',
        description: 'خصم بمبلغ ثابت',
        type: 'discount',
        typeLabel: 'خصم',
        isCredit: true,
        amount: '10.00',
        details: 'تعويض عن انقطاع الكهرباء',
        recordedByName: 'علي',
    },
];

const ids = (rows) => rows.map((row) => row.id);

test('filters by every charge, every payment and discount, one type, or payment method', () => {
    assert.deepEqual(ids(filterStatementEntries(entries, { type: 'debit' })), [1]);
    assert.deepEqual(ids(filterStatementEntries(entries, { type: 'credit' })), [2, 3, 4]);
    assert.deepEqual(ids(filterStatementEntries(entries, { type: 'discount' })), [4]);
    assert.deepEqual(ids(filterStatementEntries(entries, { method: 'bank_transfer' })), [3]);
});

test('search matches voucher numbers, banks, references and employee names', () => {
    assert.deepEqual(ids(filterStatementEntries(entries, { search: '000118' })), [2]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: '4471' })), [2]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: 'بنك فلسطين' })), [3]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: 'البنك الإسلامي الفلسطيني' })), [3]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: ' trx-88214 ' })), [3]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: 'MOHAMMED' })), [2]);
    assert.deepEqual(ids(filterStatementEntries(entries, { search: 'انقطاع' })), [4]);
});

test('the date range includes both ends, and a reversed range matches nothing', () => {
    assert.deepEqual(ids(filterStatementEntries(entries, { dateFrom: '2026-08-30', dateTo: '2026-09-13' })), [2, 3]);
    assert.deepEqual(filterStatementEntries(entries, { dateFrom: '2026-09-13', dateTo: '2026-08-30' }), []);
    assert.deepEqual(filterStatementEntries(entries), entries);
});

test('a balance reads as owed, in credit or settled', () => {
    assert.deepEqual(describeBalance('54.10'), { amount: '54.10', signed: '54.10', label: 'عليه', tone: 'owes' });
    assert.deepEqual(describeBalance('-9.10'), { amount: '9.10', signed: '-9.10', label: 'له', tone: 'credit' });
    assert.deepEqual(describeBalance('0.00'), { amount: '0', signed: '0', label: 'مسدّد', tone: 'settled' });
    // Whole amounts drop the .00.
    assert.deepEqual(describeBalance('50.00'), { amount: '50', signed: '50', label: 'عليه', tone: 'owes' });
});

test('a payment in shekels ignores the rate; others are converted at it', () => {
    assert.equal(paymentInShekels('54.10', 'ILS', '3.7'), 54.1);
    assert.equal(paymentInShekels('20', 'USD', '3.7'), 74);
    assert.equal(paymentInShekels('15', 'JOD', '5.215'), 78.23);
    assert.equal(paymentInShekels('20', 'USD', ''), null);
    assert.equal(paymentInShekels('', 'ILS', '1'), null);
});

test('a discount is a percentage of what is owed, kilowatts at the kilo price, or shekels', () => {
    assert.equal(discountAmount('percentage', '10', '250.00', '0.60'), 25);
    assert.equal(discountAmount('kilowatt', '25', '250.00', '0.60'), 15);
    assert.equal(discountAmount('shekel', '30', '250.00', '0.60'), 30);
    assert.equal(discountAmount('percentage', '12.5', '78.18', '0.60'), 9.77);
    assert.equal(discountAmount('shekel', '', '250.00', '0.60'), null);
    assert.equal(discountAmount('shekel', '0', '250.00', '0.60'), null);
});

test('the statement exports each shown line with its side, its state and the balance it left', () => {
    const csv = statementCsv([
        {
            lineNumber: 1,
            date: '2026-08-20 09:15',
            voucherNumber: null,
            description: 'غرامة',
            details: '=HYPERLINK("x")',
            typeLabel: 'غرامة',
            isCredit: false,
            amount: '50.00',
            currencyLabel: 'شيكل',
            exchangeRate: '1',
            balance: '50.00',
            cancellation: { wasCorrected: false },
            recordedByName: 'سارة',
        },
        {
            lineNumber: 2,
            date: '2026-08-30 12:40',
            voucherNumber: '4471',
            description: 'دفعة نقدية',
            typeLabel: 'دفعة',
            isCredit: true,
            amount: '74.00',
            currencyLabel: 'شيكل',
            exchangeRate: '1',
            paymentMethodLabel: 'نقدي',
            cashBox: '3',
            balance: '-24.00',
            isCorrection: true,
            recordedByName: 'Mohammed',
            paidOn: '2026-08-25',
            lateReason: 'نسيان إدخال الوصل',
            bankReceivedOn: '2026-08-26',
        },
    ]);
    const lines = csv.split('\r\n');

    assert.ok(csv.startsWith('\uFEFF'));
    assert.equal(lines.length, 3);
    assert.ok(lines[1].startsWith('"1","2026-08-20 09:15","","غرامة","\'=HYPERLINK(""x"")","غرامة","عليه","50","شيكل","1"'));
    assert.ok(lines[1].endsWith('"50","عليه","ملغاة","سارة","","",""'));
    assert.ok(lines[2].includes('"4471"'));
    assert.ok(lines[2].endsWith('"-24","له","تصحيح","Mohammed","2026-08-25","نسيان إدخال الوصل","2026-08-26"'));
});

/** A weekly reading of 60 with a standing discount of 30, corrected to 60 again, then a payment cancelled outright. */
const ledger = [
    { id: 1, lineNumber: 1, type: 'meter_reading', meterReadingId: 9, amount: '60.00', balance: '60.00', cancellation: { wasCorrected: true, correctionId: 5 } },
    { id: 2, lineNumber: 2, type: 'reading_discount', meterReadingId: 9, amount: '30.00', balance: '30.00', cancellation: { wasCorrected: true, correctionId: 6 } },
    { id: 3, lineNumber: 3, type: 'reversal', amount: '60.00', balance: '-30.00', reverses: { id: 1 } },
    { id: 4, lineNumber: 4, type: 'reversal', amount: '30.00', balance: '0.00', reverses: { id: 2 } },
    { id: 5, lineNumber: 5, type: 'meter_reading', meterReadingId: 9, amount: '60.00', balance: '60.00', isCorrection: true, corrects: { id: 1 } },
    { id: 6, lineNumber: 6, type: 'reading_discount', meterReadingId: 9, amount: '30.00', balance: '30.00', isCorrection: true, corrects: { id: 2 } },
    { id: 7, lineNumber: 7, type: 'payment', amount: '25.00', balance: '5.00', cancellation: { wasCorrected: false } },
    { id: 8, lineNumber: 8, type: 'fine', amount: '10.00', balance: '15.00' },
    { id: 9, lineNumber: 9, type: 'refund', amount: '25.00', balance: '40.00', reverses: { id: 7 } },
];

test('the compact statement leaves out cancelled lines with their reversals and rebalances what is left', () => {
    const { entries: shown, hiddenCount } = compactStatementEntries(ledger);

    assert.deepEqual(ids(shown), [5, 6, 8]);
    assert.deepEqual(
        shown.map((entry) => entry.balance),
        ['60.00', '30.00', '40.00'],
    );
    assert.equal(shown.at(-1).balance, ledger.at(-1).balance);
    assert.equal(hiddenCount, 6);
});

test('a correction carries the lines it replaced; a plain cancellation is carried by nothing', () => {
    const { entries: shown } = compactStatementEntries(ledger);

    assert.deepEqual(ids(shown[0].history), [1, 3]);
    assert.deepEqual(ids(shown[1].history), [2, 4]);
    assert.deepEqual(shown[2].history, []);
});

test('a payment refunded only in part stays in the compact statement', () => {
    const partlyRefunded = [
        { id: 1, lineNumber: 1, type: 'payment', amount: '100.00', balance: '-100.00', cancellation: { wasCorrected: false } },
        { id: 2, lineNumber: 2, type: 'refund', amount: '40.00', balance: '-60.00', reverses: { id: 1 } },
    ];

    assert.deepEqual(ids(compactStatementEntries(partlyRefunded).entries), [1, 2]);
});

test('a cancelled reading discount folds under the standing reading of the same week', () => {
    const { entries: shown } = compactStatementEntries([
        { id: 1, lineNumber: 1, type: 'meter_reading', meterReadingId: 9, amount: '60.00', balance: '60.00' },
        { id: 2, lineNumber: 2, type: 'reading_discount', meterReadingId: 9, amount: '30.00', balance: '30.00', cancellation: { wasCorrected: false } },
        { id: 3, lineNumber: 3, type: 'reversal', amount: '30.00', balance: '60.00', reverses: { id: 2 } },
    ]);

    assert.deepEqual(ids(shown), [1]);
    assert.deepEqual(ids(shown[0].history), [2, 3]);
});

test('a reading shows its standing discount inside its line, as one bill', () => {
    const rows = withReadingDiscounts(compactStatementEntries(ledger).entries);

    assert.deepEqual(ids(rows), [5, 8]);
    assert.equal(rows[0].discountLine.id, 6);
    assert.equal(rows[0].balance, '30.00');
    assert.equal(netOfReadingDiscount(rows[0]), '30.00');
    assert.deepEqual(ids(rows[0].history), [1, 2, 3, 4]);
});

test('a discount is kept on its own line unless it follows its own standing reading', () => {
    const rows = withReadingDiscounts([
        { id: 1, type: 'meter_reading', meterReadingId: 9, amount: '60.00', balance: '60.00' },
        { id: 2, type: 'reading_discount', meterReadingId: 8, amount: '30.00', balance: '30.00' },
        { id: 3, type: 'meter_reading', meterReadingId: 7, amount: '60.00', balance: '90.00', cancellation: { wasCorrected: false } },
        { id: 4, type: 'reading_discount', meterReadingId: 7, amount: '30.00', balance: '60.00' },
    ]);

    assert.deepEqual(ids(rows), [1, 2, 3, 4]);
    assert.ok(rows.every((row) => !row.discountLine));
});

test('lines that belong together share a chain number; a line on its own has none', () => {
    const chains = relatedLineChains(ledger);

    assert.deepEqual([1, 2, 3, 4, 5, 6].map((id) => chains.get(id)), [1, 1, 1, 1, 1, 1]);
    assert.equal(chains.get(7), 2);
    assert.equal(chains.get(9), 2);
    assert.equal(chains.has(8), false);
});
