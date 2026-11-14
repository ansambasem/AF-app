import { useForm } from '@inertiajs/react';
import DialogFrame from '@/Components/DialogFrame';
import HoldToConfirm from '@/Components/HoldToConfirm';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import { formatMoney } from '@/lib/format';

/** Final financial approval, scoped to the full box or branch week shown here. */
export default function AuditApprovalDialog({ title, scope, summary, url, payload = {}, onClose }) {
    const form = useForm({ ...payload, confirmed: false });

    return <DialogFrame show onClose={onClose} busy={form.processing} title={title} description={scope} icon="check" maxWidth="3xl" role="alertdialog" footer={<SecondaryButton disabled={form.processing} onClick={onClose}>العودة للمراجعة</SecondaryButton>}>
        <dl className="grid grid-cols-2 gap-x-5 gap-y-4 border-b border-gray-100 pb-5">
            {[['الصناديق', summary.boxes], ['الحوالات', summary.transfers], ['حوالات فيها فروق', summary.exceptions], ['ستُعتمد مطابقة تلقائيًا', summary.automaticMatches]].map(([label, value]) => <div key={label}><dt className="text-base text-gray-600">{label}</dt><dd className="mt-1 font-display text-2xl font-bold text-gray-900">{value}</dd></div>)}
        </dl>
        <section aria-label="الأثر المالي" className="space-y-3">
            <h3 className="text-lg font-bold text-gray-900">الأثر على حسابات المشتركين</h3>
            <dl className="divide-y divide-gray-200 rounded-control bg-gray-50 px-5">
                <div className="flex flex-wrap items-center justify-between gap-2 py-4"><dt className="text-base text-gray-700">تحميل على المشتركين</dt><dd className="font-display text-2xl font-bold text-red-700 dark:text-red-300"><bdi dir="ltr">{formatMoney(summary.debit)}</bdi> <span className="text-base font-normal">₪</span></dd></div>
                <div className="flex flex-wrap items-center justify-between gap-2 py-4"><dt className="text-base text-gray-700">إضافة لصالح المشتركين</dt><dd className="font-display text-2xl font-bold text-emerald-700 dark:text-emerald-300"><bdi dir="ltr">{formatMoney(summary.credit)}</bdi> <span className="text-base font-normal">₪</span></dd></div>
            </dl>
        </section>
        <p className="rounded-control bg-amber-500/10 px-4 py-3 font-semibold text-amber-900 dark:text-amber-300">الاعتماد نهائي، ويقفل قرارات التدقيق.</p>
        <details className="text-base">
            <summary className="cursor-pointer font-semibold text-gray-700">ماذا يشمل الاعتماد؟</summary>
            <ul className="mt-3 list-inside list-disc space-y-2">
                <li>كل الصناديق الموضحة أعلاه، بما فيها المخفية بالبحث أو الفلاتر.</li>
                <li>ترحيل الفروق المسجّلة إلى حسابات المشتركين.</li>
                <li>اعتماد بقية الحوالات كمطابقة لكشف البنك.</li>
            </ul>
        </details>
        <label className="flex cursor-pointer items-start gap-3 rounded-control border border-gray-200 p-4 text-base leading-8 text-gray-900"><input type="checkbox" checked={form.data.confirmed} onChange={(event) => form.setData('confirmed', event.target.checked)} disabled={form.processing} className="mt-2 h-5 w-5 shrink-0 rounded border-gray-300 text-brand-600" /><span>راجعت جميع الحوالات وسجّلت الفروق، والبقية مطابقة لكشف البنك.</span></label>
        <InputError message={form.errors.confirmed || form.errors.action || form.errors.snapshot || form.errors.box_ids || form.errors.week || form.errors.branch_id} />
        <HoldToConfirm count={summary.boxes} noun="الصناديق" tone="primary" icon="check" disabled={!form.data.confirmed || summary.boxes === 0} busy={form.processing} label="اضغط ٣ ثوانٍ لاعتماد التدقيق" confirmLabel="اعتماد التدقيق نهائيًا" onConfirm={() => form.post(url, { preserveScroll: true, onSuccess: onClose })} />
    </DialogFrame>;
}
