import { netOfReadingDiscount } from './accountStatement.js';

const text = (key, label, width = 22) => ({ key, label, type: 'text', width });
const decimal = (key, label, sum = false) => ({ key, label, type: 'decimal', width: 18, sum });
const money = (key, label) => ({ key, label, type: 'money', width: 20 });

/** Amounts stay in their original currency; running balances and account summaries are never added. */
export function statementExcelParts(entries, summary) {
    return [{
        key: 'statement', name: 'كشف الحساب',
        columns: [
            text('lineNumber', '#', 8), text('cashBox', 'رقم الصندوق', 14), text('voucherNumber', 'رقم السند', 14),
            text('referenceNumber', 'الرقم المرجعي', 28), text('bankName', 'البنك'), text('date', 'تاريخ الحركة', 22),
            text('description', 'البيان', 42), text('details', 'التفاصيل', 42), decimal('amount', 'المبلغ'),
            text('currencyLabel', 'العملة', 12), text('side', 'عليه / له', 12), text('typeLabel', 'نوع الحركة'),
            text('paymentMethodLabel', 'طريقة الدفع'), decimal('exchangeRate', 'سعر الصرف'), money('balance', 'الرصيد (شيكل)'),
            text('recordedByName', 'اسم المستخدم'), text('state', 'الحالة'), text('senderBankName', 'البنك المرسل'),
            text('paidOn', 'تاريخ الدفع الفعلي'), text('lateReason', 'سبب تأخر التسجيل', 34), text('bankReceivedOn', 'تاريخ وصول الحوالة إلى البنك'),
        ],
        groupBys: [{ key: 'typeLabel', label: 'نوع الحركة' }, { key: 'currencyLabel', label: 'العملة' }],
        rows: entries.map((entry) => ({
            ...entry,
            amount: Number(entry.discountLine ? netOfReadingDiscount(entry) : entry.amount),
            exchangeRate: entry.exchangeRate === null || entry.exchangeRate === '' || entry.exchangeRate === undefined ? '' : Number(entry.exchangeRate),
            balance: Number(entry.balance),
            side: entry.isCredit ? 'له' : 'عليه',
            state: entry.cancellation ? entry.cancellation.wasCorrected ? 'مُصحّحة' : 'ملغاة' : entry.isCorrection ? 'تصحيح' : '',
        })),
    }, {
        key: 'summary', name: 'ملخص الحساب الكامل',
        columns: [text('label', 'البند', 38), money('value', 'المبلغ (شيكل)')],
        rows: [['الرصيد الحالي', summary.balance], ['مجموع التحميلات', summary.charged], ['مجموع التسديدات', summary.paid], ['مجموع الخصومات', summary.discounted], ['مجموع المقاصات', summary.cleared]].map(([label, value]) => ({ label, value: Number(value) })),
    }];
}

export function readingHistoryExcelParts(readings) {
    return [{
        key: 'readings', name: 'سجل القراءات',
        columns: [
            { key: 'weekStart', label: 'بداية الأسبوع', type: 'date', width: 16 },
            { key: 'weekEnd', label: 'نهاية الأسبوع', type: 'date', width: 16 },
            decimal('previous_reading', 'السابقة'), decimal('current_reading', 'الحالية'), decimal('consumption', 'الاستهلاك', true),
            money('unitPrice', 'سعر الكيلو'), money('minimumPayment', 'الحد الأدنى'),
            { ...money('discountAmount', 'الخصم'), sum: true }, { ...money('amountDue', 'المستحق'), sum: true },
            text('statusLabel', 'الحالة'), text('recordedByName', 'سجّلها'), text('recordedAt', 'وقت التسجيل'), text('notes', 'ملاحظات', 40),
        ],
        groupBys: [{ key: 'statusLabel', label: 'الحالة' }],
        rows: readings.map((reading) => ({ ...reading, current_reading: reading.requiresReentry ? '' : reading.current_reading })),
    }];
}
