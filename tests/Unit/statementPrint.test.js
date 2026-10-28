import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';
import { statementPrintUrl, statementPrintDetails, statementRowTotals } from '../../resources/js/lib/statementPrint.js';
import { rowColumnTotal } from '../../resources/js/lib/printLayout.js';

let AccountStatement;
before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Subscriptions/AccountStatement.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        external: ['axios'],
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'use-installed-react', resolveId(id) {
            if (id === 'exceljs') return { id: import.meta.resolve(id), external: true };
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            if (id.endsWith('.css')) return '\0css';
        }, load(id) {
            if (id === '\0css') return '';
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        AccountStatement = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default;
    } finally {
        await bundle.close();
    }
});

const filters = { search: '', type: '', method: '', dateFrom: '', dateTo: '' };
const subscription = { id: 41, fullName: 'أحمد', accountNumber: 'ACC-41', branchName: 'الفرع الأول' };
const base = { date: '2026-10-19 10:00', description: 'تحميل', typeLabel: 'تحميل', currencyLabel: 'شيكل', exchangeRate: '1', isCredit: false };
const entries = [
    { ...base, id: 1, lineNumber: 1, type: 'penalty', amount: '50.00', balance: '50.00', cancellation: { wasCorrected: true, correctionId: 3, reasonLabel: 'مبلغ خطأ', notes: 'السبب محفوظ', byName: 'المحاسب', at: '2026-10-19 11:00' } },
    { ...base, id: 2, lineNumber: 2, type: 'cancellation', isCredit: true, amount: '50.00', balance: '0.00', reverses: { id: 1, lineNumber: 1 } },
    { ...base, id: 3, lineNumber: 3, type: 'penalty', amount: '30.00', balance: '30.00', isCorrection: true, corrects: { id: 1, lineNumber: 1 } },
    { ...base, id: 4, lineNumber: 4, type: 'payment', description: 'دفعة البنك', referenceNumber: 'REF-4', isCredit: true, amount: '10.00', balance: '20.00', paymentMethod: 'bank_transfer' },
];

function render(options = {}, props = {}) {
    return renderToStaticMarkup(createElement(AccountStatement, {
        subscription, entries, summary: { balance: '20', charged: '30', paid: '10', discounted: '0', cleared: '0', paymentsCount: 1, discountsCount: 0, clearingsCount: 0 },
        paymentMethods: [], transactionTypes: [], printOptions: { view: 'full', filters, openHistories: [], ...options },
        ...props,
    }));
}
const lineIds = (html) => [...html.matchAll(/id="statement-line-(\d+)"/g)].map((match) => Number(match[1]));

test('a statement starts compact despite an old full view preference unless a print view is supplied', () => {
    const previous = globalThis.window;
    globalThis.window = { localStorage: { getItem: () => 'full' } };
    try {
        assert.deepEqual(lineIds(render({ view: undefined })), [3, 4]);
        assert.deepEqual(lineIds(render({ view: 'full' })), [1, 2, 3, 4]);
    } finally {
        globalThis.window = previous;
    }
});

test('the statement print URL preserves filters, view and expanded history without the list page or modal state', () => {
    const previous = globalThis.window;
    globalThis.window = { location: { origin: 'https://example.test' } };
    try {
        const url = new URL(statementPrintUrl(41, { view: 'compact', filters: { ...filters, search: 'مرجع & شركة', type: 'debit', dateFrom: '2026-10-01', sort: 'bank_desc' }, openHistories: new Set([3]) }));
        assert.equal(url.pathname, '/subscriptions/statement-print');
        assert.equal(url.searchParams.get('subscription'), '41');
        assert.equal(url.searchParams.get('print'), '1');
        assert.equal(url.searchParams.get('view'), 'compact');
        assert.equal(url.searchParams.get('filter[search]'), 'مرجع & شركة');
        assert.equal(url.searchParams.get('filter[type]'), 'debit');
        assert.equal(url.searchParams.get('filter[dateFrom]'), '2026-10-01');
        assert.equal(url.searchParams.get('filter[sort]'), 'bank_desc');
        assert.deepEqual(url.searchParams.getAll('history[]'), ['3']);
    } finally {
        globalThis.window = previous;
    }
});

test('the print source retains the full history and avoids the legacy CSS that would hide the designer', () => {
    const html = render();
    assert.deepEqual(lineIds(html), [1, 2, 3, 4]);
    assert.match(html, /class="statement-print-source"/);
    assert.doesNotMatch(html, /class="account-statement"/);
    assert.match(html, /السبب محفوظ/);
    assert.match(html, /ملخص الحساب الكامل/);
});

test('compact printing keeps corrections and explicitly expanded history in the same order as the statement', () => {
    assert.deepEqual(lineIds(render({ view: 'compact' })), [3, 4]);
    assert.deepEqual(lineIds(render({ view: 'compact', openHistories: [3] })), [3, 1, 2, 4]);
});

test('printing applies the selected filters while retaining recorded balances and a clearly labelled full account summary', () => {
    const html = render({ filters: { ...filters, search: 'REF-4', type: 'payment', method: 'bank_transfer', dateFrom: '2026-10-19', dateTo: '2026-10-19' } });
    assert.deepEqual(lineIds(html), [4]);
    assert.match(html, /ملخص الحساب الكامل/);
    assert.match(html, /الرصيد 20 شيكل/);
    assert.deepEqual(lineIds(render({ filters: { ...filters, dateFrom: '2026-10-20', dateTo: '2026-10-19' } })), []);
});

test('printing preserves bank arrival sorting with missing dates last and reports the selected order', () => {
    const payments = [
        { ...entries[3], id: 11, bankReceivedOn: '2026-10-10', balance: '20.00' },
        { ...entries[3], id: 12, bankReceivedOn: null, balance: '10.00' },
        { ...entries[3], id: 13, bankReceivedOn: '2026-10-15', balance: '0.00' },
    ];
    const selectedFilters = { ...filters, sort: 'bank_desc' };

    assert.deepEqual(lineIds(render({ filters: selectedFilters }, { entries: payments })), [13, 11, 12]);
    assert.match(render({ filters: selectedFilters }, { entries: payments }), /وصول البنك: الأحدث أولًا/);
    const details = statementPrintDetails({ subscription, printOptions: { view: 'full', filters: selectedFilters } });
    assert.deepEqual(details.find((item) => item.key === 'sort'), { key: 'sort', label: 'ترتيب الحركات', value: 'وصول البنك: الأحدث أولًا' });
});

test('print totals subtract credits, net the reading discount and exclude folded history and running balances', () => {
    const rows = entries.map((entry) => ({ cells: {}, currency: 'شيكل', totals: statementRowTotals(entry) }));
    assert.equal(rowColumnTotal(rows, 'المبلغ'), '20 شيكل');
    assert.equal(rowColumnTotal(rows, 'الرصيد (شيكل)'), null);
    assert.equal(statementRowTotals(entries[0], true)['المبلغ'], null);
    assert.equal(statementRowTotals({ amount: '60', isCredit: false, discountLine: { amount: '15' } })['المبلغ'], 45);
    assert.equal(rowColumnTotal([...rows, { cells: {}, currency: 'دولار', totals: { 'المبلغ': -5 } }], 'المبلغ'), '20 شيكل · -5 دولار');
});

test('statement metadata comes from the current subscriber and presents filter labels', () => {
    const details = statementPrintDetails({ subscription, printOptions: { view: 'full', filters: { ...filters, type: 'payment', method: 'bank_transfer' } }, transactionTypes: [{ value: 'payment', label: 'دفعة' }], paymentMethods: [{ value: 'bank_transfer', label: 'تحويل بنكي' }] });
    assert.equal(details.find((field) => field.key === 'subscriber').value, 'أحمد');
    assert.equal(details.find((field) => field.key === 'branch').value, 'الفرع الأول');
    assert.equal(details.find((field) => field.key === 'filters').value, 'دفعة · تحويل بنكي');
    assert.deepEqual(statementPrintDetails({ subscription: null }), []);
});

test('print keeps the complete description, long reference and both late-payment dates without a details dialog', () => {
    const reference = 'BANK2026101800328945761234567890';
    const details = 'هذا البيان طويل ويجب أن يبقى كاملًا في الطباعة والتصدير. '.repeat(12);
    const entry = { ...entries[3], description: 'دفعة <اختبار>', details, referenceNumber: reference, senderBankName: 'بنك فلسطين', bankName: 'محفظة بال باي', paidOn: '2026-10-10', lateReason: 'نسي المحصّل تسجيلها' };
    const html = render({}, { entries: [entry] });

    assert.match(html, /دفعة &lt;اختبار&gt;/);
    assert.ok(html.includes(details));
    assert.ok(html.includes(reference));
    assert.match(html, /من: بنك فلسطين/);
    assert.match(html, /إلى: محفظة بال باي/);
    assert.match(html, /2026-10-10/);
    assert.match(html, /2026-10-19 10:00/);
    assert.match(html, /نسي المحصّل تسجيلها/);
    assert.doesNotMatch(html, /عرض البيان كاملًا|role="dialog"/);
});

test('a focused cash-box entry keeps its marker and related correction chain in the statement', () => {
    const html = render({ view: 'full' }, { focusLineId: 3 });
    assert.match(html, /id="statement-line-3"[^>]*class="[^"]*ledger-focused/);
    assert.match(html, /id="statement-line-3"[^>]*data-chain="1"/);
    assert.match(html, /تصحيح للحركة/);
    assert.deepEqual(lineIds(html), [1, 2, 3, 4]);
});
