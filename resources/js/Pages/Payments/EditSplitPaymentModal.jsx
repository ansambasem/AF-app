import { BankReceivedDateField } from '@/Components/BankReceivedDate';
import { useEffect, useState } from 'react';
import { useForm, useHttp } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatMoney } from '@/lib/format';

export function SplitPaymentEditor({ split, onClose, transferBanks = [], senderBanks = [] }) {
    const form = useForm({
        bank_name: split.bank_name ?? '',
        bank_received_on: split.bank_received_on ?? '',
        sender_bank_name: split.sender_bank_name ?? '',
        sender_name: split.sender_name ?? '',
        reference_number: split.reference_number ?? '',
        notes: split.notes ?? '',
        revision: split.revision,
        amendment_reason: '',
    });
    const editForm = { ...form, isEdit: true, save: (options) => form.patch(`/split-payments/${split.id}`, options) };
    const inputClass = 'mt-1 block w-full';

    return (
        <FormModal
            show
            onClose={onClose}
            form={editForm}
            title="تعديل الحوالة المقسّمة"
            icon="pencil"
            headerTone="blue"
            maxWidth="3xl"
            bodyClassName="space-y-5"
            action={{ submitLabel: 'حفظ بيانات الحوالة', title: 'تعديل بيانات الحوالة لجميع المشتركين؟', confirmLabel: 'نعم، احفظ التعديل', icon: 'pencil' }}
            saveConfirmMessage="ستُحدّث بيانات الحوالة لجميع حصص المشتركين مع تسجيل القيم السابقة والجديدة واسم المستخدم والسبب. مبلغ الحوالة والحصص والأرصدة لا تتغيّر، ولا تُرجع أي دفعة."
        >
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-5 py-4">
                <div>
                    <p className="flex items-center gap-2 text-sm font-semibold text-gray-600">
                        <Icon name="lock" className="h-4 w-4" />مبلغ الحوالة ثابت
                    </p>
                    <p className="mt-1 text-2xl font-bold text-gray-900">
                        <bdi>{formatMoney(split.total_amount)}</bdi> <span className="text-sm font-normal">شيكل</span>
                    </p>
                </div>
                <p className="text-sm text-gray-600">حوالة واحدة موزّعة على <b className="text-gray-900">{split.parts.length}</b> مشتركين</p>
            </div>
            <p className="rounded-xl border border-blue-500/20 bg-blue-500/5 px-4 py-3 text-sm leading-7 text-gray-700">يظهر التعديل في جميع حصص الحوالة. تبقى مبالغ المشتركين وأرصدتهم كما هي، دون إرجاع الحوالة أو تسجيل دفعة جديدة.</p>
            <BankReceivedDateField value={form.data.bank_received_on} onChange={(value) => form.setData('bank_received_on', value)} error={form.errors.bank_received_on} />
            <div className="grid gap-4 sm:grid-cols-2">
                {[['sender_name', 'اسم المرسل'], ['reference_number', 'الرقم المرجعي']].map(([field, label]) => (
                    <div key={field}>
                        <InputLabel htmlFor={`split_${field}`} value={label} />
                        <input
                            id={`split_${field}`}
                            name={field}
                            required
                            maxLength={field === 'reference_number' ? 100 : 255}
                            dir={field === 'reference_number' ? 'ltr' : undefined}
                            className={inputClass}
                            value={form.data[field]}
                            onChange={(event) => form.setData(field, event.target.value)}
                        />
                        <InputError message={form.errors[field]} className="mt-2" />
                    </div>
                ))}
                {[['bank_name', 'البنك المستلم', transferBanks], ['sender_bank_name', 'البنك المرسل', senderBanks]].map(([field, label, banks]) => (
                    <div key={field}>
                        <InputLabel htmlFor={`split_${field}`} value={label} />
                        <select
                            id={`split_${field}`}
                            name={field}
                            required={field === 'bank_name'}
                            className={inputClass}
                            value={form.data[field]}
                            onChange={(event) => form.setData(field, event.target.value)}
                        >
                            <option value="">{field === 'bank_name' ? 'اختر البنك المستلم' : 'غير محدد'}</option>
                            {banks.map((bank) => <option key={bank} value={bank}>{bank}</option>)}
                        </select>
                        <InputError message={form.errors[field]} className="mt-2" />
                    </div>
                ))}
            </div>
            <div className="overflow-hidden rounded-2xl border border-gray-200">
                <h4 className="flex items-center gap-2 bg-gray-50 px-4 py-3 text-base font-semibold text-gray-900"><Icon name="lock" className="h-4 w-4 text-gray-500" />حصص المشتركين — للعرض فقط</h4>
                <table className="w-full text-start text-sm">
                    <thead>
                        <tr className="border-t border-gray-100 text-gray-500">
                            <th className="px-4 py-2 text-start font-medium">المشترك</th>
                            <th className="px-4 py-2 text-end font-medium">حصته من الحوالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        {split.parts.map((part) => (
                            <tr key={part.id} className="border-t border-gray-100">
                                <td className="px-4 py-3 font-semibold text-gray-900">{part.subscriptionName}</td>
                                <td className="px-4 py-3 text-end text-gray-900">
                                    <bdi className="font-display font-semibold">{formatMoney(part.amount)}</bdi> شيكل
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {[['notes', 'ملاحظات', false], ['amendment_reason', 'سبب تعديل بيانات الحوالة', true]].map(([field, label, required]) => (
                <div key={field}>
                    <InputLabel htmlFor={`split_${field}`} value={label} />
                    <textarea
                        id={`split_${field}`}
                        name={field}
                        rows={2}
                        maxLength={1000}
                        required={required}
                        className={inputClass}
                        value={form.data[field]}
                        onChange={(event) => form.setData(field, event.target.value)}
                    />
                    <InputError message={form.errors[field]} className="mt-2" />
                </div>
            ))}
            <InputError message={form.errors.action ?? form.errors.details ?? form.errors.revision} />
            <p className="text-sm text-gray-500">يُحفظ من عدّل البيانات، ووقت التعديل، والقيم السابقة والجديدة في سجل كل حصة.</p>
        </FormModal>
    );
}

export default function EditSplitPaymentModal({ id, onClose, transferBanks, senderBanks }) {
    const http = useHttp();
    const [split, setSplit] = useState(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        let current = true;
        http.get(`/split-payments/${id}/edit`).then((loaded) => current && setSplit(loaded)).catch(() => current && setFailed(true));
        return () => { current = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);
    if (split) {
        return <SplitPaymentEditor split={split} onClose={onClose} transferBanks={transferBanks} senderBanks={senderBanks} />;
    }
    return (
        <Modal show onClose={onClose} maxWidth="3xl">
            <div className="space-y-5 p-7">
                <h3 className="text-xl font-bold text-gray-900">تعديل الحوالة المقسّمة</h3>
                {failed ? (
                    <p role="alert" className="text-base leading-8 text-red-700">تعذّر فتح التعديل. تأكد أن جميع حصص الحوالة سارية وفي صناديق مفتوحة وأن لديك الصلاحية.</p>
                ) : (
                    <div role="status" className="animate-pulse space-y-4">
                        <p className="text-gray-600">جارٍ تحميل بيانات الحوالة وحصص المشتركين…</p>
                        <div className="h-24 rounded-xl bg-gray-100" />
                        <div className="h-32 rounded-xl bg-gray-100" />
                    </div>
                )}
                <SecondaryButton onClick={onClose}>إغلاق</SecondaryButton>
            </div>
        </Modal>
    );
}
