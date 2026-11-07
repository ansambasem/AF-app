import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import { describeBalance } from '@/lib/accountStatement';
import { balanceInText } from '@/lib/balance';
import { BalanceAfter } from './AccountSummary';
import { CorrectionReasonFields, EMPTY_CORRECTION, OriginalLine } from './CorrectionFields';

/**
 * Cancel a payment, charge or discount (a statement line) and say why, or
 * refund a payment. Nothing is erased: the line stays in the statement
 * marked cancelled, with the reason, and a reversal under it takes its
 * amount back off the balance. A refund always returns the whole payment;
 * `onRecordPayment`, when given, opens the payment form once it is saved,
 * to record the right payment in its place.
 */
export default function DeleteTransactionModal({ onClose, subscription, balance, entry, action, reasons, onRecordPayment = null }) {
    const url = `/subscriptions/${subscription.id}/transactions/${entry.id}`;
    const isRefund = action === 'refund';
    const isSplit = Boolean(entry.splitPayment);
    const noun = {
        discount: 'الخصم',
        reading_discount: 'خصم القراءة الأسبوعية',
        credit: 'الرصيد الدائن',
        clearing: 'المقاصة',
    }[entry.type] ?? 'الحركة';
    const label = isRefund ? 'إرجاع الدفعة' : `إلغاء ${noun}`;
    const refundAmount = Number(entry.refundableAmount ?? entry.amount);
    const [recordNewPayment, setRecordNewPayment] = useState(Boolean(onRecordPayment) && !isSplit);
    const isReadingCharge = entry.type === 'meter_reading' && !isRefund;
    const form = useForm({ ...EMPTY_CORRECTION, action, reopen_reading: false });

    // A reading entered wrongly is meant to be corrected and billed again, so that reason suggests sending it back; any other waives its bill.
    // The box stays the user's to change once a reason is picked.
    useEffect(() => {
        if (isReadingCharge) {
            form.setData('reopen_reading', form.data.correction_reason === 'wrong_reading');
        }
    }, [form.data.correction_reason]);
    const deleteForm = {
        ...form,
        isEdit: true,
        save: (options) =>
            form.post(`${url}/actions`, {
                ...options,
                onSuccess: (...args) => {
                    options?.onSuccess?.(...args);

                    if (isRefund && recordNewPayment && onRecordPayment) {
                        onRecordPayment();
                    }
                },
            }),
    };
    // Cancelling a weekly reading cancels its standing discount too, which gives that amount back to what is owed.
    const discountReturned = !isRefund && entry.readingDiscount ? Number(entry.readingDiscount) : 0;
    const balanceAfter = describeBalance(
        isRefund ? Number(balance) + refundAmount : Number(balance) - Number(entry.recorded.effect) + discountReturned,
    );
    const balanceText = balanceInText(balanceAfter.signed);

    return (
        <FormModal
            show
            onClose={onClose}
            form={deleteForm}
            title={label}
            icon={isRefund ? 'repeat' : 'close'}
            maxWidth="xl"
            bodyClassName="space-y-5"
            action={{ submitLabel: label, title: `${label}؟`, confirmLabel: `نعم، ${label}`, icon: isRefund ? 'repeat' : 'close', tone: 'danger' }}
            saveConfirmMessage={isSplit
                ? `سيُرجع كامل الحوالة المقسّمة من جميع المشتركين المرتبطين بها بالسبب نفسه، ويصبح رصيد هذا المشترك ${balanceText}. هل تريد المتابعة؟`
                : `ستُضاف حركة ${label} مرتبطة بـ «${entry.description}»${discountReturned ? `، ويُلغى معها خصم القراءة الأسبوعية (${entry.readingDiscount} شيكل)` : ''}، ويصبح الرصيد ${balanceText}. هل تريد المتابعة؟`}
        >
            <OriginalLine entry={entry} tone="cancel" />

            {isSplit && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm leading-7 text-gray-700">
                    <p className="font-semibold text-gray-900">الإرجاع يشمل الحوالة المقسّمة كاملة</p>
                    <p>تُرجع جميع الحصص القائمة من حسابات المشتركين المرتبطين بالحوالة بالسبب نفسه، وتُحدّث أرصدتهم والصناديق المفتوحة تلقائيًا. إذا تعذّر إرجاع أحد الأجزاء، فلن يُرجع أي جزء.</p>
                </div>
            )}

            <p className="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600">
                لا تُمسح الحركة من السجل: تبقى الحركة الأصلية، وتُضاف حركة {label} جديدة في نهاية السجل مع رابط مباشر بينهما.
            </p>

            {discountReturned > 0 && (
                <p className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm text-gray-700">
                    لهذه القراءة خصم أسبوعي بقيمة <b className="font-display">{entry.readingDiscount}</b> شيكل. يُلغى معها تلقائيًا بالسبب نفسه، لأنه لا يقوم
                    إلا على فاتورة القراءة.
                </p>
            )}

            {isReadingCharge && (
                <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm">
                    <input
                        type="checkbox"
                        className="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                        checked={form.data.reopen_reading}
                        onChange={(event) => form.setData('reopen_reading', event.target.checked)}
                    />
                    <span>
                        <span className="block font-semibold text-gray-900">أعد القراءة إلى «بانتظار الاعتماد»</span>
                        <span className="block text-xs text-gray-500">
                            لتصحيح قيمتها ثم اعتمادها فتُحمَّل على المشترك من جديد. إن لم تحدّده تُلغى فاتورة هذا الأسبوع فقط وتبقى القراءة معتمدة،
                            ولا تُحمَّل على المشترك ثانية.
                        </span>
                    </span>
                </label>
            )}

            {isRefund && (
                <div className="space-y-3">
                    <p className="rounded-xl border border-gray-100 px-4 py-3 text-sm text-gray-700">
                        يُرجَع <b className="font-display text-gray-900">{refundAmount.toLocaleString('en-US', { maximumFractionDigits: 2 })}</b> شيكل،
                        {isSplit ? 'وهي حصة هذا المشترك؛ تُرجع حصص بقية المشتركين أيضًا.' : 'أي كامل الدفعة. إن كان المبلغ الصحيح غير ذلك، سجّله دفعةً جديدة بعد الإرجاع.'}
                    </p>
                    {onRecordPayment && (
                        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                checked={recordNewPayment}
                                onChange={(event) => setRecordNewPayment(event.target.checked)}
                            />
                            <span>
                                <span className="block font-semibold text-gray-900">{isSplit ? 'سجّل دفعة جديدة لهذا المشترك بعد الإرجاع' : 'سجّل الدفعة الصحيحة بعد الإرجاع'}</span>
                                <span className="block text-xs text-gray-500">يُفتح نموذج الدفعة مباشرة بعد حفظ الإرجاع.</span>
                            </span>
                        </label>
                    )}
                    {form.errors.action && <p className="text-sm text-red-600">{form.errors.action}</p>}
                </div>
            )}

            <CorrectionReasonFields form={form} reasons={reasons} action={label} />

            <BalanceAfter label={`${isSplit ? 'رصيد هذا المشترك' : 'الرصيد'} بعد ${label}`} balanceAfter={balanceAfter} />
        </FormModal>
    );
}
