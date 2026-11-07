import { useId } from 'react';
import { useForm } from '@inertiajs/react';
import DialogFrame from '@/Components/DialogFrame';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Balance from '@/Components/Balance';
import { balanceWord } from '@/lib/balance';
import DateInput from '@/Components/DateInput';

export default function ArchiveSubscriptionModal({ subscription, today, onClose }) {
    const id = useId();
    const restoring = Boolean(subscription.archivedAt);
    const { data, setData, post, delete: destroy, errors, processing } = useForm({ service_ended_on: today, reason: '', confirmed: false });
    const title = restoring ? 'استعادة الاشتراك من الأرشيف' : 'أرشفة الاشتراك المنتهي';
    function submit(event) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        const url = `/subscriptions/${subscription.id}/archive`;
        restoring ? destroy(url, options) : post(url, options);
    }
    return <DialogFrame show onClose={onClose} title={title} icon={restoring ? 'history' : 'folder'} busy={processing}
        description="يُطبَّق على هذا الاشتراك فقط. تبقى الاشتراكات الأخرى للشخص كما هي."
        footer={<><SecondaryButton onClick={onClose} disabled={processing}>إلغاء</SecondaryButton><PrimaryButton type="submit" form={id} disabled={processing || !data.confirmed || !data.reason.trim()}>{processing ? 'جارٍ الحفظ…' : title}</PrimaryButton></>}>
        <form id={id} onSubmit={submit} className="space-y-5">
            <div className="rounded-card border border-gray-200 bg-gray-50 p-5">
                <p className="font-bold text-gray-900">{subscription.display_name}</p>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-3 text-base"><span>رقم الاشتراك <bdi className="font-display font-semibold">{subscription.account_number}</bdi></span><span>الرصيد الحالي: <Balance value={subscription.outstandingBalance} /> <small>{balanceWord(subscription.outstandingBalance)}</small></span></div>
            </div>
            <p className="text-base leading-7">{restoring ? 'سيعود الاشتراك إلى القائمة بحالة «مفصول». إعادة تشغيل الخدمة تحتاج تعديل الحالة لاحقًا بصلاحيتها.' : 'يبقى كشف الحساب والقراءات والمرفقات محفوظًا. الأرشفة لا تسدّد المستحقات ولا تلغي الحركات السابقة.'}</p>
            {!restoring && <div><InputLabel htmlFor={`${id}-date`} value="تاريخ إنهاء الخدمة" /><DateInput id={`${id}-date`} name="service_ended_on" value={data.service_ended_on} max={today} required className="mt-2" onChange={(value) => setData('service_ended_on', value)} /><InputError message={errors.service_ended_on} className="mt-2" /></div>}
            <div><InputLabel htmlFor={`${id}-reason`} value={restoring ? 'سبب الاستعادة' : 'سبب إنهاء الخدمة والأرشفة'} /><textarea id={`${id}-reason`} value={data.reason} required maxLength={2000} rows={3} className="mt-2 block w-full text-base" placeholder={restoring ? 'اكتب سبب استعادة هذا الاشتراك…' : 'مثال: أنهى المشترك الخدمة نهائيًا وطلب إغلاق ملف الاشتراك.'} onChange={(event) => setData('reason', event.target.value)} /><InputError message={errors.reason} className="mt-2" /></div>
            <label className="flex cursor-pointer items-start gap-3 rounded-control border border-gray-200 p-4 text-base leading-7"><input type="checkbox" checked={data.confirmed} onChange={(event) => setData('confirmed', event.target.checked)} className="mt-1 rounded border-gray-300 text-brand-600" /><span>{restoring ? 'أؤكد استعادة هذا الاشتراك دون إعادة تفعيل الخدمة أو تغيير الرصيد.' : 'أؤكد انتهاء خدمة هذا الاشتراك، وقد راجعت رصيده والقراءات الخاصة به.'}</span></label>
            <InputError message={errors.confirmed} /><InputError message={errors.archive} />
        </form>
    </DialogFrame>;
}
