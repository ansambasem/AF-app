/**
 * The reports page as parts for the Excel editor (see lib/excelSheet.js):
 * the summary figures, how the balances moved, day by day, where the
 * payments came in (by account, collector and currency), the readings,
 * each branch side by side, and every line of the period — so a file
 * holds whatever of the report the user picks, with the same words as the
 * page.
 */
import { weekDayName } from './weekDays.js';

const money = (key, label, extra = {}) => ({ key, label, type: 'money', width: 16, ...extra });
const count = (key, label, extra = {}) => ({ key, label, type: 'int', width: 10, ...extra });
const text = (key, label, width = 18, extra = {}) => ({ key, label, type: 'text', width, ...extra });

/** What the subscribers owe at the start and end of the period, what was charged and what was collected. */
function summaryPart(flow, collections, comparison) {
    const change = Number(flow.change);

    return {
        key: 'summary',
        name: 'الملخص',
        columns: [text('label', 'البند', 34), money('value', 'المبلغ', { width: 18 }), count('count', 'العدد'), text('note', 'ملاحظة', 30)],
        rows: [
            { label: 'الرصيد الافتتاحي', value: flow.opening, note: 'على المشتركين' },
            { label: 'التحميلات', value: flow.chargesTotal, note: 'قراءات ورسوم وغرامات' },
            { label: 'إجمالي المحصّل', value: collections.total, count: collections.count },
            { label: 'نقدي', value: collections.cash },
            { label: 'تحويلات وشيكات', value: collections.nonCash },
            { label: 'الرصيد الختامي', value: flow.closing, note: change > 0 ? 'زاد' : change < 0 ? 'نقص' : 'لم يتغير' },
            { label: 'التغيّر خلال الفترة', value: flow.change },
            ...(comparison ? [
                { label: 'المحصّل في الفترة السابقة', value: comparison.total, note: `${comparison.from} – ${comparison.to}` },
                { label: 'فرق التحصيل عن الفترة السابقة', value: comparison.difference, note: comparison.percentage === null ? 'لا تحصيل في الفترة السابقة' : `${comparison.percentage}%` },
            ] : []),
        ],
    };
}

/** The balance statement, line by line, as on the page. */
export function flowPart(flow) {
    return {
        key: 'flow',
        name: 'حركة الأرصدة',
        columns: [text('label', 'البند', 40), count('count', 'العدد'), money('total', 'المبلغ', { width: 18 })],
        rows: [
            { label: 'الرصيد الافتتاحي', total: flow.opening },
            ...flow.charges.map((line) => ({ label: `+ ${line.label}`, count: line.count, total: line.total })),
            { label: 'مجموع التحميلات (عليه)', total: flow.chargesTotal },
            ...flow.credits.map((line) => ({ label: `− ${line.label}`, count: line.count, total: line.total })),
            { label: 'مجموع الدفعات والخصومات (له)', total: flow.creditsTotal },
            { label: '± الإلغاءات والقيود العكسية', count: flow.corrections.count, total: flow.corrections.total },
            { label: 'الرصيد الختامي', total: flow.closing },
        ],
    };
}

export function daysPart(days) {
    return {
        key: 'days',
        name: 'يومًا بيوم',
        default: days.length > 1,
        columns: [
            { key: 'day', label: 'التاريخ', type: 'date', width: 13 },
            text('weekday', 'اليوم', 11),
            money('charges', 'التحميلات', { sum: true }),
            money('payments', 'الدفعات', { sum: true }),
            money('discounts', 'الخصومات', { sum: true }),
            money('corrections', 'الإلغاءات', { sum: true }),
            count('lines', 'الحركات', { sum: true }),
            money('balance', 'الرصيد الختامي لليوم', { width: 20 }),
        ],
        rows: days.map((day) => ({ ...day, weekday: weekDayName(day.day) })),
    };
}

export function accountsPart(collections) {
    return {
        key: 'accounts',
        name: 'التحصيل حسب الحساب',
        columns: [text('label', 'الحساب', 28), count('count', 'الدفعات', { sum: true }), money('total', 'المبلغ', { sum: true })],
        rows: collections.accounts,
    };
}

export function collectorsPart(collections) {
    return {
        key: 'collectors',
        name: 'التحصيل حسب المحصّل',
        columns: [
            text('name', 'الموظف', 24),
            count('count', 'الدفعات', { sum: true }),
            money('cash', 'نقدي', { sum: true }),
            money('nonCash', 'تحويلات وشيكات', { sum: true }),
            money('total', 'المجموع', { sum: true }),
        ],
        rows: collections.collectors.map((collector) => ({ ...collector, nonCash: ((Math.round(Number(collector.total) * 100) - Math.round(Number(collector.cash) * 100)) / 100).toFixed(2) })),
    };
}

export function currenciesPart(collections) {
    return {
        key: 'currencies',
        name: 'التحصيل حسب العملة',
        default: collections.currencies.some((currency) => currency.currency !== 'ILS'),
        columns: [
            text('label', 'العملة', 16),
            text('currency', 'الرمز', 8),
            count('count', 'الدفعات', { sum: true }),
            { key: 'amount', label: 'المبلغ بالعملة', type: 'decimal', width: 16 },
            money('total', 'بالشيكل', { sum: true }),
        ],
        rows: collections.currencies,
    };
}

export function readingsPart(readings) {
    return {
        key: 'readings',
        name: 'القراءات',
        default: false,
        columns: [text('label', 'البند', 30), count('count', 'العدد'), { key: 'amount', label: 'الكمية / المبلغ', type: 'decimal', width: 18 }, text('unit', 'الوحدة', 10)],
        rows: [
            { label: 'قراءات أُدخلت', count: readings.entered, amount: readings.consumption, unit: 'كيلو' },
            { label: 'قراءات اعتُمدت وحُمِّلت', count: readings.approved, amount: readings.billed, unit: '₪' },
            { label: 'بانتظار الاعتماد الآن', count: readings.pending },
        ],
    };
}

export function branchesPart(branchSummary) {
    return {
        key: 'branches',
        name: 'مقارنة الفروع',
        columns: [
            text('name', 'الفرع', 24),
            money('opening', 'الرصيد الافتتاحي', { sum: true, width: 20, default: false }),
            money('charges', 'التحميلات', { sum: true, default: false }),
            money('closing', 'الرصيد الختامي', { sum: true, width: 20 }),
            money('total', 'إجمالي المحصّل', { sum: true }),
            money('cash', 'نقدي', { sum: true }),
            money('nonCash', 'تحويلات وشيكات', { sum: true }),
            count('count', 'الدفعات', { sum: true }),
        ],
        rows: branchSummary.map((row) => ({
            name: row.name,
            opening: row.flow.opening,
            charges: row.flow.chargesTotal,
            closing: row.flow.closing,
            total: row.collections.total,
            cash: row.collections.cash,
            nonCash: row.collections.nonCash,
            count: row.collections.count,
        })),
    };
}

/** Every line of the period, oldest first, as ReportController@lines sends it. */
export function linesPart(lines, { showBranch = false } = {}) {
    return {
        key: 'lines',
        name: 'الحركات',
        columns: [
            { key: 'day', label: 'التاريخ', type: 'date', width: 12 },
            text('time', 'الوقت', 8),
            text('branchName', 'الفرع', 18, { default: showBranch }),
            text('voucherNumber', 'رقم السند', 12),
            text('subscriptionName', 'المشترك', 26),
            text('accountNumber', 'رقم الحساب', 12),
            text('meterBoxNumber', 'الطبلون', 10, { default: false }),
            text('typeLabel', 'النوع', 16),
            text('description', 'البيان', 32, { default: false }),
            text('methodLabel', 'طريقة الدفع', 14, { default: false }),
            text('account', 'الحساب', 18),
            text('currency', 'العملة', 8, { default: false }),
            { key: 'currencyAmount', label: 'المبلغ بالعملة', type: 'decimal', width: 14, default: false },
            money('debit', 'عليه', { sum: true }),
            money('credit', 'له', { sum: true }),
            text('recordedBy', 'سجّله', 18),
            text('status', 'الحالة', 10),
        ],
        groupBys: [
            { key: 'day', label: 'اليوم' },
            { key: 'typeLabel', label: 'النوع' },
            { key: 'account', label: 'الحساب' },
            { key: 'recordedBy', label: 'من سجّلها' },
            ...(showBranch ? [{ key: 'branchName', label: 'الفرع' }] : []),
        ],
        rows: lines.map((line) => ({
            ...line,
            debit: line.isCredit ? '' : line.amount,
            credit: line.isCredit ? line.amount : '',
            status: line.isCancelled ? 'ملغى' : '',
        })),
    };
}

/** The parts of the report the page shows now; `lines` joins them once fetched. */
export function reportExcelParts({ flow, collections, comparison = null, readings, days = [], branchSummary = [], lines = null, showBranch = false }) {
    const parts = [];

    if (flow && collections) {
        parts.push(summaryPart(flow, collections, comparison), flowPart(flow));
    }

    if (days.length > 0) {
        parts.push(daysPart(days));
    }

    if (collections) {
        parts.push(accountsPart(collections), collectorsPart(collections), currenciesPart(collections));
    }

    if (readings) {
        parts.push(readingsPart(readings));
    }

    if (branchSummary.length > 0) {
        parts.push(branchesPart(branchSummary));
    }

    if (lines) {
        parts.push(linesPart(lines, { showBranch }));
    }

    return parts;
}
