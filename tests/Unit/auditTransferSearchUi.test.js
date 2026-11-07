import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let Search;
before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Payments/AuditTransferSearch.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'render-search', resolveId(id) {
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            if (id.endsWith('.css')) return '\0css';
        }, load(id) { if (id === '\0css') return ''; } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        Search = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default;
    } finally { await bundle.close(); }
});

const entry = {
    id: 1, canReview: true, finalized: false,
    transaction: { subscription: { full_name: 'مشترك الاختبار' } },
    ledger: { date: '2026-10-19 10:19', voucherNumber: '000125', referenceNumber: 'BANK-125', bankName: 'بنك فلسطين', paidAmount: '100', currencyLabel: 'شيكل' },
    box: { number: 41, collector: 'المحصل' }, audit: null,
};
function render(entries = [], options = {}, busy = false) {
    return renderToStaticMarkup(createElement(Search, {
        branchName: 'فرع نابلس', busy, data: { search: '', state: '', results: { data: entries, total: entries.length }, ...options },
        onSearch() {}, onPage() {}, onEdit() {},
    }));
}

test('initial and empty search states explain how to find a transaction', () => {
    assert.match(render(), /ابدأ برقم الحركة/);
    assert.match(render(), /تظهر النتائج تلقائيًا بعد توقف الكتابة/);
    assert.doesNotMatch(render(), /type="submit"/);
    assert.match(render([], { search: 'unknown' }), /لا توجد حوالة مطابقة/);
});

test('loading leaves search controls usable and disables editing the previous results', () => {
    const html = render([entry], { search: 'BANK-125' }, true);
    assert.match(html, /جارٍ تحديث النتائج/);
    assert.doesNotMatch(html, /<input[^>]*disabled/);
    assert.doesNotMatch(html, /<select[^>]*disabled/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>[^]*?تعديل الحوالة/);
});

test('unreviewed bank amount is unknown and transaction date and reference use separate columns', () => {
    const html = render([entry], { search: 'BANK-125' });
    assert.match(html, /لم يُسجّل بعد/);
    assert.match(html, /تعديل الحوالة/);
    assert.match(html, /<th[^>]*>تاريخ الحركة<\/th>/);
    assert.match(html, /<th[^>]*>الرقم المرجعي<\/th>/);
    assert.match(html, /فرع نابلس/);
    assert.match(html, /صندوق 41/);
});

test('missing drafts use red status and escape free text while finalized entries are locked', () => {
    const audit = { bank_amount: '0', difference: '100', outcome: 'missing', reason: '<script>unsafe</script>' };
    const draft = render([{ ...entry, audit }]);
    assert.match(draft, /غير موجودة/);
    assert.match(draft, /text-red/);
    assert.match(draft, /مسودة محفوظة/);
    assert.match(draft, /&lt;script&gt;/);
    assert.doesNotMatch(draft, /<script>/);
    const final = render([{ ...entry, audit, finalized: true, canReview: false }]);
    assert.match(final, /معتمدة نهائيًا/);
    assert.match(final, /مقفلة/);
    assert.doesNotMatch(final, /تعديل الحوالة/);
});
