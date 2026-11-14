export const bankAuditReasonPresets = [
    { value: 'missing', label: 'غير موجودة في البنك' },
    { value: 'partial', label: 'وصل جزء من المبلغ' },
    { value: 'amount', label: 'قيمة البنك غير مطابقة' },
    { value: 'details', label: 'اختلاف في بيانات الحوالة' },
];

/** Presets are editable note text; only a missing transfer determines the bank amount. */
export function bankAuditReasonChoice(data, choice) {
    const preset = bankAuditReasonPresets.find((option) => option.value === choice);

    return preset ? { ...data, reason: preset.label, ...(choice === 'missing' ? { bank_amount: '0', ...(data.parts ? { parts: data.parts.map((part) => ({ ...part, bank_amount: '0' })) } : {}) } : {}) } : data;
}

/** Only zero and an exact match have an unambiguous allocation. Other totals require the auditor's allocation. */
export function splitAuditTotal(data, split, value) {
    const cents = (amount) => Math.round(Number(amount) * 100);
    const reset = value !== '' && (cents(value) === 0 || cents(value) === cents(split.total));
    return { ...data, bank_amount: value, ...(reset ? { parts: split.parts.map((part) => ({ transaction_id: part.transaction_id, bank_amount: cents(value) === 0 ? '0' : part.recorded })) } : {}) };
}
