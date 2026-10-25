import BankReceivedDate from '@/Components/BankReceivedDate';
import { useEffect, useId, useState } from 'react';
import { useForm } from '@inertiajs/react';
import DialogFrame from '@/Components/DialogFrame';
import LatePaymentDetails from '@/Components/LatePaymentDetails';
import FormField from '@/Components/Form/FormField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatMoney } from '@/lib/format';
import { bankAuditReasonChoice, bankAuditReasonPresets, splitAuditTotal } from '@/lib/bankAudit';
import { ledgerOf } from './LedgerColumns';

/** A saved bank review, posted only when the box receives final approval. */
export default function BankTransferAuditForm({ entry, onClose }) {
    const id = useId();
    const ledger = ledgerOf(entry);
    const [review, setReview] = useState({ loading: true, split: null, error: '' });
    const { data, setData, post, transform, processing, errors } = useForm({ bank_amount: entry.audit?.bank_amount ?? ledger.paidAmount, reason: entry.audit?.reason ?? '', parts: [], revision: null });
    const split = review.split;
    const difference = Math.round(((split ? Number(split.total) : Math.abs(Number(entry.transaction.amount))) - Number(data.bank_amount || 0) * Number(entry.transaction.exchange_rate || 1)) * 100) / 100;
    const allocated = data.parts.reduce((total, part) => total + Math.round(Number(part.bank_amount || 0) * 100), 0);
    const allocationMismatch = split && allocated !== Math.round(Number(data.bank_amount || 0) * 100);
    const needsReason = difference !== 0 || Boolean(split && data.parts.some((part, index) => Math.round(Number(part.bank_amount || 0) * 100) !== Math.round(Number(split.parts[index].recorded) * 100)));

    useEffect(() => {
        const controller = new AbortController();
        fetch(entry.audit_url, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (response) => {
                const body = await response.json().catch(() => ({}));
                if (!response.ok || !Object.hasOwn(body, 'split')) throw new Error(Object.values(body.errors || {}).flat()[0] || 'تعذر تحميل مراجعة الحوالة. حدّث الصفحة وأعد فتح النافذة.');
                return body.split;
            })
            .then((loaded) => {
                if (controller.signal.aborted) return;
                if (loaded) setData((current) => ({ ...current, bank_amount: loaded.bank_amount, reason: loaded.reason, revision: loaded.revision, parts: loaded.parts.map((part) => ({ transaction_id: part.transaction_id, bank_amount: part.bank_amount })) }));
                setReview({ loading: false, split: loaded, error: '' });
            })
            .catch((error) => { if (!controller.signal.aborted) setReview({ loading: false, split: null, error: error instanceof TypeError ? 'تعذر الاتصال بالخادم. أعد فتح النافذة للمحاولة مجددًا.' : error.message }); });
        return () => controller.abort();
    }, [entry.audit_url]);

    function submit(event) {
        event.preventDefault();
        if (review.loading || review.error || allocationMismatch) return;
        transform((current) => split ? current : { bank_amount: current.bank_amount, reason: current.reason });
        post(entry.audit_url, { preserveScroll: true, onSuccess: onClose });
    }

    return <DialogFrame show onClose={onClose} busy={processing} title={split ? 'تدقيق الحوالة المقسّمة' : 'تعديل الحوالة البنكية'} description={split ? 'مراجعة الحوالة وتوزيع المبلغ الواصل على جميع المشتركين' : entry.transaction.subscription?.subscription_name || entry.transaction.subscription?.full_name} icon="pencil" maxWidth="2xl" footer={<>
        <SecondaryButton onClick={onClose} disabled={processing}>إلغاء</SecondaryButton>
        <PrimaryButton type="submit" form={`${id}-form`} disabled={processing || review.loading || Boolean(review.error) || allocationMismatch}>{processing ? 'جارٍ الحفظ…' : 'حفظ مراجعة الحوالة'}</PrimaryButton>
    </>}>
        {review.loading ? <div role="status" className="animate-pulse space-y-4 py-4"><p className="text-base text-gray-600">جارٍ تحميل بيانات الحوالة…</p><div className="h-24 rounded-control bg-gray-100" /></div> : review.error ? <InputError message={review.error} /> : <form id={`${id}-form`} onSubmit={submit} className="space-y-5">
            <LatePaymentDetails paidOn={ledger.paidOn} recordedAt={ledger.date} reason={ledger.lateReason} />
            <BankReceivedDate value={ledger.bankReceivedOn} />
            <dl className="flex flex-wrap justify-between gap-4 border-b border-gray-100 pb-4 text-base">
                <div><dt className="text-gray-600">الرقم المرجعي</dt><dd className="mt-1 font-display font-semibold text-gray-900"><bdi dir="ltr">{ledger.referenceNumber || '—'}</bdi></dd></div>
                <div><dt className="text-gray-600">{split ? 'إجمالي الحوالة المسجّل' : 'المبلغ المسجّل'}</dt><dd className="mt-1 font-display text-xl font-bold text-gray-900"><bdi dir="ltr">{formatMoney(split?.total ?? ledger.paidAmount)}</bdi> <span className="text-base font-normal">{ledger.currencyLabel}</span></dd></div>
            </dl>
            <FormField id={`${id}-amount`} label={`${split ? 'إجمالي المبلغ الذي وصل البنك' : 'المبلغ الذي وصل البنك'} (${ledger.currencyLabel})`} required error={errors.bank_amount}><TextInput type="number" min="0" max="1000000" step="0.01" value={data.bank_amount} onChange={(event) => split ? setData(splitAuditTotal(data, split, event.target.value)) : setData('bank_amount', event.target.value)} className="block w-full !text-xl" required autoFocus disabled={processing} /></FormField>
            {split && <div className="space-y-4 rounded-card border border-gray-200 p-4">
                <h3 className="text-base font-bold text-gray-900">توزيع الواصل على المشتركين</h3>
                <p className="text-sm leading-7 text-gray-600">أدخل الواصل لكل حصة. عند عدم الوصول تُصبح جميع الحصص صفرًا؛ لا يُوزّع النقص أو الزيادة تلقائيًا.</p>
                {split.parts.map((part, index) => <div key={part.transaction_id} className="grid items-end gap-3 border-b border-gray-100 pb-4 last:border-0 last:pb-0 sm:grid-cols-2">
                    <div><p className="font-semibold text-gray-900">{part.subscriptionName}</p><p className="mt-1 text-sm text-gray-600">الاشتراك <bdi>{part.accountNumber}</bdi> · الصندوق <bdi>{part.boxNumber}</bdi></p><p className="text-sm text-gray-600">الحصة المسجّلة: <bdi>{formatMoney(part.recorded)}</bdi> شيكل</p></div>
                    <FormField id={`${id}-part-${part.transaction_id}`} label="الواصل لهذه الحصة (شيكل)" required error={errors[`parts.${index}.bank_amount`]}><TextInput type="number" min="0" max="1000000" step="0.01" value={data.parts[index]?.bank_amount ?? ''} onChange={(event) => setData('parts', data.parts.map((item, itemIndex) => itemIndex === index ? { ...item, bank_amount: event.target.value } : item))} required disabled={processing} className="block w-full" /></FormField>
                </div>)}
                <p role="status" className={`text-base font-semibold ${allocationMismatch ? 'text-brand-600' : 'text-gray-900'}`}>مجموع التوزيع: <bdi>{formatMoney(allocated / 100)}</bdi> من <bdi>{formatMoney(data.bank_amount)}</bdi> شيكل</p>
                <InputError message={errors.parts || (allocationMismatch ? 'يجب أن يساوي مجموع التوزيع إجمالي المبلغ الواصل.' : '')} />
            </div>}
            <div>
                <InputLabel htmlFor={`${id}-reason`}>{needsReason ? 'سبب الفرق' : 'ملاحظات (اختياري)'}{needsReason && <span className="text-red-500"> *</span>}</InputLabel>
                <div className="mt-2 overflow-hidden rounded-control border border-gray-200 transition focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20">
                    <select aria-label="توضيحات التدقيق الجاهزة" value={bankAuditReasonPresets.find((option) => option.label === data.reason)?.value ?? ''} onChange={(event) => setData(bankAuditReasonChoice(data, event.target.value))} disabled={processing} className="block w-full rounded-none border-0 border-b border-gray-200 bg-gray-50 py-3 text-base text-gray-700 focus:ring-0">
                        <option value="">توضيح جاهز (اختياري)</option>
                        {bankAuditReasonPresets.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                    <textarea id={`${id}-reason`} rows="3" maxLength="1000" value={data.reason} onChange={(event) => setData('reason', event.target.value)} required={needsReason} disabled={processing} aria-describedby={`${id}-reason-hint`} aria-invalid={Boolean(errors.reason)} placeholder="اكتب توضيحك هنا، أو اختر من القائمة أعلاه…" className="block w-full rounded-none border-0 p-4 text-base leading-8 focus:ring-0" />
                </div>
                <p id={`${id}-reason-hint`} className="mt-2 text-sm leading-7 text-gray-600">يمكنك تعديل التوضيح المختار أو كتابة نص خاص.</p>
                <InputError message={errors.reason} className="mt-1" />
            </div>
            <div role="status" aria-live="polite" className="rounded-control bg-brand-500/5 p-4">
                <p className="text-base text-gray-600">عند اعتماد الصندوق</p>
                <p className="mt-1 font-semibold text-gray-900">{difference > 0 ? (split ? 'إجمالي ما يُحمّل على المشتركين' : 'يُحمّل على المشترك') : difference < 0 ? (split ? 'إجمالي ما يُضاف لصالح المشتركين' : 'يُضاف لصالح المشترك') : needsReason ? 'توزيع مختلف بين الحصص · راجع أثر كل حصة' : 'مطابقة · لا يوجد فرق مالي'}</p>
                {difference !== 0 && <p className="mt-2 font-display text-2xl font-bold text-gray-900"><bdi dir="ltr">{formatMoney(Math.abs(difference))}</bdi> <span className="text-base font-normal">شيكل</span></p>}
            </div>
            <p className="text-base leading-8 text-gray-600">{split ? 'تُحفظ مراجعة جميع الحصص معًا كمسودة. عند اعتماد أحد صناديق الحوالة يثبت أثرها المالي لجميع المشتركين مرة واحدة.' : 'تُحفظ كمسودة. يثبت أثرها المالي عند اعتماد الصندوق.'}</p>
            <InputError message={errors.action || errors.audit || errors.revision} />
        </form>}
    </DialogFrame>;
}
