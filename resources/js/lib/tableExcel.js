import { printUrl } from './print.js';

/** Reuse each list's authorized print source, including its filters and sort. */
export function excelUrl(title, base) {
    const url = new URL(printUrl(title, base));
    url.searchParams.set('excel', '1');
    return url.href;
}

const IDENTIFIER = /رقم|جوال|هاتف|مرجع|حساب|طبلون|كود/;
const MONEY = /مبلغ|رصيد|تحميل|تحصيل|محصّل|دفعات|تسديد|خصم|خصومات|رسوم|غرام|مستحق|إجمالي|المجموع|عليه|^له$|₪|شيكل/;
const NUMBER = /عدد|استهلاك|قراءة|السابقة|الحالية|سعر|كمية|إنذار|تمديد/;

function columnType(column) {
    if (column.type) return column.type;
    if (IDENTIFIER.test(column.label)) return 'text';
    if (/^(عدد|العدد)(\s|$)/.test(column.label)) return 'int';
    if (MONEY.test(column.label)) return /عملة|دولار|دينار/.test(column.label) ? 'decimal' : 'money';
    if (NUMBER.test(column.label)) return 'decimal';
    return 'text';
}

/** Never guess identifiers or dates; only an entirely numeric amount becomes a number. */
export function tableExcelValue(value, type) {
    if (value == null || value === '') return '';
    if (!['money', 'decimal', 'int'].includes(type)) return String(value);
    if (typeof value === 'number') return Number.isFinite(value) ? value : '';
    const text = String(value).replace(/[\u200e\u200f\u061c\u2066-\u2069]/g, '').trim();
    if (text === '—' || text === '–') return '';
    const normalized = text.replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 1632)).replace(/[٬,]/g, '').replace(/٫/g, '.');
    const numeric = normalized.replace(/\s*(₪|شيكل|عليه|له)\s*/g, '').trim();
    if (!/^[+-]?\d+(\.\d+)?$/.test(numeric)) return text;
    const amount = Number(numeric);
    return normalized.includes('له') ? -Math.abs(amount) : amount;
}

/** The print table's fields keep identifiers intact and offer its extra columns in Excel. */
export function tableExcelPart(table, { key = 'table', name = 'الجدول' } = {}) {
    const data = table.rows.filter((row) => row.type === 'row');
    const columns = table.columns.map((column) => ({
        key: column.key,
        label: column.label,
        type: columnType(column),
        width: Math.min(40, data.slice(0, 100).reduce((width, row) => Math.max(width, String(row.cells[column.key] ?? '').length + 2), Math.max(16, column.label.length + 6))),
        default: !column.extra,
    })).map((column) => {
        const numeric = ['money', 'decimal', 'int'].includes(column.type);
        const mixed = numeric && data.some((row) => typeof tableExcelValue(row.cells[column.key], column.type) === 'string' && tableExcelValue(row.cells[column.key], column.type) !== '');
        return mixed ? { ...column, type: 'text' } : column;
    });
    return {
        key, name, columns,
        rows: data.map((row) => Object.fromEntries(columns.map((column) => [column.key, tableExcelValue(row.cells[column.key], column.type)]))),
        groupBys: columns.filter((column) => column.type === 'text').map(({ key, label }) => ({ key, label })),
    };
}

/** Fail closed if the source changes or stops advancing rather than downloading an incomplete file. */
export function appendExcelPage(state, table, pagination) {
    const page = pagination?.current_page ?? 1;
    const rows = table.rows.filter((row) => row.type === 'row');
    if (state && (page !== state.page + 1 || JSON.stringify(table.columns) !== JSON.stringify(state.columns) || pagination.total !== state.total)) {
        throw new Error('تغيّرت نتائج الجدول أثناء التحميل. أغلق المحرر وأعد المحاولة.');
    }
    const collected = [...(state?.rows ?? []), ...rows];
    const total = pagination?.total ?? collected.length;
    if (collected.length < total && (!rows.length || !pagination?.next_page_url)) {
        throw new Error('تعذّر تحميل جميع نتائج الجدول. أعد المحاولة.');
    }
    return { columns: table.columns, rows: collected, page, total, next: collected.length < total ? pagination.next_page_url : null };
}
