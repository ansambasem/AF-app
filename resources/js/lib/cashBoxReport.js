import { formatMoney } from './format.js';

export const reportFilters = { active: 'السارية', mismatched: 'غير المطابقة', returned: 'المستردّة / الملغاة', all: 'الكل' };
export const reportCurrency = { ILS: 'شيكل', USD: 'دولار', JOD: 'دينار' };
export const reportColumns = ['المشترك', 'تاريخ الحركة', 'رقم المرجع', 'مبلغ الصندوق', 'وصل البنك', 'الفرق', 'الحالة', 'سبب الاسترداد / الملاحظات'];
const boxTypes = { cash: 'الصندوق النقدي', bank_transfer: 'صندوق الحوالات البنكية', clearing: 'صندوق المقاصات' };
export const reportDateTime = (value) => value ? new Intl.DateTimeFormat('ar-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Gaza' }).format(new Date(value)) : '—';

export function reportDetails(report) {
    return [
        { key: 'number', label: 'رقم الصندوق', value: report.number ?? '—' },
        { key: 'collector', label: 'المحصّل صاحب الصندوق', value: report.collector ?? '—' },
        { key: 'type', label: 'نوع الصندوق', value: boxTypes[report.type] ?? '—' },
        { key: 'branch', label: 'الفرع', value: report.branch ?? '—' },
        { key: 'opened', label: 'فُتح بتاريخ', value: reportDateTime(report.openedAt) },
        { key: 'closed', label: 'أُغلق بتاريخ', value: report.closedAt ? reportDateTime(report.closedAt) : 'لم يُغلق بعد' },
        { key: 'closedBy', label: 'أغلقه', value: report.closedBy ?? '—' },
        { key: 'audit', label: 'التدقيق', value: report.auditStatus },
        { key: 'scope', label: 'الحركات المعروضة', value: `${reportFilters[report.filter]} · ${report.rows.length} من ${report.counts.all}` },
    ];
}

export function reportMoney(value, currency) {
    return value == null ? '—' : `${formatMoney(value)} ${reportCurrency[currency] ?? currency}`;
}

/** Only active payments contribute to reconciliation; each currency remains separate. */
export function reportRowTotals(row) {
    return {
        'مبلغ الصندوق': row.active ? row.amount : null,
        'وصل البنك': row.active ? row.bankAmount : null,
        'الفرق': row.active ? row.difference : null,
    };
}
