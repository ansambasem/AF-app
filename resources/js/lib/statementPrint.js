import { printUrl } from './print.js';
import { netOfReadingDiscount, STATEMENT_SORT_OPTIONS, STATEMENT_VIEWS } from './accountStatement.js';

export function statementPrintUrl(subscriptionId, { view, filters, openHistories = [] }) {
    const url = new URL(printUrl('كشف حساب المشترك', `/subscriptions/statement-print?subscription=${subscriptionId}`));
    url.searchParams.set('view', view);
    for (const key of ['search', 'type', 'method', 'dateFrom', 'dateTo', 'sort']) {
        if (filters[key]) url.searchParams.set(`filter[${key}]`, filters[key]);
    }
    for (const id of openHistories) url.searchParams.append('history[]', String(id));
    return url.href;
}

export function statementPrintDetails({ subscription, printOptions, transactionTypes = [], paymentMethods = [] }) {
    if (!subscription) return [];
    const filters = printOptions.filters;
    const type = { debit: 'كل ما عليه', credit: 'كل ما له' }[filters.type] ?? transactionTypes.find((item) => item.value === filters.type)?.label;
    const method = paymentMethods.find((item) => item.value === filters.method)?.label;
    const sort = filters.sort && STATEMENT_SORT_OPTIONS.find((item) => item.value === filters.sort)?.label;
    return [
        { key: 'subscriber', label: 'المشترك', value: subscription.fullName },
        { key: 'account', label: 'رقم الحساب', value: subscription.accountNumber ?? '—' },
        { key: 'subscriberNumber', label: 'رقم المشترك', value: subscription.subscriberNumber ?? '—' },
        { key: 'branch', label: 'الفرع', value: subscription.branchName ?? '—' },
        { key: 'meterBox', label: 'الطبلون', value: subscription.meterBoxNumber ?? '—' },
        { key: 'view', label: 'طريقة العرض', value: STATEMENT_VIEWS.find((item) => item.value === printOptions.view)?.label ?? 'كل الحركات' },
        ...(sort ? [{ key: 'sort', label: 'ترتيب الحركات', value: sort }] : []),
        { key: 'filters', label: 'التصفية', value: [type, method, filters.dateFrom && `من ${filters.dateFrom}`, filters.dateTo && `إلى ${filters.dateTo}`, filters.search && `بحث: ${filters.search}`].filter(Boolean).join(' · ') || 'كل الحركات ضمن طريقة العرض المختارة' },
    ];
}

/** Amount totals are the net debit minus credit in each currency; folded history never counts twice. */
export function statementRowTotals(entry, isHistory = false) {
    const amount = Number(entry.discountLine ? netOfReadingDiscount(entry) : entry.amount);
    return { 'المبلغ': isHistory ? null : (entry.isCredit ? -amount : amount) };
}
