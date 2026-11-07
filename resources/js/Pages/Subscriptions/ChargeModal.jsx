import { useId, useRef, useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import { useResourceForm } from '@/hooks/useResourceForm';
import { describeBalance } from '@/lib/accountStatement';
import { formatMoney, normalizeDecimalInput } from '@/lib/format';
import { amountForChargeType } from '@/lib/subscriptionCharges';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';
import {
    AmountBox,
    balanceAfterRow,
    balanceText,
    ChoiceTile,
    DoneScreen,
    FieldLabel,
    FieldWarning,
    FormFooter,
    FormHeader,
    QuickPick,
    SubscriptionStrip,
    SummaryFigure,
    SummaryLedger,
    SummaryPanel,
} from './AccountFormParts';
import { CorrectionReasonFields, EMPTY_CORRECTION, OriginalLine } from './CorrectionFields';

/** How each type of charge looks in the form: its icon, a hint, quick amounts and an example note. */
const TYPE_LOOKS = {
    penalty: { icon: 'warning', hint: 'مخالفة أو عبث بالعداد', quick: [50, 100, 200], example: 'اكتب سبب الغرامة، مثال: توصيل خط بدون عداد' },
    disconnection_fee: { icon: 'power', hint: 'قطع الكهرباء أو إعادتها', quick: [25, 50], example: 'مثال: فصل بسبب تراكم الدين' },
    subscription_fee: { icon: 'document-plus', hint: 'تحميل رسوم الاشتراك على الحساب', quick: [50, 100, 200], example: 'مثال: رسوم اشتراك المشترك' },
    minimum_charge: { icon: 'bolt', hint: 'تحميل الحد الأدنى المحدد لهذا المشترك', quick: [], example: 'ملاحظات على تحميل الحد الأدنى (اختياري)' },
};

/** A charge above this many shekels asks the user to double-check it. */
const LARGE_CHARGE = 500;

const notesClass =
    'block min-h-[76px] w-full resize-y rounded-[14px] border-[1.5px] bg-surface px-4 py-3 text-base leading-relaxed text-gray-900 transition placeholder:text-gray-400 hover:border-brand-500/45 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15';

/**
 * Charge a subscription by hand (تحميل): a financial penalty (which must say
 * why), a service disconnection fee, subscription fee or their minimum.
 * A dark panel beside the form shows what it does to the balance before
 * saving; once saved, the window shows what was recorded. Keys: Ctrl +
 * Enter saves; number keys pick the type.
 *
 * With `correcting` (a statement line), it corrects that charge instead:
 * the form starts from it, `balance` leaves it out, and saving cancels it
 * and records this one in its place, with one of `correctionReasons`.
 */
export default function ChargeModal({ show, onClose, subscription, balance, chargeTypes, correcting = null, correctionReasons = [] }) {
    const titleId = useId();
    const amountInput = useRef(null);
    const form = useResourceForm(
        correcting ? `/subscriptions/${subscription.id}/transactions` : `/subscriptions/${subscription.id}/charges`,
        correcting,
        correcting
            ? { type: correcting.recorded.type, amount: correcting.recorded.amount, notes: correcting.recorded.notes, override_minimum_amount: false, ...EMPTY_CORRECTION }
            : { type: chargeTypes[0]?.value ?? '', amount: '', notes: '', override_minimum_amount: false },
    );
    const { data, setData, errors } = form;
    const [discarding, setDiscarding] = useState(false);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [confirmingAmountUnlock, setConfirmingAmountUnlock] = useState(false);
    const [receipt, setReceipt] = useState(null);

    const type = chargeTypes.find((option) => option.value === data.type) ?? chargeTypes[0];
    const look = TYPE_LOOKS[type?.value] ?? TYPE_LOOKS.penalty;
    const isMinimumCharge = data.type === 'minimum_charge';
    const amount = Number(data.amount) > 0 ? Number(data.amount) : 0;
    const balanceAfter = amount > 0 ? Number(balance) + amount : null;
    const missingReason = Boolean(type?.needsReason && !data.notes.trim());

    function close() {
        setDiscarding(false);
        setConfirmingAmountUnlock(false);
        setReceipt(null);
        form.resetAndClearErrors();
        onClose();
    }

    function requestClose() {
        if (!receipt && form.isDirty) {
            setDiscarding(true);
        } else {
            close();
        }
    }

    /** Picks a type, filling in its usual amount when none is typed yet. */
    function chooseType(value) {
        setData((current) => ({ ...current, type: value, amount: amountForChargeType(current, value, subscription, chargeTypes), override_minimum_amount: false }));
        setConfirmingAmountUnlock(false);
        form.clearErrors('type', 'notes', 'amount');
    }

    function setAmount(value) {
        setData('amount', value);
        form.clearErrors('amount');
        amountInput.current?.focus();
    }

    /** Number keys pick the type — unless a field is being typed in. */
    function onKeyDown(event) {
        submitOnCtrlEnter(event);

        const typing = event.target.matches('textarea, input:not([type=radio])');
        const index = Number(event.key) - 1;

        if (!typing && !event.ctrlKey && !event.metaKey && !event.altKey && chargeTypes[index]) {
            event.preventDefault();
            chooseType(chargeTypes[index].value);
        }
    }

    function submit(event) {
        event.preventDefault();

        if (!validateFormFields(event.currentTarget, form)) {
            return;
        }

        setConfirmingSave(true);
    }

    function save() {
        setConfirmingSave(false);

        const recorded = { typeLabel: type.label, amount, notes: data.notes.trim(), balanceAfter };

        form.save({
            preserveScroll: true,
            onSuccess: () => {
                setReceipt(recorded);
                form.resetAndClearErrors();
            },
        });
    }

    return (
        <>
            <Modal show={show} onClose={requestClose} maxWidth="5xl">
                {receipt ? (
                    <DoneScreen
                        tone={correcting ? 'amber' : 'red'}
                        title={correcting ? 'صُحّح التحميل' : 'أُضيف التحميل'}
                        text={`${formatMoney(receipt.amount)} ₪ ${receipt.typeLabel} على حساب ${subscription.fullName}.`}
                        rows={[
                            ['النوع', receipt.typeLabel],
                            ['المبلغ', `${formatMoney(receipt.amount)} ₪`],
                            ['الرصيد بعد', balanceText(describeBalance(receipt.balanceAfter))],
                            ['ملاحظات', receipt.notes || '—'],
                        ]}
                        anotherLabel="تحميل آخر"
                        onAnother={correcting ? null : () => setReceipt(null)}
                        onDone={close}
                    />
                ) : (
                    <form
                        noValidate
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        onSubmit={submit}
                        onInput={(e) => clearErrorOnInput(e, form)}
                        onKeyDown={onKeyDown}
                        className="flex max-h-[calc(100dvh-6rem)] flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:grid-rows-[auto_minmax(0,1fr)_auto]"
                    >
                        <FormHeader
                            titleId={titleId}
                            icon={correcting ? 'repeat' : 'arrow-down-tray'}
                            tone={correcting ? 'amber' : 'red'}
                            title={correcting ? 'تصحيح تحميل' : 'تحميل حركة'}
                            subtitle={correcting ? 'صحّح التحميل؛ يبقى الأصلي في الكشف ملغى مع سبب التصحيح.' : 'مبلغ يُضاف على حساب المشترك.'}
                            onClose={requestClose}
                        />

                        {/* On phones the fields and the summary scroll together; side by side they each scroll alone. */}
                        <div className="min-h-0 flex-1 overflow-y-auto lg:contents">
                            <div className="grid grid-cols-1 content-start gap-[22px] px-5 pb-3 pt-5 sm:px-6 lg:col-start-1 lg:row-start-2 lg:min-h-0 lg:overflow-y-auto">
                                <SubscriptionStrip
                                    subscription={subscription}
                                    balance={balance}
                                    balanceLabel={correcting ? 'الرصيد بدون التحميل الأصلي' : 'الرصيد الحالي'}
                                />

                                {correcting && <OriginalLine entry={correcting} />}

                                <fieldset>
                                    <legend className="contents">
                                        <FieldLabel required hint={`اختر بالأرقام ${chargeTypes.map((_, index) => index + 1).join(' · ')}`}>
                                            نوع التحميل
                                        </FieldLabel>
                                    </legend>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {chargeTypes.map((option, index) => (
                                            <ChoiceTile
                                                key={option.value}
                                                name="type"
                                                value={option.value}
                                                checked={data.type === option.value}
                                                onChange={chooseType}
                                                icon={TYPE_LOOKS[option.value]?.icon ?? 'document-plus'}
                                                title={option.label}
                                                hint={TYPE_LOOKS[option.value]?.hint}
                                                tone="red"
                                                stacked
                                                shortcut={index + 1}
                                            />
                                        ))}
                                    </div>
                                    <InputError message={errors.type} className="mt-2" />
                                </fieldset>

                                <div>
                                    <div className="flex items-center justify-between gap-3">
                                        <FieldLabel htmlFor="amount" required>المبلغ</FieldLabel>
                                        {isMinimumCharge && <button type="button" aria-pressed={data.override_minimum_amount} onClick={() => {
                                            if (data.override_minimum_amount) {
                                                setData((current) => ({ ...current, amount: String(subscription.minimumPayment ?? '0'), override_minimum_amount: false }));
                                                form.clearErrors('amount');
                                            } else {
                                                setConfirmingAmountUnlock(true);
                                            }
                                        }} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline">
                                            <Icon name={data.override_minimum_amount ? 'unlock' : 'lock'} className="h-3.5 w-3.5" />
                                            {data.override_minimum_amount ? 'إعادة الحد الأدنى وقفل المبلغ' : 'فتح القفل لتعديل المبلغ'}
                                        </button>}
                                    </div>
                                    <AmountBox
                                        id="amount"
                                        inputRef={amountInput}
                                        value={data.amount}
                                        onChange={(e) => setData('amount', normalizeDecimalInput(e.target.value))}
                                        unit="₪"
                                        placeholder="0.00"
                                        label="مبلغ التحميل"
                                        autoFocus
                                        readOnly={isMinimumCharge && !data.override_minimum_amount}
                                        error={Boolean(errors.amount)}
                                    >
                                        {look.quick.map((quick) => (
                                            <QuickPick key={quick} onClick={() => setAmount(String(quick))}>
                                                {quick} ₪
                                            </QuickPick>
                                        ))}
                                        {type?.usualAmount && (
                                            <span className="ms-auto text-[13.5px] text-gray-500">
                                                المعتاد لهذا النوع <b className="font-display text-gray-900">{formatMoney(type.usualAmount)} ₪</b>
                                            </span>
                                        )}
                                    </AmountBox>
                                    <InputError message={errors.amount} className="mt-2" />
                                    {isMinimumCharge && <p className="mt-2 text-sm text-gray-500">الحد الأدنى للمشترك: {formatMoney(subscription.minimumPayment)} ₪. يُسجّل كتحميل يدوي مستقل على الحساب.</p>}
                                    {amount > LARGE_CHARGE && (
                                        <FieldWarning>مبلغ كبير. تأكد أنه صحيح قبل الحفظ، فهو يظهر في كشف حساب المشترك.</FieldWarning>
                                    )}
                                </div>

                                <div>
                                    <FieldLabel
                                        htmlFor="charge_notes"
                                        required={type?.needsReason}
                                        hint={type?.needsReason ? 'يظهر في كشف الحساب' : undefined}
                                    >
                                        {type?.needsReason ? 'سبب الغرامة' : 'ملاحظات'}
                                    </FieldLabel>
                                    <textarea
                                        id="charge_notes"
                                        name="notes"
                                        rows={2}
                                        required={type?.needsReason}
                                        maxLength={1000}
                                        placeholder={look.example}
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        className={`${notesClass} ${missingReason && amount > 0 ? 'border-amber-500/60' : 'border-gray-200'}`}
                                    />
                                    <InputError message={errors.notes} className="mt-2" />
                                </div>

                                {correcting && <CorrectionReasonFields form={form} reasons={correctionReasons} />}
                            </div>

                            <SummaryPanel title="ملخص التحميل" tag={type?.label}>
                                <SummaryFigure label="مبلغ التحميل" value={`${formatMoney(amount)}`} tone="red" />
                                <SummaryLedger
                                    rows={[
                                        [correcting ? 'الرصيد بدون الأصلي' : 'الرصيد الحالي', balanceText(describeBalance(balance))],
                                        [type?.label ?? 'تحميل', `${formatMoney(amount)} ₪`, 'red'],
                                    ]}
                                    result={balanceAfterRow('الرصيد بعد التحميل', balanceAfter)}
                                />
                            </SummaryPanel>
                        </div>

                        <FormFooter
                            onCancel={requestClose}
                            tone={correcting ? 'amber' : 'red'}
                            disabled={amount <= 0 || missingReason}
                            processing={form.processing}
                            submitLabel={correcting ? 'حفظ التصحيح' : amount > 0 ? `تحميل ${formatMoney(amount)} ₪ على الحساب` : 'إضافة التحميل'}
                            shortcuts={`${chargeTypes.map((_, index) => index + 1).join(' · ')} للنوع`}
                        />
                    </form>
                )}
            </Modal>

            <ConfirmDialog
                show={show && confirmingAmountUnlock}
                onConfirm={() => {
                    setConfirmingAmountUnlock(false);
                    setData('override_minimum_amount', true);
                    form.clearErrors('amount');
                    amountInput.current?.focus();
                }}
                onCancel={() => setConfirmingAmountUnlock(false)}
                title="تعديل مبلغ التحميل؟"
                message="سيُفتح المبلغ لتعديله لهذا التحميل فقط. يبقى الحد الأدنى المحفوظ للمشترك كما هو."
                confirmLabel="نعم، افتح القفل"
                icon="pencil"
            />

            <ConfirmDialog
                show={show && discarding}
                onConfirm={close}
                onCancel={() => setDiscarding(false)}
                title="تجاهل التحميل؟"
                message="أدخلت بيانات لم تُحفظ بعد. إذا أغلقت النافذة الآن فستفقدها."
                confirmLabel="تجاهل التحميل"
                cancelLabel="البقاء ومتابعة الإدخال"
                icon="alert"
                tone="danger"
            />

            <ConfirmDialog
                show={show && confirmingSave}
                onConfirm={save}
                onCancel={() => setConfirmingSave(false)}
                title={correcting ? 'تأكيد حفظ التصحيح؟' : 'تأكيد إضافة التحميل؟'}
                message={`${correcting ? 'سيُصحَّح التحميل إلى' : 'سيُضاف تحميل'} ${type.label} بقيمة ${formatMoney(amount)} ₪ على حساب ${subscription.fullName}. هل تريد المتابعة؟`}
                confirmLabel={correcting ? 'نعم، احفظ التصحيح' : 'نعم، أضف التحميل'}
                cancelLabel="رجوع للمراجعة"
                icon="check"
            />
        </>
    );
}
