import { useCallback, useEffect, useRef, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import Affix from '@/Components/Affix';
import ChoiceChips from '@/Components/ChoiceChips';
import ConfirmDialog from '@/Components/ConfirmDialog';
import FormField from '@/Components/Form/FormField';
import FormPreview from '@/Components/Form/FormPreview';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import SearchableSelect from '@/Components/SearchableSelect';
import Switch from '@/Components/Switch';
import TextInput from '@/Components/TextInput';
import { formatAmount } from '@/lib/currency';
import { initials } from '@/lib/format';
import { plainDigits } from '@/lib/formValidation';
import DateInput from '@/Components/DateInput';

const STATUS_OPTIONS = [
    { value: 'active', label: 'نشط', dot: 'green' },
    { value: 'suspended', label: 'قيد الانتظار', dot: 'amber' },
    { value: 'disconnected', label: 'مفصول', dot: 'gray' },
];

const ACCOUNTING_TYPE_OPTIONS = [
    { value: 'weekly', label: 'أسبوعي' },
    { value: 'monthly', label: 'شهري' },
];

const STATUS_DOTS = {
    active: 'bg-emerald-500',
    suspended: 'bg-amber-500',
    disconnected: 'bg-gray-400',
};

/**
 * The fields the server requires of every subscription; a Super Admin also
 * picks the branch, and an active subscription needs their starting reading.
 */
const REQUIRED_FIELDS = ['full_name', 'status', 'tariff_id', 'minimum_charge'];

// A value the form shows but never lets the user edit directly (it's
// derived from another selection, like the branch's area).
function FieldLock() {
    return (
        <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center text-gray-400" aria-hidden="true">
            <Icon name="lock" className="h-4 w-4" />
        </span>
    );
}

function ReadOnlyField({ id, label, value, dir }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="relative mt-1" dir={dir}>
                <TextInput id={id} readOnly value={value} title="للقراءة فقط" className="w-full bg-gray-50 pe-10 text-gray-600" />
                <FieldLock />
            </div>
        </div>
    );
}

/**
 * The dark card at the top of the form: the subscription as they're being
 * typed in (name, phone, status, subscription type and breaker), and how
 * many of the required fields are filled.
 */
function SubscriptionPreview({ data, tariff, circuitBreaker, filled, total }) {
    const kilowattPrice = data.kilowatt_price !== '' ? data.kilowatt_price : tariff?.rate;
    const name = data.subscription_name.trim() || data.full_name.trim();
    const phone = String(data.subscription_phone || data.phone || '').trim();

    return (
        <FormPreview
            avatar={name ? initials(name) : <Icon name="user" className="h-6 w-6 text-white/70" />}
            dotClass={STATUS_DOTS[data.status]}
            title={name || 'مشترك جديد'}
            subtitle={phone || '05— ——— ——'}
            subtitleDir="ltr"
            chips={[tariff && `${tariff.categoryLabel} · ${formatAmount(kilowattPrice)} ش/ك.و`, circuitBreaker && `قاطع ${circuitBreaker.ampere} أمبير`].filter(Boolean)}
            filled={filled}
            total={total}
        />
    );
}

/**
 * The form's starting values: the subscription's own when editing,
 * otherwise blank. New subscriptions start out waiting (قيد الانتظار).
 */
export function subscriptionFormData(subscription, sourceSubscription = null) {
    const data = {
        full_name: subscription?.full_name ?? '',
        subscription_name: subscription?.subscription_name ?? subscription?.full_name ?? '',
        national_id: subscription?.national_id ?? '',
        phone: subscription?.phone ?? '',
        subscription_phone: subscription?.subscription_phone ?? subscription?.phone ?? '',
        address: subscription?.address ?? '',
        meter_box_id: subscription?.meter_box_id ?? '',
        tariff_id: subscription?.tariff_id ?? '',
        tariff_segment_id: subscription?.tariff_segment_id ?? '',
        status: subscription?.status ?? 'suspended',
        accounting_type: subscription?.accounting_type ?? 'weekly',
        branch_id: subscription?.branch_id ?? '',
        circuit_breaker_id: subscription?.circuit_breaker_id ?? '',
        minimum_charge: subscription?.minimum_charge != null ? Number(subscription.minimum_charge) : '',
        // Empty means the subscriber pays their tariff's price.
        kilowatt_price: subscription?.kilowatt_price != null ? Number(subscription.kilowatt_price) : '',
        initial_reading: subscription?.initial_reading ?? '',
        subscription_fee: subscription?.subscription_fee ?? '',
        // New subscriptions, and existing ones whose fee is not on the account yet, can be charged it.
        ...(subscription?.subscription_fee_charged ? {} : { charge_subscription_fee: false }),
        subscription_date: subscription?.subscription_date ?? '',
        // Only read by the form: it is not a field the server takes.
        has_been_active: subscription?.has_been_active ?? false,
        subscription_fee_charged: subscription?.subscription_fee_charged ?? false,
        notes: subscription?.notes ?? '',
    };

    if (sourceSubscription) {
        Object.assign(data, {
            source_subscription_id: sourceSubscription.id,
            full_name: sourceSubscription.full_name,
            subscription_name: sourceSubscription.display_name ?? sourceSubscription.full_name,
            national_id: sourceSubscription.national_id,
            phone: sourceSubscription.phone ?? '',
            subscription_phone: sourceSubscription.contact_phone ?? sourceSubscription.phone ?? '',
            address: sourceSubscription.address ?? '',
            branch_id: sourceSubscription.branch_id,
        });
    }

    return data;
}

export default function SubscriptionForm({
    data,
    setData,
    errors,
    clearErrors,
    meterBox = null,
    tariffs,
    segments = [],
    circuitBreakers,
    branches,
    subAreas,
    canChooseBranch,
    currentBranchAreaId,
    canEditMinimumCharge,
    canEditKilowattPrice = false,
    sharedPersonalDetails = false,
    isEdit = false,
    subscriptionCount = 1,
}) {
    // What the subscription had when the form opened, which choosing the old status again brings back.
    const original = useRef(data).current;
    const independentContactDetails = sharedPersonalDetails || isEdit;
    const nameField = independentContactDetails ? 'subscription_name' : 'full_name';
    const phoneField = independentContactDetails ? 'subscription_phone' : 'phone';
    const [minimumChargeUnlocked, setMinimumChargeUnlocked] = useState(false);
    const [confirmingMinimumChargeUnlock, setConfirmingMinimumChargeUnlock] = useState(false);

    const [kilowattPriceUnlocked, setKilowattPriceUnlocked] = useState(false);
    const [confirmingKilowattPriceUnlock, setConfirmingKilowattPriceUnlock] = useState(false);

    function unlockMinimumCharge() {
        setConfirmingMinimumChargeUnlock(false);
        setMinimumChargeUnlocked(true);
    }
    const selectedBranch = canChooseBranch ? branches.find((branch) => String(branch.id) === String(data.branch_id)) : null;

    // The branch's area scopes the selectable sub-areas ("منطقة 2"). A Super
    // Admin also sees the chosen branch's area as a read-only value; branch
    // staff do not need to see or choose the area already fixed by their branch.
    const resolvedAreaId = canChooseBranch ? (selectedBranch?.area_id ?? '') : (currentBranchAreaId ?? '');
    const resolvedAreaName = selectedBranch?.area?.name ?? '';

    // The meter box chosen (the subscription's own when editing, which the page carries) and its sub-area:
    // seeding the sub-area from it shows an edited subscription's box pre-selected instead of
    // hiding it behind an unmade sub-area choice. The other boxes are found as the user types.
    const [selectedBox, setSelectedBox] = useState(meterBox);
    const [subAreaId, setSubAreaId] = useState(() => (meterBox?.sub_area_id ? String(meterBox.sub_area_id) : ''));

    const subAreasInArea = resolvedAreaId ? subAreas.filter((subArea) => String(subArea.area_id) === String(resolvedAreaId)) : [];

    // A Super Admin's boxes are those of the branch chosen; a branch's staff already get only their own.
    const boxBranchId = canChooseBranch ? data.branch_id : '';
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;
    const loadMeterBoxes = useCallback(
        async (search) => {
            const query = new URLSearchParams({ search, ...(boxBranchId ? { branch_id: boxBranchId } : {}), ...(subAreaId ? { sub_area_id: subAreaId } : {}) });
            const response = await httpRef.current.get(`/meter-boxes/options?${query}`);

            return { options: response.data, hasMore: response.hasMore };
        },
        [boxBranchId, subAreaId],
    );

    const showMeterBoxField = !canChooseBranch || Boolean(data.branch_id);

    useEffect(() => {
        if (!isEdit && !subAreaId && subAreasInArea.length === 1) {
            setSubAreaId(String(subAreasInArea[0].id));
        }
    }, [isEdit, resolvedAreaId, subAreaId, subAreasInArea]);

    const selectedTariff = tariffs.find((tariff) => String(tariff.id) === String(data.tariff_id));
    const selectedCircuitBreaker = circuitBreakers.find((circuitBreaker) => String(circuitBreaker.id) === String(data.circuit_breaker_id));
    const minimumChargeLocked = !canEditMinimumCharge || !minimumChargeUnlocked;
    const tariffRate = selectedTariff ? Number(selectedTariff.rate) : null;
    const kilowattPriceLocked = !canEditKilowattPrice || !kilowattPriceUnlocked;
    const hasOwnKilowattPrice = data.kilowatt_price !== '';
    // An edited subscriber's own price does not fit another tariff, so choosing one drops it.
    const kilowattPriceDropped = isEdit && original.kilowatt_price !== '' && !hasOwnKilowattPrice && !kilowattPriceUnlocked;
    // A fee already on the account is that line's amount: it is changed from the transactions, not here.
    const subscriptionFeeCharged = original.subscription_fee_charged;
    const subscriptionFeeLocked = subscriptionFeeCharged || ('charge_subscription_fee' in data && !data.charge_subscription_fee);

    const contactRequiredFields = REQUIRED_FIELDS.map((field) => field === 'full_name' ? nameField : field === 'phone' ? phoneField : field);
    // Once active, a subscription is disconnected rather than put back to waiting.
    const statusOptions = STATUS_OPTIONS.map((option) =>
        isEdit && (original.status === 'active' || original.has_been_active) && option.value === 'suspended'
            ? { ...option, disabled: true, title: 'لا يمكن إعادة مشترك سبق تفعيله إلى قيد الانتظار؛ غيّر حالته إلى مفصول.' }
            : option,
    );
    const readingRequired = data.status === 'active';
    const requiredFields = [...contactRequiredFields, ...(canChooseBranch ? ['branch_id'] : []), ...(readingRequired ? ['initial_reading'] : [])];
    const filledRequiredFields = requiredFields.filter((field) => String(data[field] ?? '').trim() !== '').length;

    /** A choice made with a chip: no input event fires, so clear its error here. */
    function choose(field, value) {
        setData(field, value);
        clearErrors?.(field);
    }

    /**
     * The starting reading is entered only for an active subscription.
     * Activating one who is not active yet starts their subscription today,
     * from a reading entered now; one who had been active keeps the day they
     * first subscribed. Choosing their old status again puts back what they had.
     */
    function chooseStatus(value) {
        const activating = isEdit && original.status !== 'active' && value === 'active';

        setData((current) => ({
            ...current,
            status: value,
            initial_reading: value === 'active' ? (activating ? '' : current.initial_reading) : isEdit ? original.initial_reading : '',
            ...(isEdit && original.status !== 'active'
                ? { subscription_date: activating && !original.has_been_active ? new Date().toLocaleDateString('en-CA') : original.subscription_date }
                : {}),
        }));
        clearErrors?.('status', 'initial_reading', 'subscription_date');
    }

    function onBranchChange(value) {
        setSubAreaId('');
        setSelectedBox(null);
        setData((current) => ({ ...current, branch_id: value, meter_box_id: '' }));
    }

    function onSubAreaChange(value) {
        setSubAreaId(value);

        if (selectedBox && value && String(selectedBox.sub_area_id) !== String(value)) {
            setSelectedBox(null);
            setData('meter_box_id', '');
        }
    }

    function onMeterBoxChange(value, option) {
        setData('meter_box_id', value);
        setSelectedBox(option ? { value: option.value, label: option.label, sub_area_id: option.sub_area_id } : null);

        if (option?.sub_area_id) {
            setSubAreaId(String(option.sub_area_id));
        }
    }

    function onTariffChange(value) {
        setData((current) => ({ ...current, tariff_id: value, ...(String(current.tariff_id) !== String(value) ? { kilowatt_price: '' } : {}) }));
        setKilowattPriceUnlocked(false);
        clearErrors?.('tariff_id', 'kilowatt_price');
    }

    /** Opens the price for editing, starting from what the subscriber pays now. */
    function unlockKilowattPrice() {
        setConfirmingKilowattPriceUnlock(false);
        setKilowattPriceUnlocked(true);
        setData('kilowatt_price', hasOwnKilowattPrice ? data.kilowatt_price : tariffRate);
    }

    /** Closes the price again, back to what the form opened with (or the tariff's price for a new subscriber). */
    function lockKilowattPrice() {
        setKilowattPriceUnlocked(false);
        setData('kilowatt_price', String(original.tariff_id) === String(data.tariff_id) ? original.kilowatt_price : '');
        clearErrors?.('kilowatt_price');
    }

    function onCircuitBreakerChange(value) {
        const match = circuitBreakers.find((circuitBreaker) => String(circuitBreaker.id) === value);
        setData((current) => ({
            ...current,
            circuit_breaker_id: value,
            minimum_charge: match ? Number(match.minimum_payment) : current.minimum_charge,
        }));
        clearErrors?.('circuit_breaker_id');
    }

    return (
        <div className="space-y-4">
            <SubscriptionPreview
                data={data}
                tariff={selectedTariff}
                circuitBreaker={selectedCircuitBreaker}
                filled={filledRequiredFields}
                total={requiredFields.length}
            />

            {(sharedPersonalDetails || subscriptionCount > 1) && (
                <div className="rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm text-brand-800">
                    {sharedPersonalDetails
                        ? 'سيُضاف اشتراك جديد بنفس الهوية. يمكنك إضافة وصف إلى الاسم وتغيير رقم الجوال لهذا الاشتراك.'
                        : 'الاسم ورقم الجوال قابلان للتعديل لهذا الاشتراك فقط. الهوية والعنوان مشتركان بين الاشتراكات.'}
                    <InputError message={errors.source_subscription_id} className="mt-1" />
                </div>
            )}

            <FormSection icon="user" title="بيانات المشترك">
                <FormField
                    id={nameField}
                    label="الاسم"
                    required
                    error={errors[nameField]}
                    hint={independentContactDetails ? 'يمكنك إضافة وصف إلى الاسم مثل: محمد حمدان — المنزل.' : undefined}
                >
                    <TextInput
                        required
                        className="block w-full"
                        placeholder="الاسم الرباعي"
                        maxLength={255}
                        value={data[nameField]}
                        autoFocus
                        onChange={(e) => setData(nameField, e.target.value)}
                    />
                </FormField>

                <FormField id="national_id" label="رقم الهوية" error={errors.national_id}>
                    <TextInput
                        dir="ltr"
                        inputMode="numeric"
                        maxLength={9}
                        pattern="[0-9]{9}"
                        data-feedback
                        title="رقم الهوية يجب أن يتكون من 9 أرقام"
                        placeholder="9 أرقام"
                        className="block w-full"
                        value={data.national_id ?? ''}
                        readOnly={sharedPersonalDetails}
                        onChange={(event) => setData('national_id', plainDigits(event.target.value))}
                    />
                </FormField>

                <FormField id={phoneField} label="رقم الجوال" error={errors[phoneField]}>
                    <TextInput
                        type="tel"
                        dir="ltr"
                        inputMode="numeric"
                        maxLength={10}
                        pattern="05[69][0-9]{7}"
                        data-feedback
                        title="رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056"
                        placeholder="059XXXXXXX"
                        className="block w-full"
                        value={data[phoneField]}
                        onChange={(e) => setData(phoneField, plainDigits(e.target.value))}
                    />
                </FormField>

                <FormField
                    id="status"
                    label="الحالة"
                    required
                    error={errors.status}
                    span="sm:col-span-2 lg:col-span-3"
                    hint={
                        isEdit && original.status !== 'active' && data.status === 'active'
                            ? original.has_been_active
                                ? 'عند إعادة التوصيل أدخل القراءة السابقة للعدّاد؛ يبقى تاريخ الاشتراك الأصلي ويُسجَّل اليوم كتاريخ إعادة التوصيل.'
                                : 'عند التفعيل أدخل القراءة السابقة للعدّاد، ويُحدَّث تاريخ الاشتراك إلى تاريخ اليوم.'
                            : undefined
                    }
                >
                    <ChoiceChips label="الحالة" value={data.status} onChange={chooseStatus} options={statusOptions} />
                </FormField>
            </FormSection>

            <FormSection icon="bolt" title="نوع الاشتراك والقاطع" info="سعر الكيلو والحد الأدنى يُحسبان تلقائيًا من اختيارك">
                <FormField id="accounting_type" label="نوع المحاسبة" error={errors.accounting_type} span="sm:col-span-2 lg:col-span-3">
                    <ChoiceChips label="نوع المحاسبة" value={data.accounting_type} onChange={(value) => setData('accounting_type', value)} options={ACCOUNTING_TYPE_OPTIONS} />
                </FormField>

                <FormField id="tariff_id" label="نوع الاشتراك" required error={errors.tariff_id} span="sm:col-span-2">
                    <SearchableSelect
                        name="tariff_id"
                        required
                        value={data.tariff_id}
                        onChange={onTariffChange}
                        options={tariffs.map((tariff) => ({
                            value: String(tariff.id),
                            label: tariff.categoryLabel,
                            hint: `${formatAmount(tariff.rate)} ش/ك.و`,
                        }))}
                        placeholder="اختر نوع الاشتراك"
                        searchPlaceholder="بحث في أنواع الاشتراك..."
                        emptyLabel="لا توجد أنواع مطابقة"
                        active={Boolean(data.tariff_id)}
                    />
                </FormField>

                <div>
                    <div className="flex items-center justify-between">
                        <InputLabel htmlFor="tariff_rate" value="سعر الكيلو" />
                        {canEditKilowattPrice && selectedTariff && !kilowattPriceUnlocked && (
                            <button type="button" onClick={() => setConfirmingKilowattPriceUnlock(true)} className="text-xs font-semibold text-brand-600 hover:underline">
                                تعديل السعر
                            </button>
                        )}
                        {kilowattPriceUnlocked && (
                            <button type="button" onClick={lockKilowattPrice} className="text-xs font-semibold text-gray-500 hover:underline">
                                إلغاء التعديل
                            </button>
                        )}
                    </div>
                    <div className="mt-1">
                        <Affix unit="₪">
                            <TextInput
                                id="tariff_rate"
                                type={kilowattPriceLocked ? 'text' : 'number'}
                                step="0.01"
                                min={kilowattPriceLocked ? undefined : 0}
                                required={!kilowattPriceLocked}
                                readOnly={kilowattPriceLocked}
                                title={kilowattPriceLocked ? 'للقراءة فقط' : undefined}
                                value={kilowattPriceLocked ? (hasOwnKilowattPrice ? formatAmount(data.kilowatt_price) : selectedTariff ? formatAmount(selectedTariff.rate) : '—') : data.kilowatt_price}
                                onChange={(e) => setData('kilowatt_price', e.target.value)}
                                className={`block w-full ${kilowattPriceLocked ? 'bg-gray-50 text-gray-600' : ''}`}
                            />
                        </Affix>
                    </div>
                    {kilowattPriceUnlocked ? (
                        <p className="mt-1 text-xs text-gray-500">سعر التعرفة {formatAmount(tariffRate)} شيكل؛ يمكن أن يكون أقل منه أو أكثر.</p>
                    ) : hasOwnKilowattPrice ? (
                        <p className="mt-1 text-xs font-semibold text-brand-600">سعر خاص بهذا المشترك — سعر التعرفة {formatAmount(tariffRate)} شيكل.</p>
                    ) : kilowattPriceDropped ? (
                        <p className="mt-1 text-xs text-amber-600">أُلغي السعر الخاص بهذا المشترك لتغيير نوع الاشتراك.</p>
                    ) : null}
                    <InputError message={errors.kilowatt_price} className="mt-1" />
                    <ConfirmDialog
                        show={confirmingKilowattPriceUnlock}
                        onConfirm={unlockKilowattPrice}
                        onCancel={() => setConfirmingKilowattPriceUnlock(false)}
                        title="تعديل سعر الكيلو لهذا المشترك؟"
                        message={`سيصبح لهذا المشترك سعر كيلو خاص به بدل سعر التعرفة (${selectedTariff ? formatAmount(selectedTariff.rate) : ''} شيكل)، ويُحسب به استهلاكه وخصوماته في القراءات القادمة. هل تريد المتابعة؟`}
                        confirmLabel="نعم، عدّل"
                        icon="alert"
                    />
                </div>

                <FormField id="circuit_breaker_id" label="القاطع" error={errors.circuit_breaker_id} span="sm:col-span-2">
                    <SearchableSelect
                        value={data.circuit_breaker_id}
                        onChange={onCircuitBreakerChange}
                        options={circuitBreakers.map((circuitBreaker) => ({
                            value: String(circuitBreaker.id),
                            label: `${circuitBreaker.ampere} أمبير`,
                            hint: `الحد الأدنى ${formatAmount(circuitBreaker.minimum_payment)} ش`,
                        }))}
                        placeholder="بدون قاطع"
                        searchPlaceholder="بحث في القواطع..."
                        emptyLabel="لا توجد قواطع مطابقة"
                        active={Boolean(data.circuit_breaker_id)}
                    />
                </FormField>

                <div>
                    <div className="flex items-center justify-between">
                        <InputLabel htmlFor="minimum_charge">
                            الحد الأدنى
                            <span className="text-red-500"> *</span>
                        </InputLabel>
                        {canEditMinimumCharge && !minimumChargeUnlocked && (
                            <button
                                type="button"
                                onClick={() => setConfirmingMinimumChargeUnlock(true)}
                                className="text-xs font-semibold text-brand-600 hover:underline"
                            >
                                تعديل يدوي
                            </button>
                        )}
                    </div>
                    <div className="mt-1">
                        <Affix unit="₪">
                            <TextInput
                                id="minimum_charge"
                                type="number"
                                step="0.01"
                                disabled={minimumChargeLocked}
                                className={`block w-full disabled:opacity-100 ${minimumChargeLocked ? 'bg-gray-50 text-gray-600' : ''}`}
                                value={data.minimum_charge}
                                onChange={(e) => setData('minimum_charge', e.target.value)}
                            />
                        </Affix>
                    </div>
                    <InputError message={errors.minimum_charge} className="mt-1" />
                    <ConfirmDialog
                        show={confirmingMinimumChargeUnlock}
                        onConfirm={unlockMinimumCharge}
                        onCancel={() => setConfirmingMinimumChargeUnlock(false)}
                        title="تعديل الحد الأدنى يدويًا؟"
                        message="أنت على وشك تعديل الحد الأدنى لهذا المشترك يدويًا بدل القيمة المأخوذة من القاطع. هل تريد المتابعة؟"
                        confirmLabel="نعم، عدّل"
                        icon="alert"
                    />
                </div>
                {segments.length > 0 && (
                    <FormField id="tariff_segment_id" label="تصنيف الزبائن" error={errors.tariff_segment_id} span="sm:col-span-2">
                        <SearchableSelect
                            value={data.tariff_segment_id}
                            onChange={(value) => setData('tariff_segment_id', value)}
                            options={segments.map((segment) => ({ value: String(segment.id), label: segment.name }))}
                            placeholder="بدون تصنيف"
                            searchPlaceholder="بحث في التصنيفات..."
                            emptyLabel="لا توجد تصنيفات مطابقة"
                            active={Boolean(data.tariff_segment_id)}
                        />
                    </FormField>
                )}
            </FormSection>

            <FormSection icon="pin" title="الموقع والعداد">
                <div className="grid grid-cols-1 gap-x-6 gap-y-4 sm:col-span-2 sm:grid-cols-2 lg:col-span-3">
                    {canChooseBranch && (
                        <FormField id="branch_id" label="الفرع" required error={errors.branch_id}>
                            {branches.length === 0 ? (
                                <p className="text-sm text-gray-500">لا توجد فروع بعد — أنشئ فرعًا أولاً.</p>
                            ) : (
                                <select required className="block w-full" value={data.branch_id} onChange={(e) => onBranchChange(e.target.value)}>
                                    <option value="">— اختر فرعًا —</option>
                                    {branches.map((branch) => (
                                        <option key={branch.id} value={branch.id}>
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>
                    )}

                    {canChooseBranch && (
                        <ReadOnlyField
                            id="branch_area"
                            label="المنطقة"
                            value={resolvedAreaName || 'اختر فرعًا أولاً لعرض منطقته.'}
                        />
                    )}

                    {Boolean(resolvedAreaId) && (
                        <FormField id="sub_area_id" label="منطقة 2" error={errors.sub_area_id}>
                            {subAreasInArea.length === 0 ? (
                                <p className="text-sm text-gray-500">لا توجد منطقة 2 في هذه المنطقة بعد.</p>
                            ) : (
                                <SearchableSelect
                                    id="sub_area_id"
                                    value={subAreaId}
                                    onChange={onSubAreaChange}
                                    options={subAreasInArea.map((subArea) => ({ value: String(subArea.id), label: subArea.name }))}
                                    placeholder={isEdit ? '— بلا منطقة 2 —' : '— اختر منطقة 2 —'}
                                    searchPlaceholder="بحث في المناطق..."
                                    emptyLabel="لا توجد مناطق مطابقة"
                                    active={Boolean(subAreaId)}
                                />
                            )}
                        </FormField>
                    )}

                    {showMeterBoxField && (
                        <FormField id="meter_box_id" label="الطبلون (الاسم والرقم)" error={errors.meter_box_id}>
                            <SearchableSelect
                                value={data.meter_box_id}
                                onChange={onMeterBoxChange}
                                options={selectedBox ? [selectedBox] : []}
                                loadOptions={loadMeterBoxes}
                                searchPlaceholder="بحث عن طبلون..."
                                emptyLabel="لا توجد طبلونات مطابقة"
                            />
                        </FormField>
                    )}
                </div>
            </FormSection>

            <FormSection icon="calendar" title="معلومات الاشتراك">
                <FormField
                    id="initial_reading"
                    label="القراءة السابقة"
                    required={readingRequired}
                    error={errors.initial_reading}
                    hint={readingRequired ? 'منها يبدأ حساب الاستهلاك.' : 'تُدخل القراءة عند تفعيل المشترك، منها يبدأ حساب استهلاكه.'}
                >
                    <Affix unit="ك.و.س">
                        <TextInput
                            type="number"
                            required={readingRequired}
                            disabled={!readingRequired}
                            min={0}
                            step="0.01"
                            className={`block w-full disabled:opacity-100 ${readingRequired ? '' : 'bg-gray-50 text-gray-600'}`}
                            value={data.initial_reading}
                            onChange={(event) => setData('initial_reading', event.target.value)}
                        />
                    </Affix>
                </FormField>

                <FormField id="subscription_fee" label="رسوم الاشتراك" required={data.charge_subscription_fee} error={errors.subscription_fee}>
                    <Affix unit="₪">
                        <TextInput
                            type="number"
                            step="0.01"
                            disabled={subscriptionFeeLocked}
                            title={subscriptionFeeCharged ? 'حُمّلت الرسوم على الحساب؛ عدّلها من سجل المعاملات.' : subscriptionFeeLocked ? 'فعّل تحميل رسوم اشتراك لإدخال المبلغ' : undefined}
                            className={`block w-full disabled:opacity-100 ${subscriptionFeeLocked ? 'bg-gray-50 text-gray-600' : ''}`}
                            required={data.charge_subscription_fee}
                            min={data.charge_subscription_fee ? '0.01' : '0'}
                            value={data.subscription_fee}
                            onChange={(e) => setData('subscription_fee', e.target.value)}
                        />
                    </Affix>
                </FormField>

                <FormField id="subscription_date" label="تاريخ الاشتراك" error={errors.subscription_date}>
                    <DateInput
                        // The server accepts no date before 2000 or after today.
                        min="2000-01-01"
                        max={new Date().toLocaleDateString('en-CA')}
                        value={data.subscription_date}
                        onChange={(value) => setData('subscription_date', value)}
                    />
                </FormField>

                {'charge_subscription_fee' in data && (
                    <div className="sm:col-span-2 lg:col-span-3">
                        <Switch
                            checked={data.charge_subscription_fee}
                            onChange={(checked) => choose('charge_subscription_fee', checked)}
                            label="تحميل رسوم اشتراك"
                            ariaLabel="تحميل رسوم اشتراك"
                            hint={
                                data.charge_subscription_fee
                                    ? 'تُضاف رسوم الاشتراك إلى الرصيد وسجل المعاملات عند حفظ المشترك.'
                                    : 'لن تُضاف الرسوم إلى الرصيد أو سجل المعاملات. يمكنك تحميلها لاحقًا من سجل المعاملات.'
                            }
                        />
                        <InputError message={errors.charge_subscription_fee} className="mt-1" />
                    </div>
                )}
            </FormSection>

            <FormSection icon="note" title="معلومات إضافية">
                <FormField id="address" label="العنوان" error={errors.address} span="sm:col-span-2 lg:col-span-3">
                    <textarea
                        rows={2}
                        className="block w-full"
                        value={data.address}
                        readOnly={sharedPersonalDetails}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                </FormField>

                <FormField id="notes" label="معلومات أخرى" error={errors.notes} span="sm:col-span-2 lg:col-span-3">
                    <textarea rows={2} className="block w-full" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </FormField>
            </FormSection>
        </div>
    );
}
