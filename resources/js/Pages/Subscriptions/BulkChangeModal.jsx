import { useCallback, useId, useRef, useState } from 'react';
import { router, useHttp, usePage } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import ChoiceChips from '@/Components/ChoiceChips';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import SearchableSelect from '@/Components/SearchableSelect';
import { normalizeDecimalInput } from '@/lib/format';

const TITLES = {
    minimum_charge: { title: 'تغيير الحد الأدنى', icon: 'wallet' },
    status: { title: 'تغيير الحالة', icon: 'flag' },
    meter_box_id: { title: 'نقل إلى طبلون آخر', icon: 'grid' },
};

const MINIMUM_MODES = [
    { value: 'amount', label: 'مبلغ محدد', icon: 'dollar' },
    { value: 'circuit_breaker', label: 'حد القاطع لكل مشترك', icon: 'bolt' },
];

const STATUS_ICONS = { active: 'check', suspended: 'clock', disconnected: 'power' };

/**
 * Set one field — the weekly minimum charge or the status — for every
 * chosen subscription (`selection`: the ticked ids, or every match of the
 * list's filters), after saying how many it reaches. The change is kept
 * in the bulk changes log and can be undone from there.
 */
export default function BulkChangeModal({ field, selection, count, statusOptions, selectionLabel = '', onClose, onDone }) {
    const id = useId();
    const { errors } = usePage().props;
    const [mode, setMode] = useState('amount');
    const [value, setValue] = useState(field === 'status' ? (statusOptions[0]?.value ?? '') : '');
    const [saving, setSaving] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    const [selectedBox, setSelectedBox] = useState(null);
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;
    const loadMeterBoxes = useCallback(async (search) => {
        const response = await httpRef.current.get(`/meter-boxes/options?${new URLSearchParams({ search })}`);
        return { options: response.data, hasMore: response.hasMore };
    }, []);
    const { title, icon } = TITLES[field];
    const needsValue = field !== 'minimum_charge' || mode === 'amount';
    const validValue = field !== 'minimum_charge' || mode !== 'amount' || (/^\d+(\.\d{1,2})?$/.test(value) && Number(value) <= 100000);

    function apply(event) {
        event.preventDefault();
        if (saving || !validValue || (needsValue && value === '')) {
            return;
        }
        if (!reviewing) {
            setReviewing(true);
            return;
        }
        setSaving(true);
        router.post(
            '/subscriptions/bulk-changes',
            { field, mode: field === 'minimum_charge' ? mode : undefined, value: needsValue ? value : undefined, expected_count: count, ...selection },
            { preserveScroll: true, onSuccess: onDone, onError: () => setReviewing(false), onFinish: () => setSaving(false) },
        );
    }

    return (
        <Modal show onClose={() => { if (!saving) onClose(); }} maxWidth="lg" centered panelClassName={field === 'meter_box_id' ? '!overflow-visible' : ''}>
            <form onSubmit={apply} role="dialog" aria-modal="true" aria-labelledby={`${id}-title`} aria-describedby={`${id}-reach`}>
                <div className="flex items-start gap-4 px-7 pb-2 pt-7">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600">
                        <Icon name={icon} strokeWidth={2} />
                    </span>
                    <div className="min-w-0 pt-0.5">
                        <h3 id={`${id}-title`} className="text-lg font-bold text-gray-900">
                            {title}
                        </h3>
                        <p id={`${id}-reach`} className="mt-1.5 text-sm leading-6 text-gray-600">
                            يُطبَّق على <b className="text-gray-900">{count.toLocaleString('en')}</b> اشتراك
                            {selection.all ? ' (كل النتائج المطابقة للبحث والفلاتر)' : ' محدد'}. من كانت قيمته نفسها لا يتغير.
                        </p>
                    </div>
                </div>

                <div className="space-y-4 px-7 py-5">
                    {selectionLabel && <p className="rounded-control border border-brand-500/15 bg-brand-500/5 px-4 py-3 text-sm text-gray-700">{selectionLabel}</p>}
                    {reviewing ? (
                        <div className="rounded-2xl border border-gray-200 bg-gray-50 p-5">
                            <h4 className="text-base font-bold text-gray-900">مراجعة التعديل</h4>
                            <p className="mt-3 text-base leading-8 text-gray-700">{field === 'meter_box_id' ? `الطبلون الجديد: ${selectedBox?.label}` : field === 'minimum_charge' ? mode === 'amount' ? `الحد الأدنى الأسبوعي الجديد: ${value} شيكل` : 'الحد الأدنى الجديد: حد القاطع الخاص بكل اشتراك' : `الحالة الجديدة: ${statusOptions.find((option) => option.value === value)?.label}`}</p>
                            {field === 'meter_box_id' && <p className="mt-1 text-sm text-gray-600">{selectedBox?.hint}</p>}
                            <p className="mt-2 text-sm leading-7 text-gray-600">سيُحفظ التعديل في سجل التعديلات الجماعية، مع القيمة السابقة والجديدة لكل اشتراك.{field === 'minimum_charge' && ' الفواتير والحركات السابقة تبقى كما هي.'}{field === 'meter_box_id' && ' الأرصدة والقراءات والحركات المالية تبقى كما هي.'}</p>
                        </div>
                    ) : field === 'meter_box_id' ? (
                        <div className="space-y-3">
                            <label htmlFor={`${id}-box`} className="block text-sm font-semibold text-gray-700">الطبلون الجديد</label>
                            <SearchableSelect
                                id={`${id}-box`}
                                value={value}
                                onChange={(next, option) => { setValue(next); setSelectedBox(option ?? null); }}
                                options={selectedBox ? [selectedBox] : []}
                                loadOptions={loadMeterBoxes}
                                placeholder="اختر الطبلون الجديد"
                                searchPlaceholder="ابحث باسم الطبلون أو رقمه..."
                            />
                            <InputError message={errors.value} />
                            <p className="text-sm leading-7 text-gray-600">اختر طبلونًا من فرع الاشتراكات المحددة. الاشتراكات الموجودة فيه بالفعل تبقى كما هي.</p>
                        </div>
                    ) : field === 'minimum_charge' ? (
                        <>
                            <ChoiceChips options={MINIMUM_MODES} value={mode} onChange={setMode} label="طريقة تحديد الحد الأدنى" />
                            {mode === 'amount' ? (
                                <div>
                                    <label htmlFor={`${id}-value`} className="mb-1.5 block text-sm font-semibold text-gray-700">
                                        الحد الأدنى الأسبوعي الجديد (شيكل)
                                    </label>
                                    <input
                                        id={`${id}-value`}
                                        type="text"
                                        inputMode="decimal"
                                        value={value}
                                        onChange={(e) => setValue(normalizeDecimalInput(e.target.value))}
                                        className="block w-full text-sm"
                                        autoFocus
                                        aria-invalid={errors.value ? true : undefined}
                                    />
                                    <InputError message={errors.value} className="mt-1" />
                                </div>
                            ) : (
                                <p className="rounded-control bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                    يأخذ كل مشترك الحد الأدنى لقاطعه. من ليس له قاطع لا يتغير.
                                </p>
                            )}
                            <p className="flex items-start gap-1.5 text-xs text-gray-500">
                                <Icon name="info" className="mt-0.5 h-4 w-4 shrink-0" />
                                يسري على القراءات القادمة فقط؛ القراءات المسجلة تبقى كما هي.
                            </p>
                        </>
                    ) : (
                        <ChoiceChips
                            options={statusOptions.map((option) => ({ ...option, icon: STATUS_ICONS[option.value] }))}
                            value={value}
                            onChange={setValue}
                            label="الحالة الجديدة"
                        />
                    )}
                    <InputError message={errors.ids} />
                    <InputError message={errors.expected_count} />
                </div>

                <div className="flex flex-wrap items-center justify-end gap-3 rounded-b-panel border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton disabled={saving} onClick={onClose}>إلغاء</SecondaryButton>
                    {reviewing && <SecondaryButton disabled={saving} onClick={() => setReviewing(false)}>تعديل القيمة</SecondaryButton>}
                    <PrimaryButton type="submit" disabled={saving || !validValue || (needsValue && value === '')} aria-busy={saving}>
                        <Icon name="check" className="h-4 w-4" />
                        {saving ? 'جارٍ التطبيق...' : reviewing ? `تأكيد التطبيق على ${count.toLocaleString('en')} اشتراك` : 'مراجعة التعديل'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
