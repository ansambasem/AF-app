import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { COLLECTION_INDICATORS, COLLECTION_RATES, SETTLEMENT_COLUMNS, SUBSCRIBER_COLUMNS, formatRate } from '../../resources/js/lib/remainingAmounts.js';

test('the subscribers table keeps its sixteen columns, named and ordered as they were', () => {
    assert.deepEqual(SUBSCRIBER_COLUMNS, [
        'رقم المشترك', 'اسم المشترك', 'الفرع', 'القراءة السابقة', 'القراءة الحالية', 'فرق القراءة', 'سعر الوحدة',
        'الفاتورة', 'المتراكم', 'المقدم', 'صافي المتراكم', 'الخصومات', 'المبالغ المحملة', 'الرصيد قبل الدفعات', 'إجمالي الدفعات', 'الرصيد المتبقي',
    ]);
});

test('the subscribers table draws its headers from those columns only', () => {
    const page = readFileSync(new URL('../../resources/js/Pages/Reports/RemainingAmounts.jsx', import.meta.url), 'utf8');
    const table = page.slice(page.indexOf('aria-label="تفاصيل المشتركين"'), page.indexOf('</table>', page.indexOf('aria-label="تفاصيل المشتركين"')));
    const head = table.slice(table.indexOf('<thead'), table.indexOf('</thead>'));

    assert.equal((head.match(/<th\b/g) ?? []).length, 1);
    assert.match(head, /SUBSCRIBER_COLUMNS\.map/);
    assert.doesNotMatch(table, /SETTLEMENT_COLUMNS|COLLECTION_/);
});

test('the four collection figures and three rates, and the settlement kept out of the subscribers table', () => {
    assert.deepEqual(COLLECTION_INDICATORS.map(([key, label]) => [key, label]), [
        ['payments', 'إجمالي الدفعات'], ['paidToInvoice', 'إجمالي دفعات الفاتورة'], ['paidToAccumulated', 'إجمالي دفعات المتراكم'], ['newAdvance', 'إجمالي دفعات المقدم'],
    ]);
    assert.deepEqual(COLLECTION_RATES.map(([key]) => key), ['totalRate', 'invoiceRate', 'accumulatedRate']);
    assert.deepEqual([formatRate(null), formatRate(0), formatRate(100)], ['—', '0%', '100%']);
});

test('the settlement details put the invoice target right after the advance used, before the new payments', () => {
    assert.deepEqual(SETTLEMENT_COLUMNS.slice(0, 9).map(([, label]) => label), [
        'الفاتورة الحالية', 'المبالغ المحملة', 'الخصم', 'المقدم المرحّل', 'المقدم المستخدم', 'مستهدف الفاتورة', 'الدفعات الجديدة', 'المخصص للفاتورة', 'المخصص للمتراكم',
    ]);
});
