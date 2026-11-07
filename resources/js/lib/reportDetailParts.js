const text = (key, label, width = 22) => ({ key, label, width, type: 'text' });
const money = (key, label, balance = false) => ({ key, label, width: 18, type: 'money', balance });
const date = (key, label, width = 14) => ({ key, label, width, type: 'date' });
export const debtColumns = [text('name', 'المشترك'), text('accountNumber', 'رقم الحساب'), text('branchName', 'الفرع'), money('current', 'حتى 30 يومًا'), money('days_60', '31–60 يومًا'), money('days_90', '61–90 يومًا'), money('older', 'أكثر من 90 يومًا'), money('balance', 'الرصيد عليه', true), date('lastPaymentDate', 'آخر دفعة')];
export const boxColumns = [text('number', 'الصندوق', 12), text('collector', 'المحصّل'), text('branchName', 'الفرع'), text('type', 'النوع'), text('status', 'الحالة'), text('currency', 'العملة', 10), { ...money('amount', 'المبلغ المسجل'), type: 'decimal' }, { key: 'count', label: 'عدد الحركات', type: 'int', width: 12 }];
export const statementColumns = [date('day', 'التاريخ'), text('voucherNumber', 'السند', 16), text('description', 'البيان', 42), money('debit', 'عليه (مدين)'), money('credit', 'له (دائن)'), money('balance', 'الرصيد', true)];

/**
 * A statement line cancelled together with its reversal inside the period
 * stays in the sheet with its balance, but its amount moves to the
 * description so the debit and credit totals match the screen's.
 */
function withoutNettedAmounts(row) {
    if (!row.netted) return row;
    const amount = Number(row.debit) > 0 ? row.debit : row.credit;

    return { ...row, description: `${row.description} — ملغاة مع قيدها العكسي (${amount})`, debit: '0.00', credit: '0.00' };
}

export function detailExcelPart(key, name, columns, rows) {
    return {
        key, name, default: true, rows: key === 'statement' ? rows.map(withoutNettedAmounts) : rows,
        columns: columns.map(({ balance, ...column }) => ({
            ...column,
            sum: key === 'debts' ? column.type === 'money' : key === 'statement' ? ['debit', 'credit'].includes(column.key) : column.key === 'count',
        })),
    };
}
