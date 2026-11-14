import { BankReceivedDateField } from '@/Components/BankReceivedDate';
import { useForm } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { describeBalance } from '@/lib/accountStatement';
import Balance from '@/Components/Balance';
import { formatAmount } from '@/lib/currency';
import { BalanceAfter } from './AccountSummary';
import EditSplitPaymentModal from '@/Pages/Payments/EditSplitPaymentModal';

const inputClass = 'mt-2 block w-full';

function LockedValue({ label, children }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
            <span className="flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                <Icon name="lock" className="h-3.5 w-3.5" />
                {label}
            </span>
            <b className="mt-1 block text-sm text-gray-900">{children}</b>
        </div>
    );
}

/** Amend a payment's descriptive details without changing its money or balance. */
export default function AmendTransactionModal({ onClose, subscription, balance, entry, transferBanks, senderBanks }) {
    if (entry.splitPayment) {
        return <EditSplitPaymentModal id={entry.splitPayment.id} onClose={onClose} transferBanks={transferBanks} senderBanks={senderBanks} />;
    }

    return <SinglePaymentEditor onClose={onClose} subscription={subscription} balance={balance} entry={entry} transferBanks={transferBanks} senderBanks={senderBanks} />;
}

function SinglePaymentEditor({ onClose, subscription, balance, entry, transferBanks, senderBanks }) {
    const throughBank = entry.paymentMethod === 'bank_transfer' || entry.paymentMethod === 'cheque';
    const form = useForm({
        ...(throughBank
            ? {
                  bank_name: entry.recorded?.bank_name ?? entry.bankName ?? '',
                  bank_received_on: entry.bankReceivedOn ?? '',
                  sender_bank_name: entry.recorded?.sender_bank_name ?? entry.senderBankName ?? '',
                  sender_name: entry.recorded?.sender_name ?? entry.senderName ?? '',
                  reference_number: entry.recorded?.reference_number ?? entry.referenceNumber ?? '',
              }
            : {}),
        action: 'edit_metadata',
        notes: entry.recorded?.notes ?? entry.details ?? '',
        amendment_reason: '',
    });
    const amendForm = {
        ...form,
        isEdit: true,
        save: (options) => form.post(`/subscriptions/${subscription.id}/transactions/${entry.id}/actions`, options),
    };

    return (
        <FormModal
            show
            onClose={onClose}
            form={amendForm}
            title="تعديل بيانات الحركة"
            icon="pencil"
            headerTone="blue"
            maxWidth="2xl"
            bodyClassName="space-y-5"
            action={{ submitLabel: 'حفظ التعديل', title: 'حفظ تعديل البيانات؟', confirmLabel: 'نعم، احفظ التعديل', icon: 'pencil' }}
            saveConfirmMessage="ستتغير البيانات الوصفية فقط، ويبقى المبلغ وطريقة الدفع والرصيد كما هي."
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <LockedValue label="المبلغ">
                    {formatAmount(entry.amount)} {entry.currencyLabel}
                </LockedValue>
                <LockedValue label="طريقة الدفع">{entry.paymentMethodLabel}</LockedValue>
                <LockedValue label="الرصيد قبل وبعد"><Balance value={balance} /></LockedValue>
            </div>

            {throughBank && (
                <div className="grid items-start gap-x-5 gap-y-5 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="amend_bank_name" value="البنك المستلم" />
                        <select
                            id="amend_bank_name"
                            name="bank_name"
                            required
                            className={inputClass}
                            value={form.data.bank_name}
                            onChange={(event) => form.setData('bank_name', event.target.value)}
                        >
                            <option value="">اختر البنك أو المحفظة</option>
                            {transferBanks.map((bank) => (
                                <option key={bank} value={bank}>
                                    {bank}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.bank_name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="amend_sender_bank_name" value="البنك المرسل" />
                        <select
                            id="amend_sender_bank_name"
                            name="sender_bank_name"
                            className={inputClass}
                            value={form.data.sender_bank_name}
                            onChange={(event) => form.setData('sender_bank_name', event.target.value)}
                        >
                            <option value="">غير محدد</option>
                            {senderBanks.map((bank) => (
                                <option key={bank} value={bank}>
                                    {bank}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.sender_bank_name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="amend_sender_name" value="اسم المرسل" />
                        <input
                            id="amend_sender_name"
                            name="sender_name"
                            type="text"
                            maxLength={255}
                            className={inputClass}
                            value={form.data.sender_name}
                            onChange={(event) => form.setData('sender_name', event.target.value)}
                        />
                        <InputError message={form.errors.sender_name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="amend_reference_number" value="الرقم المرجعي" />
                        <input
                            id="amend_reference_number"
                            type="text"
                            dir="ltr"
                            name="reference_number"
                            required
                            maxLength={100}
                            className={`${inputClass} text-end`}
                            value={form.data.reference_number}
                            onChange={(event) => form.setData('reference_number', event.target.value)}
                            placeholder="—"
                        />
                        <InputError message={form.errors.reference_number} className="mt-2" />
                    </div>
                    <div className="sm:col-span-2">
                        <BankReceivedDateField value={form.data.bank_received_on} onChange={(value) => form.setData('bank_received_on', value)} error={form.errors.bank_received_on} />
                    </div>
                </div>
            )}

            <div>
                <InputLabel htmlFor="amend_notes" value="ملاحظات" />
                <textarea
                    id="amend_notes"
                    name="notes"
                    rows={2}
                    maxLength={1000}
                    className={inputClass}
                    value={form.data.notes}
                    onChange={(event) => form.setData('notes', event.target.value)}
                />
                <InputError message={form.errors.notes} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="amendment_reason" value="سبب التعديل" />
                <textarea
                    id="amendment_reason"
                    name="amendment_reason"
                    rows={2}
                    required
                    maxLength={1000}
                    className={inputClass}
                    placeholder="مثال: اختير البنك الخطأ عند تسجيل الدفعة"
                    value={form.data.amendment_reason}
                    onChange={(event) => form.setData('amendment_reason', event.target.value)}
                />
                <InputError message={form.errors.amendment_reason ?? form.errors.details} className="mt-2" />
            </div>

            <BalanceAfter label="الرصيد بعد التعديل (بدون تغيير)" balanceAfter={describeBalance(balance)} />
        </FormModal>
    );
}
