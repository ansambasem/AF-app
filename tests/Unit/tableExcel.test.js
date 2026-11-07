import { test } from 'node:test';
import assert from 'node:assert/strict';
import ExcelJS from 'exceljs';
import { appendExcelPage, excelUrl, tableExcelPart, tableExcelValue } from '../../resources/js/lib/tableExcel.js';
import { defaultExcelSettings, buildWorkbookModel } from '../../resources/js/lib/excelSheet.js';
import { workbookBlob } from '../../resources/js/lib/excelWorkbook.js';
import { statementExcelParts, readingHistoryExcelParts } from '../../resources/js/lib/detailExcelParts.js';

test('Excel keeps the list search, sort, branch and filters, starting at the first matching page', () => {
    globalThis.window = { location: { origin: 'https://example.test', href: 'https://example.test/subscriptions' } };
    const url = new URL(excelUrl('المشتركون', 'https://example.test/subscriptions?page=7&sort=balance&direction=desc&search=أحمد&filter[branch_id]=3'));
    assert.equal(url.searchParams.get('excel'), '1');
    assert.equal(url.searchParams.get('print_all'), '1');
    assert.equal(url.searchParams.get('print_page'), '7');
    assert.equal(url.searchParams.has('page'), false);
    assert.equal(url.searchParams.get('search'), 'أحمد');
    assert.equal(url.searchParams.get('filter[branch_id]'), '3');
    assert.equal(url.searchParams.get('sort'), 'balance');
});

const columns = [{ key: 'account', label: 'رقم الاشتراك' }, { key: 'name', label: 'المشترك' }, { key: 'balance', label: 'الرصيد' }, { key: 'phone', label: 'رقم الجوال', extra: true }];
const row = (account) => ({ type: 'row', cells: { account, name: '=HYPERLINK("https://example.test")', balance: '100,215.50 ₪', phone: '0599000111' } });

test('every matching row beyond the print limit reaches Excel once and in order', () => {
    const first = appendExcelPage(null, { columns, rows: Array.from({ length: 2000 }, (_, index) => row(String(index))) }, { current_page: 1, total: 2003, next_page_url: '/subscriptions?page=2&filter[branch_id]=3' });
    const complete = appendExcelPage(first, { columns, rows: [row('2000'), row('2001'), row('2002')] }, { current_page: 2, total: 2003, next_page_url: null });
    assert.equal(complete.rows.length, 2003);
    assert.equal(complete.rows[0].cells.account, '0');
    assert.equal(complete.rows.at(-1).cells.account, '2002');
    assert.equal(complete.next, null);
    assert.throws(() => appendExcelPage(first, { columns, rows: [] }, { current_page: 2, total: 2003, next_page_url: null }));
    assert.throws(() => appendExcelPage(first, { columns, rows: [row('x')] }, { current_page: 1, total: 2003, next_page_url: '/same-page' }));
    assert.throws(() => appendExcelPage(first, { columns, rows: [row('x')] }, { current_page: 2, total: 2004, next_page_url: '/next' }));
});

test('formatted workbooks keep phone/account zeros, formula-like names as text, and balances as numbers', async () => {
    const part = tableExcelPart({ columns, rows: [{ type: 'group', text: 'يوم الخميس' }, row('000123')] });
    assert.equal(part.columns.find((column) => column.key === 'phone').default, false);
    const settings = defaultExcelSettings([part]);
    settings.parts.table.columns.find((column) => column.key === 'phone').on = true;
    const blob = await workbookBlob(buildWorkbookModel([part], settings), settings);
    const book = new ExcelJS.Workbook();
    await book.xlsx.load(Buffer.from(await blob.arrayBuffer()));
    const sheet = book.worksheets[0];
    const cells = sheet.getSheetValues().flatMap((values) => values ?? []);
    assert.ok(cells.includes('000123'));
    assert.ok(cells.includes('0599000111'));
    assert.ok(cells.includes('=HYPERLINK("https://example.test")'));
    assert.ok(cells.includes(100215.5));
    assert.equal(sheet.views[0].rightToLeft, true);
    assert.equal(sheet.pageSetup.fitToWidth, 1);
});

test('mixed numeric/status cells retain their words instead of disappearing', () => {
    const part = tableExcelPart({ columns: [{ key: 'amount', label: 'المبلغ' }], rows: [{ type: 'row', cells: { amount: 'لم تُدقّق' } }] });
    assert.equal(part.columns[0].type, 'text');
    assert.equal(part.rows[0].amount, 'لم تُدقّق');
    assert.equal(tableExcelValue('١٬٢٣٤٫٥٠ له', 'money'), -1234.5);
    assert.equal(tableExcelValue('—', 'money'), '');
    const counts = tableExcelPart({ columns: [{ key: 'count', label: 'عدد الدفعات' }], rows: [{ type: 'row', cells: { count: '12' } }] });
    assert.equal(counts.columns[0].type, 'int');
    assert.equal(counts.rows[0].count, 12);
});

test('statements preserve currencies, signed balances, corrections and net reading discounts without summing balances', () => {
    const [statement, summary] = statementExcelParts([
        { lineNumber: 1, amount: '200', discountLine: { amount: '20' }, balance: '-10.50', isCredit: false, currencyLabel: 'شيكل', referenceNumber: '0007' },
        { lineNumber: 2, amount: '10', balance: '-48', isCredit: true, exchangeRate: '3.75', currencyLabel: 'دولار', cancellation: { wasCorrected: true } },
    ], { balance: '-48', charged: '180', paid: '228', discounted: '20', cleared: '0' });
    assert.equal(statement.rows[0].amount, 180);
    assert.equal(statement.rows[0].referenceNumber, '0007');
    assert.equal(statement.rows[0].balance, -10.5);
    assert.equal(statement.rows[1].side, 'له');
    assert.equal(statement.rows[1].currencyLabel, 'دولار');
    assert.equal(statement.rows[1].state, 'مُصحّحة');
    assert.equal(statement.columns.some((column) => column.sum), false);
    assert.equal(summary.rows[0].value, -48);
});

test('reading exports leave invalidated current readings blank and total only additive fields', () => {
    const [part] = readingHistoryExcelParts([{ current_reading: '500', requiresReentry: true }]);
    assert.equal(part.rows[0].current_reading, '');
    assert.deepEqual(part.columns.filter((column) => column.sum).map((column) => column.key), ['consumption', 'discountAmount', 'amountDue']);
});
