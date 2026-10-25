import FormModal from '@/Components/FormModal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SearchableSelect from '@/Components/SearchableSelect';
import { useResourceForm } from '@/hooks/useResourceForm';
import { consumptionBetween } from '@/lib/readings';

const APPROVED_READING_WARNING = 'هذه القراءة معتمدة. تعديلها يعيدها إلى قيد المراجعة ويزيل مبلغها من المعاملات المالية للمشترك حتى يُعاد اعتمادها.';
const APPROVE_NOW_NOTE = 'هذه القراءة معتمدة. سيُلغى مبلغها السابق بقيد عكسي يبقى في الكشف، وتُعتمد القراءة المصحّحة ويُحمَّل مبلغها الجديد فورًا.';

function Summary({ label, value, tone = 'text-gray-900' }) {
    return (
        <div className="min-w-0 rounded-lg bg-gray-50 px-3 py-2 text-center">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`mt-1 break-words text-center font-display text-base font-bold tabular-nums ${tone}`} dir="ltr">
                {value}
            </p>
        </div>
    );
}

/**
 * Enter or correct a weekly reading. `fixedSubscription` (an option shaped
 * like `subscriptionOptions` entries) pins the form to one subscription, as
 * when it is opened from that subscription's statement. A reading that
 * says `canApprove` (the statement's) offers to approve the correction at
 * once instead of sending it back for approval.
 */
export default function MeterReadingModal({ show, onClose, reading, subscriptionOptions = [], fixedSubscription = null, weekOptions }) {
    const offersApproval = Boolean(reading?.status === 'approved' && reading?.canApprove);
    const form = useResourceForm(
        '/meter-readings',
        reading,
        reading
            ? { current_reading: reading.requiresReentry ? '' : String(reading.current_reading), notes: reading.notes ?? '', ...(offersApproval ? { approve: true } : {}) }
            : { subscription_id: fixedSubscription?.value ?? '', week_start: weekOptions[0]?.value ?? '', current_reading: '', notes: '' },
    );
    const { data, setData, errors, isEdit } = form;
    const approvesNow = offersApproval && data.approve;

    const selectedSubscription = isEdit ? null : (fixedSubscription ?? subscriptionOptions.find((option) => option.value === String(data.subscription_id)));
    const previousReading = isEdit ? reading.previous_reading : selectedSubscription?.lastReading;
    const hasPrevious = previousReading !== undefined && previousReading !== null;
    const consumption = hasPrevious && data.current_reading !== '' ? consumptionBetween(previousReading, data.current_reading) : null;

    return (
        <FormModal
            show={show}
            onClose={onClose}
            form={form}
            title={isEdit ? 'تعديل القراءة' : 'إدخال قراءة'}
            icon="bolt"
            visitOptions={{ preserveState: true }}
            bodyClassName="space-y-4"
            // Readings are entered one after another, so they save without a confirmation step —
            // except correcting an approved one, which sends it back for approval.
            confirmBeforeSave={isEdit && reading.status === 'approved'}
            saveConfirmMessage={`${approvesNow ? APPROVE_NOW_NOTE : APPROVED_READING_WARNING} هل تريد المتابعة؟`}
        >
            {isEdit ? (
                <div className="rounded-lg border border-gray-100 px-4 py-3 text-sm">
                    <p className="font-semibold text-gray-900">{reading.subscriptionName}</p>
                    <p className="mt-1 text-gray-500">
                        <span dir="ltr">{reading.accountNumber}</span> · الأسبوع {reading.weekStart} ← {reading.weekEnd}
                    </p>
                </div>
            ) : (
                <>
                    {fixedSubscription ? (
                        <div className="rounded-lg border border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">{fixedSubscription.label}</div>
                    ) : (
                        <div>
                            <InputLabel value="المشترك" />
                            <SearchableSelect
                                className="mt-1"
                                value={data.subscription_id}
                                onChange={(value) => setData('subscription_id', value)}
                                options={subscriptionOptions}
                                placeholder="اختر مشتركًا"
                                searchPlaceholder="بحث بالاسم أو رقم الاشتراك..."
                                emptyLabel="لا يوجد مشتركون مطابقون"
                            />
                            <InputError message={errors.subscription_id} className="mt-2" />
                        </div>
                    )}

                    <div>
                        <InputLabel htmlFor="week_start" value="الأسبوع" />
                        <select
                            id="week_start"
                            className="mt-1 block w-full"
                            value={data.week_start}
                            onChange={(e) => setData('week_start', e.target.value)}
                        >
                            {weekOptions.map((week) => (
                                <option key={week.value} value={week.value}>
                                    {week.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.week_start} className="mt-2" />
                        {selectedSubscription?.lastWeekStart && (
                            <p className="mt-1 text-xs text-gray-500">آخر قراءة مسجلة لأسبوع يبدأ في {selectedSubscription.lastWeekStart}</p>
                        )}
                    </div>
                </>
            )}

            <div className={`grid items-start gap-4 ${hasPrevious ? 'sm:grid-cols-2' : ''}`}>
                {hasPrevious && <div>
                    <InputLabel htmlFor="previous_reading" value="القراءة السابقة" className="text-center" />
                    <TextInput
                        id="previous_reading"
                        dir="ltr"
                        readOnly
                        value={previousReading}
                        className="mt-2 h-12 w-full bg-gray-50 !text-center font-display text-xl font-semibold text-gray-600"
                    />
                </div>}
                <div>
                    <InputLabel htmlFor="current_reading" value="القراءة الحالية" className="text-center" />
                    <TextInput
                        id="current_reading"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        dir="ltr"
                        className="mt-2 h-12 w-full !text-center font-display text-xl font-semibold"
                        value={data.current_reading}
                        autoFocus={isEdit}
                        onChange={(e) => setData('current_reading', e.target.value)}
                    />
                    <InputError message={errors.current_reading} className="mt-2" />
                </div>
            </div>

            {hasPrevious && (
                <div>
                    <Summary
                        label="الاستهلاك (كيلو)"
                        value={consumption ?? '—'}
                        tone={consumption !== null && consumption < 0 ? 'text-red-600' : 'text-brand-700'}
                    />
                </div>
            )}

            {offersApproval && (
                <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5 text-sm">
                    <input
                        type="checkbox"
                        className="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                        checked={data.approve}
                        onChange={(e) => setData('approve', e.target.checked)}
                    />
                    <span>
                        <span className="block font-semibold text-gray-900">اعتماد القراءة المصحّحة الآن</span>
                        <span className="block text-xs text-gray-500">
                            {data.approve
                                ? 'يُحمَّل المبلغ الجديد على الحساب مباشرة، دون انتظار المراجعة.'
                                : 'تعود القراءة إلى قيد المراجعة، ولا يُحمَّل مبلغها حتى يعتمدها أحد المعتمِدين.'}
                        </span>
                    </span>
                </label>
            )}

            <div>
                <InputLabel htmlFor="notes" value="ملاحظات" />
                <textarea id="notes" rows={2} className="mt-1 block w-full" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} className="mt-2" />
            </div>
        </FormModal>
    );
}
