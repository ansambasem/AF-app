import { useState } from 'react';
import { router } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatMoney } from '@/lib/format';

export default function ReadingPricingField({ reading, field }) {
    const price = field === 'unit_price';
    const label = price ? 'سعر الكيلو' : 'الحد الأدنى';
    const saved = price ? reading.unitPrice : reading.minimumPayment;
    const allowed = price ? reading.canUpdateUnitPrice : reading.canUpdateMinimumPayment;
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState(String(saved ?? ''));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    function submit(event) {
        event.preventDefault();
        router.put(`/meter-readings/${reading.id}/pricing`, { [field]: value }, {
            preserveScroll: true, preserveState: true,
            onStart: () => setSaving(true), onFinish: () => setSaving(false),
            onSuccess: () => setOpen(false),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'تعذّر حفظ التعديل.'),
        });
    }

    return <>
        <span className="rh-last-value"><span dir="ltr">{saved != null ? formatMoney(saved) : '—'}</span>{allowed && <button type="button" className="rh-last-pencil" aria-label={`تعديل ${label}`} title={`تعديل ${label} لهذه القراءة فقط`} onClick={() => { setValue(String(saved)); setError(null); setOpen(true); }}><Icon name="pencil" /></button>}</span>
        <Modal show={open} onClose={saving ? undefined : () => setOpen(false)} maxWidth="lg" centered>
            <form onSubmit={submit} className="space-y-4 p-6" dir="rtl">
                <h3 className="text-lg font-bold">تعديل {label} لهذه القراءة</h3>
                <p className="text-sm text-gray-600">يُعاد حساب هذه القراءة فقط، وتبقى إعدادات المشترك والفواتير الأقدم كما هي.</p>
                {reading.status === 'approved' && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">تعديل القراءة المعتمدة يلغي مبلغها القديم بقيد عكسي محفوظ في الكشف، ويعيدها للمراجعة حتى اعتمادها مجددًا.</p>}
                <label className="block"><span className="mb-2 block font-semibold">{label} (₪)</span><input type="number" inputMode="decimal" dir="ltr" min="0" max="10000" step="0.01" required autoFocus value={value} onChange={(event) => setValue(event.target.value)} disabled={saving} className="w-full rounded-lg border-gray-300" /></label>
                <InputError message={error} />
                <div className="flex justify-end gap-3"><SecondaryButton onClick={() => setOpen(false)} disabled={saving}>إلغاء</SecondaryButton><PrimaryButton disabled={saving || Number(value) === Number(saved)}>{saving ? 'جارٍ الحفظ…' : 'تأكيد التعديل'}</PrimaryButton></div>
            </form>
        </Modal>
    </>;
}
