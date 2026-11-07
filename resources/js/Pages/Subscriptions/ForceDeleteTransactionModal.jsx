import { useForm } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { describeBalance } from '@/lib/accountStatement';
import { balanceInText } from '@/lib/balance';
import { BalanceAfter } from './AccountSummary';
import { OriginalLine } from './CorrectionFields';

/**
 * Erase one line for good. Unlike deleting, nothing stays on the
 * statement: the line (and its reversal, if it was cancelled) is gone, and
 * the balance is as if it was never recorded. Only the reason is logged.
 */
export default function ForceDeleteTransactionModal({ onClose, subscription, balance, entry, action = 'delete' }) {
    const isReversalDelete = action === 'delete_reversal';
    const isTreeDelete = action === 'delete_tree';
    const isSplit = Boolean(entry.splitPayment);
    const noun = {
        payment: 'الدفعة',
        discount: 'الخصم',
        reading_discount: 'خصم القراءة الأسبوعية',
        credit: 'الرصيد الدائن',
        clearing: 'المقاصة',
    }[entry.type] ?? 'الحركة';
    const reversalNoun = entry.type === 'refund' ? 'الإرجاع' : 'الإلغاء';
    const title = isReversalDelete
        ? `حذف ${reversalNoun} فقط`
        : isTreeDelete
          ? entry.type === 'payment'
              ? 'حذف الدفعة والإرجاع معًا'
              : `حذف ${noun} والإلغاء معًا`
          : `حذف ${noun}`;
    const submitLabel = isTreeDelete ? 'حذف الحركات' : isReversalDelete ? `حذف ${reversalNoun}` : `حذف ${noun}`;
    const form = useForm({ action, correction_notes: '' });
    const eraseForm = { ...form, isEdit: true, save: (options) => form.post(`/subscriptions/${subscription.id}/transactions/${entry.id}/actions`, options) };
    const effect = entry.actionEffects?.[action] ?? entry.eraseEffect;
    const balanceAfter = describeBalance(Number(balance) - Number(effect));
    const balanceText = balanceInText(balanceAfter.signed);

    return (
        <FormModal
            show
            onClose={onClose}
            form={eraseForm}
            title={title}
            icon="trash"
            headerTone="danger"
            maxWidth="xl"
            bodyClassName="space-y-5"
            action={{ submitLabel, title: `${title}؟`, confirmLabel: `نعم، ${submitLabel}`, icon: 'trash', tone: 'danger' }}
            saveConfirmMessage={`${isSplit ? (isReversalDelete ? 'حذف الإرجاعات وإعادة جميع حصص الحوالة المقسّمة إلى الحالة النشطة' : 'حذف جميع حصص الحوالة المقسّمة وحركاتها المرتبطة من حسابات المشتركين') : title} نهائيًا وإعادة موازنة الحركات اللاحقة، وسيصبح ${isSplit ? 'رصيد هذا المشترك' : 'الرصيد'} ${balanceText}. لا يمكن التراجع. هل تريد المتابعة؟`}
        >
            <OriginalLine entry={entry} tone="erase" />

            {isSplit && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm leading-7 text-gray-700">
                    <p className="font-semibold text-gray-900">الإجراء يشمل الحوالة المقسّمة كاملة</p>
                    <p>{isReversalDelete
                        ? 'تُحذف الإرجاعات من حسابات جميع المشتركين المرتبطين بالحوالة، وتعود حصصهم الأصلية فعّالة.'
                        : 'تُحذف جميع حصص الحوالة وحركات الإلغاء أو الإرجاع والتصحيح المرتبطة بها من حسابات المشتركين، مع حفظ السبب في سجل التدقيق.'} إذا تعذّر تنفيذ الإجراء على أحد الأجزاء، فلن يتغيّر أي جزء.</p>
                </div>
            )}

            {isTreeDelete && entry.linkedReversals?.length > 0 && (
                <div className="grid gap-2 rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <p className="text-xs font-semibold text-gray-500">الحركات المرتبطة التي ستُحذف</p>
                    {entry.linkedReversals.map((reversal) => (
                        <div key={reversal.id} className="flex items-center justify-between gap-3 rounded-lg bg-surface px-3 py-2 text-sm">
                            <span>{reversal.description}</span>
                            <bdi dir="ltr" className="font-semibold text-gray-900">{reversal.amount} شيكل</bdi>
                        </div>
                    ))}
                </div>
            )}

            {!isSplit && action === 'delete' && entry.isCorrection && entry.corrects && (
                <p className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm text-gray-700">
                    هذه الحركة صحّحت الحركة رقم <b className="font-display">{entry.corrects.lineNumber}</b>. بحذفها يُلغى التصحيح كله: تعود الحركة الأصلية
                    فعّالة ويُحذف قيد إلغائها، فلا يضيع المبلغ من حساب المشترك.
                </p>
            )}

            {action === 'delete' && entry.readingDiscount && (
                <p className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm text-gray-700">
                    لهذه القراءة خصم أسبوعي بقيمة <b className="font-display">{entry.readingDiscount}</b> شيكل. يُحذف معها، لأنه لا يقوم إلا على فاتورة
                    القراءة.
                </p>
            )}

            <p className="rounded-xl bg-brand-500/5 px-4 py-3 text-sm text-brand-700">
                {isSplit
                    ? 'تُحدّث أرصدة جميع المشتركين المتأثرين، ويُحفظ اسم المستخدم والوقت والسبب في سجل التدقيق.'
                    : isReversalDelete
                    ? `ستعود الحركة الأصلية إلى الحالة النشطة، ويُحذف ${reversalNoun} وحده، ثم تُعاد موازنة الحركات اللاحقة.`
                    : isTreeDelete
                      ? 'ستُحذف الحركة الأصلية وجميع حركات الإلغاء أو الإرجاع المرتبطة بها، ثم تُعاد موازنة الحركات اللاحقة.'
                      : 'ستُحذف الحركة نهائيًا من كشف الحساب، ثم تُعاد موازنة الحركات اللاحقة. لا يمكن التراجع.'}
            </p>

            <div>
                <InputLabel htmlFor="correction_notes" value="سبب الحذف النهائي" />
                <textarea
                    id="correction_notes"
                    name="correction_notes"
                    rows={2}
                    required
                    maxLength={1000}
                    className="mt-1 block w-full"
                    placeholder="اكتب سبب الحذف بوضوح"
                    value={form.data.correction_notes}
                    onChange={(e) => form.setData('correction_notes', e.target.value)}
                />
                <InputError message={form.errors.correction_notes} className="mt-2" />
                <p className="mt-2 text-xs text-gray-500">سيُسجَّل اسم المستخدم والوقت والسبب والحركات المتأثرة في سجل التدقيق.</p>
            </div>

            <InputError message={form.errors.action} />
            <BalanceAfter label={`${isSplit ? 'رصيد هذا المشترك' : 'الرصيد'} بعد الحذف وإعادة الموازنة`} balanceAfter={balanceAfter} />
        </FormModal>
    );
}
