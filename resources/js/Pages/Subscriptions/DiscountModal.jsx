import { useId, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import { useResourceForm } from '@/hooks/useResourceForm';
import { describeBalance, discountAmount } from '@/lib/accountStatement';
import { formatAmount } from '@/lib/currency';
import { formatMoney, normalizeDecimalInput } from '@/lib/format';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';
import { weeklyCharges } from '@/lib/readings';
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

/** A discount comes off the balance once, in shekels, or off every weekly reading from now on. */
const KINDS = [
    { value: 'once', icon: 'tag', title: 'لمرة واحدة', hint: 'مبلغ بالشيكل يُخصم الآن من الرصيد المستحق' },
    { value: 'standing', icon: 'repeat', title: 'خصم القراءات الأسبوعية', hint: 'من كل قراءة أسبوعية حتى تُوقفه' },
];

const METHOD_ICONS = { percentage: 'percent', kilowatt: 'bolt', shekel: 'currency' };
const METHOD_ORDER = ['percentage', 'kilowatt', 'shekel'];

/** The quick values under the amount, by kind and method; a one-off discount is in shekels only. */
const QUICK_VALUES = {
    once: { shekel: [10, 20, 50] },
    standing: { percentage: [5, 10, 20], kilowatt: [5, 10, 20], shekel: [0.5, 1] },
};

/** Roughly how many weeks a month has, for the monthly saving. */
const WEEKS_PER_MONTH = 4.3;

const inputClass =
    'block h-[50px] w-full rounded-[14px] border-[1.5px] border-gray-200 bg-surface px-4 text-base text-gray-900 transition placeholder:text-gray-400 hover:border-brand-500/45 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15';

/** What the value field asks for: shekels off the balance once, or by method for the weekly readings discount. */
function fieldFor(kind, method, kiloPrice) {
    if (kind === 'once') {
        return { label: 'المبلغ', hint: 'بالشيكل', unit: '₪', suffix: ' ₪' };
    }

    return {
        percentage: { title: 'نسبة', tileHint: 'من قيمة كل قراءة', label: 'النسبة من كل قراءة', hint: 'من 1 إلى 100', unit: '%', suffix: '%' },
        kilowatt: {
            title: 'كيلوات',
            tileHint: 'كيلوات مجانية كل أسبوع',
            label: 'الكيلوات المجانية كل أسبوع',
            hint: `الكيلو بـ ${formatMoney(kiloPrice)} ₪`,
            unit: 'كيلو',
            suffix: ' كيلو',
        },
        shekel: {
            title: 'شيكل من سعر الكيلو',
            tileHint: 'يُنزل من سعر الكيلو',
            label: 'الخصم من سعر الكيلو',
            hint: `سعر الكيلو ${formatMoney(kiloPrice)} ₪`,
            unit: '₪',
            suffix: ' ₪',
        },
    }[method];
}

/** How a standing discount reads: "10%", "3 كيلو" or "5 شيكل من سعر الكيلو". Mirrors StandingDiscount::termsFor(). */
function standingTerms(method, value) {
    const amount = formatAmount(value);

    return { percentage: `${amount}%`, kilowatt: `${amount} كيلو`, shekel: `${amount} شيكل من سعر الكيلو` }[method];
}

/** A standing discount's terms with the customer segment it is given to: "3 كيلو · موظفو أبو زايد". */
function withSegment(terms, segment) {
    return segment ? `${terms} · ${segment}` : terms;
}

/**
 * Give a subscription a discount (خصم). Once: shekels taken off what they
 * owe now. Weekly readings (خصم القراءات الأسبوعية): an advantage taken
 * off the latest week's reading, straight away if it has been entered,
 * and every weekly reading after it — a
 * percentage of the reading, free kilowatts, or shekels off the kilo price
 * — until it is stopped. A dark panel beside the form shows the balance a
 * discount leaves, or what the subscription pays for the latest week with a
 * standing one. Keys: Ctrl + Enter saves, 1 · 2 · 3 pick the weekly
 * readings discount's method.
 *
 * With `correcting` (a statement line), it corrects that one-off discount
 * instead: the form starts from its shekels, `balance` leaves it out, and
 * saving cancels it and records this one in its place, with one of
 * `correctionReasons`.
 */
export default function DiscountModal({
    show,
    onClose,
    subscription,
    balance,
    discountMethods,
    discountSegments = [],
    correcting = null,
    correctionReasons = [],
}) {
    const titleId = useId();
    const valueInput = useRef(null);
    const standingDiscount = subscription.standingDiscount;
    const form = useResourceForm(
        correcting ? `/subscriptions/${subscription.id}/transactions` : `/subscriptions/${subscription.id}/discounts`,
        correcting,
        correcting
            ? {
                  kind: 'once',
                  method: 'shekel',
                  value: formatAmount(correcting.amount),
                  segment: '',
                  notes: correcting.recorded.notes,
                  ...EMPTY_CORRECTION,
              }
            : { kind: 'once', method: 'shekel', value: '', segment: '', notes: '' },
    );
    const { data, setData, errors } = form;
    const [discarding, setDiscarding] = useState(false);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [confirmingStop, setConfirmingStop] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [receipt, setReceipt] = useState(null);

    const isStanding = data.kind === 'standing';
    const kiloPrice = Number(subscription.kiloPrice);
    const owed = Math.max(Number(balance), 0);
    const value = Number(data.value) > 0 ? Number(data.value) : 0;
    const field = fieldFor(data.kind, data.method, kiloPrice);
    const methods = isStanding ? METHOD_ORDER.filter((method) => discountMethods.some((option) => option.value === method)) : [];

    // What makes the value unusable, said under it.
    let invalid = null;

    if (data.method === 'percentage' && value > 100) {
        invalid = 'النسبة لا تزيد عن 100%.';
    } else if (isStanding && data.method === 'shekel' && value > kiloPrice) {
        invalid = `الخصم لا يزيد عن سعر الكيلو (${formatMoney(kiloPrice)} ₪).`;
    }

    // Once: what comes off the balance now; it may not take the balance below zero.
    const discount = isStanding || invalid ? null : discountAmount(data.method, data.value, owed, subscription.kiloPrice);

    if (!isStanding && value > 0 && !invalid) {
        if (owed <= 0) {
            invalid = 'لا يوجد رصيد مستحق على المشترك ليُخصم منه.';
        } else if (discount > owed) {
            invalid = `لا يمكن أن يزيد الخصم (${formatMoney(discount)} ₪) عن الرصيد المستحق (${formatMoney(owed)} ₪).`;
        }
    }

    const balanceAfter = discount === null || invalid ? null : Number(balance) - discount;

    // Standing: the last week read, or 10 kilos, at the subscription's prices, billed without and with it.
    const hasLastReading = Number(subscription.lastConsumption) > 0;
    const example = {
        kilos: hasLastReading ? Number(subscription.lastConsumption) : 10,
        unitPrice: subscription.kiloPrice,
        minimumPayment: subscription.minimumPayment,
        label: hasLastReading ? 'يدفع عن آخر قراءة' : 'يدفع عن 10 كيلو (مثال)',
    };
    const exampleBill = weeklyCharges(example.kilos, example.unitPrice, example.minimumPayment);
    const exampleWithDiscount =
        isStanding && value > 0 && !invalid ? weeklyCharges(example.kilos, example.unitPrice, example.minimumPayment, data) : exampleBill;
    const weeklySaving = Math.max(0, exampleBill.amountDue - exampleWithDiscount.amountDue);

    function close() {
        setDiscarding(false);
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

    function changeKind(kind) {
        // Switching to the standing kind starts from the subscription's current one, to change it,
        // or else from their customer segment on the tariff, if they have one.
        const current = kind === 'standing' ? standingDiscount : null;
        const segment = current ? (current.segment ?? '') : kind === 'standing' ? (subscription.tariffSegmentName ?? '') : '';

        setData((previous) => ({
            ...previous,
            kind,
            method: kind === 'once' ? 'shekel' : (current?.method ?? previous.method),
            value: current ? formatAmount(current.value) : '',
            segment,
            notes: current?.notes ?? '',
        }));
        form.clearErrors();
    }

    function changeMethod(method) {
        setData((current) => ({ ...current, method, value: '' }));
        form.clearErrors('value');
        valueInput.current?.focus();
    }

    function setValue(next) {
        setData('value', String(next));
        form.clearErrors('value');
        valueInput.current?.focus();
    }

    function stopStandingDiscount() {
        setConfirmingStop(false);
        router.delete(`/subscriptions/${subscription.id}/standing-discount`, {
            preserveScroll: true,
            onStart: () => setStopping(true),
            onFinish: () => setStopping(false),
            onSuccess: () => {
                form.resetAndClearErrors();
                onClose();
            },
        });
    }

    /** 1 · 2 · 3 pick the method — unless a field is being typed in. */
    function onKeyDown(event) {
        submitOnCtrlEnter(event);

        const typing = event.target.matches('textarea, input:not([type=radio])');
        const method = methods[Number(event.key) - 1];

        if (!typing && !event.ctrlKey && !event.metaKey && !event.altKey && method) {
            event.preventDefault();
            changeMethod(method);
        }
    }

    function submit(event) {
        event.preventDefault();

        if (!validateFormFields(event.currentTarget, form) || invalid) {
            return;
        }

        setConfirmingSave(true);
    }

    function save() {
        setConfirmingSave(false);

        const recorded = isStanding
            ? {
                  standing: true,
                  terms: standingTerms(data.method, data.value),
                  segment: data.segment.trim(),
                  weekly: `${formatMoney(exampleWithDiscount.amountDue)} ₪ بدلًا من ${formatMoney(exampleBill.amountDue)} ₪`,
              }
            : {
                  standing: false,
                  method: 'مبلغ ثابت بالشيكل',
                  discount,
                  balanceAfter,
              };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setReceipt(recorded);
                form.resetAndClearErrors();
            },
        };

        // A standing discount is saved on its own address.
        if (isStanding) {
            form.put(`/subscriptions/${subscription.id}/standing-discount`, options);
        } else {
            form.save(options);
        }
    }

    const doneRows = !receipt
        ? []
        : receipt.standing
          ? [
                ['الخصم', receipt.terms],
                ['التصنيف', receipt.segment || '—'],
                ['قراءة الأسبوع', receipt.weekly],
            ]
          : [
                ['الطريقة', receipt.method],
                ['قيمة الخصم', `${formatMoney(receipt.discount)} ₪`],
                ['الرصيد بعد', balanceText(describeBalance(receipt.balanceAfter))],
            ];

    return (
        <>
            <Modal show={show} onClose={requestClose} maxWidth="5xl">
                {receipt ? (
                    <DoneScreen
                        tone={correcting ? 'amber' : 'green'}
                        title={receipt.standing ? 'فُعّل خصم القراءات الأسبوعية' : correcting ? 'صُحّح الخصم' : 'أُضيف الخصم'}
                        text={
                            receipt.standing
                                ? 'يُخصم تلقائيًا من كل قراءة أسبوعية تُدخل من الآن.'
                                : `نزل ${formatMoney(receipt.discount)} ₪ من حساب ${subscription.fullName}.`
                        }
                        rows={doneRows}
                        anotherLabel="خصم آخر"
                        onAnother={correcting || receipt.standing ? null : () => setReceipt(null)}
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
                            icon={correcting ? 'repeat' : 'tag'}
                            tone={correcting ? 'amber' : 'green'}
                            title={correcting ? 'تصحيح خصم' : 'إضافة خصم'}
                            subtitle={
                                correcting
                                    ? 'صحّح الخصم؛ يبقى الأصلي في الكشف ملغى مع سبب التصحيح.'
                                    : 'يُنزَّل مما على المشترك، مرة واحدة أو كل أسبوع.'
                            }
                            onClose={requestClose}
                        />

                        {/* On phones the fields and the summary scroll together; side by side they each scroll alone. */}
                        <div className="min-h-0 flex-1 overflow-y-auto lg:contents">
                            <div className="grid grid-cols-1 content-start gap-[22px] px-5 pb-3 pt-5 sm:px-6 lg:col-start-1 lg:row-start-2 lg:min-h-0 lg:overflow-y-auto">
                                <SubscriptionStrip
                                    subscription={subscription}
                                    balance={balance}
                                    balanceLabel={correcting ? 'الرصيد بدون الخصم الأصلي' : 'الرصيد الحالي'}
                                />

                                {correcting ? (
                                    <OriginalLine entry={correcting} />
                                ) : (
                                    <fieldset>
                                        <legend className="sr-only">نوع الخصم</legend>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {KINDS.map((kind) => (
                                                <ChoiceTile
                                                    key={kind.value}
                                                    name="kind"
                                                    value={kind.value}
                                                    checked={data.kind === kind.value}
                                                    onChange={changeKind}
                                                    icon={kind.icon}
                                                    title={kind.title}
                                                    hint={kind.hint}
                                                    tone="green"
                                                />
                                            ))}
                                        </div>
                                    </fieldset>
                                )}

                                {isStanding && standingDiscount && (
                                    <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-dashed border-emerald-600/45 bg-emerald-500/10 px-3.5 py-3 text-sm">
                                        <Icon name="repeat" className="h-[18px] w-[18px] shrink-0 text-emerald-700 dark:text-emerald-400" />
                                        <div className="min-w-0 flex-1">
                                            <b className="font-bold text-gray-900">
                                                لديه خصم على القراءات الأسبوعية: {withSegment(standingDiscount.terms, standingDiscount.segment)}
                                            </b>
                                            <small className="block text-[12.5px] text-gray-500">
                                                منذ <bdi dir="ltr">{standingDiscount.grantedAt}</bdi>
                                                {standingDiscount.grantedByName && ` · أعطاه ${standingDiscount.grantedByName}`}. الخصم الجديد يحلّ
                                                محلّه.
                                            </small>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setConfirmingStop(true)}
                                            disabled={stopping}
                                            className="h-9 shrink-0 rounded-xl border border-gray-200 bg-surface px-3 text-[13.5px] font-bold text-brand-600 transition hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            {stopping ? 'جارٍ الإيقاف...' : 'إيقاف'}
                                        </button>
                                    </div>
                                )}

                                {isStanding && (
                                    <fieldset>
                                        <legend className="contents">
                                            <FieldLabel required hint="اختر بالأرقام 1 · 2 · 3">
                                                طريقة الخصم
                                            </FieldLabel>
                                        </legend>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            {methods.map((method, index) => {
                                                const look = fieldFor(data.kind, method, kiloPrice);

                                                return (
                                                    <ChoiceTile
                                                        key={method}
                                                        name="method"
                                                        value={method}
                                                        checked={data.method === method}
                                                        onChange={changeMethod}
                                                        icon={METHOD_ICONS[method]}
                                                        title={look.title}
                                                        hint={look.tileHint}
                                                        tone="green"
                                                        stacked
                                                        shortcut={index + 1}
                                                    />
                                                );
                                            })}
                                        </div>
                                        <InputError message={errors.method} className="mt-2" />
                                    </fieldset>
                                )}
                                {!isStanding && <InputError message={errors.method} />}

                                <div>
                                    <FieldLabel htmlFor="value" required hint={field.hint}>
                                        {field.label}
                                    </FieldLabel>
                                    <AmountBox
                                        id="value"
                                        inputRef={valueInput}
                                        value={data.value}
                                        onChange={(e) => setData('value', normalizeDecimalInput(e.target.value))}
                                        unit={field.unit}
                                        label="قيمة الخصم"
                                        autoFocus
                                        error={Boolean(errors.value || invalid)}
                                    >
                                        {QUICK_VALUES[data.kind][data.method].map((quick) => (
                                            <QuickPick key={quick} onClick={() => setValue(quick)}>
                                                {quick}
                                                {field.suffix}
                                            </QuickPick>
                                        ))}
                                        {!isStanding && owed > 0 && (
                                            <QuickPick tone="green" onClick={() => setValue(owed)}>
                                                كل ما عليه
                                            </QuickPick>
                                        )}
                                        {isStanding && value > 0 && !invalid && (
                                            <span className="ms-auto text-[13.5px] text-gray-500">
                                                يوفّر{' '}
                                                <b className="font-display text-emerald-700 dark:text-emerald-400">{formatMoney(weeklySaving)} ₪</b>{' '}
                                                في الأسبوع
                                            </span>
                                        )}
                                    </AmountBox>
                                    <InputError message={errors.value ?? invalid} className="mt-2" />
                                    {isStanding && value > 0 && !invalid && exampleWithDiscount.amountDue < Number(example.minimumPayment) && (
                                        <FieldWarning>
                                            مع خصم القراءات الأسبوعية لا يُطبَّق الحد الأدنى للأسبوع ({formatMoney(example.minimumPayment)} ₪): يدفع
                                            المشترك ثمن الكيلوات بعد الخصم فقط.
                                        </FieldWarning>
                                    )}
                                </div>

                                {isStanding && (
                                    <div>
                                        <FieldLabel htmlFor="discount_segment" hint="لمن هذا الخصم؟ يفيد في التقارير">
                                            تصنيف الزبون
                                        </FieldLabel>
                                        <input
                                            id="discount_segment"
                                            name="segment"
                                            maxLength={100}
                                            autoComplete="off"
                                            placeholder="مثال: موظفو أبو زايد"
                                            value={data.segment}
                                            onChange={(e) => setData('segment', e.target.value)}
                                            className={inputClass}
                                        />
                                        {discountSegments.length > 0 && (
                                            <div className="mt-2.5 flex flex-wrap gap-2">
                                                {discountSegments.map((segment) => (
                                                    <button
                                                        key={segment}
                                                        type="button"
                                                        aria-pressed={data.segment === segment}
                                                        onClick={() => setData('segment', data.segment === segment ? '' : segment)}
                                                        className={`rounded-full border px-3 py-1 text-[13.5px] font-semibold transition ${
                                                            data.segment === segment
                                                                ? 'border-gray-900 bg-gray-900 text-surface'
                                                                : 'border-gray-100 bg-gray-50 text-gray-700 hover:border-gray-200 hover:text-gray-900'
                                                        }`}
                                                    >
                                                        {segment}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                        <InputError message={errors.segment} className="mt-2" />
                                    </div>
                                )}

                                <div>
                                    <FieldLabel htmlFor="discount_notes">
                                        ملاحظات
                                    </FieldLabel>
                                    <textarea
                                        id="discount_notes"
                                        name="notes"
                                        rows={2}
                                        maxLength={1000}
                                        placeholder="مثال: خصم بموافقة المدير"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        className={`${inputClass} h-auto min-h-[76px] resize-y py-3 leading-relaxed`}
                                    />
                                    <InputError message={errors.notes} className="mt-2" />
                                </div>

                                {correcting && <CorrectionReasonFields form={form} reasons={correctionReasons} />}
                            </div>

                            {isStanding ? (
                                <SummaryPanel title="ملخص خصم القراءات الأسبوعية" tag="كل أسبوع">
                                    <SummaryFigure
                                        label={example.label}
                                        value={formatMoney(exampleWithDiscount.amountDue)}
                                        tone="green"
                                        note={`بدلًا من ${formatMoney(exampleBill.amountDue)} ₪ · ${formatAmount(example.kilos)} كيلو × ${formatMoney(example.unitPrice)} ₪`}
                                    />
                                    <SummaryLedger
                                        rows={[
                                            ['الخصم', value > 0 && !invalid ? standingTerms(data.method, data.value) : '—'],
                                            ['التصنيف', data.segment.trim() || '—'],
                                        ]}
                                        result={{
                                            label: 'يبدأ من',
                                            value: 'القراءة القادمة',
                                            className: 'text-[15px] text-white',
                                        }}
                                    />
                                    <div className="relative grid grid-cols-2 gap-2.5">
                                        <div className="rounded-2xl border border-white/10 bg-white/5 p-3">
                                            <small className="block text-[12.5px] text-white/60">توفير في الأسبوع</small>
                                            <b className="block text-end font-display text-[22px] font-extrabold text-emerald-300" dir="ltr">
                                                {formatMoney(weeklySaving)} ₪
                                            </b>
                                        </div>
                                        <div className="rounded-2xl border border-white/10 bg-white/5 p-3">
                                            <small className="block text-[12.5px] text-white/60">توفير في الشهر تقريبًا</small>
                                            <b className="block text-end font-display text-[22px] font-extrabold text-emerald-300" dir="ltr">
                                                {formatMoney(weeklySaving * WEEKS_PER_MONTH)} ₪
                                            </b>
                                        </div>
                                    </div>
                                </SummaryPanel>
                            ) : (
                                <SummaryPanel title="ملخص الخصم" tag="مرة واحدة">
                                    <SummaryFigure label="قيمة الخصم" value={`${formatMoney(discount ?? 0)}`} tone="green" />
                                    <SummaryLedger
                                        rows={[
                                            [correcting ? 'الرصيد بدون الأصلي' : 'الرصيد الحالي', balanceText(describeBalance(balance))],
                                            ['خصم', `${formatMoney(discount ?? 0)} ₪`, 'green'],
                                        ]}
                                        result={balanceAfterRow('الرصيد بعد الخصم', balanceAfter)}
                                    />
                                </SummaryPanel>
                            )}
                        </div>

                        <FormFooter
                            onCancel={requestClose}
                            tone={correcting ? 'amber' : 'green'}
                            disabled={value <= 0 || Boolean(invalid)}
                            processing={form.processing}
                            submitLabel={
                                correcting
                                    ? 'حفظ التصحيح'
                                    : isStanding
                                      ? standingDiscount
                                          ? 'استبدال خصم القراءات الأسبوعية'
                                          : 'تفعيل خصم القراءات الأسبوعية'
                                      : discount
                                        ? `خصم ${formatMoney(discount)} ₪`
                                        : 'إضافة الخصم'
                            }
                            shortcuts={isStanding ? '1 · 2 · 3 للطريقة' : undefined}
                        />
                    </form>
                )}
            </Modal>

            <ConfirmDialog
                show={show && discarding}
                onConfirm={close}
                onCancel={() => setDiscarding(false)}
                title="تجاهل الخصم؟"
                message="أدخلت بيانات لم تُحفظ بعد. إذا أغلقت النافذة الآن فستفقدها."
                confirmLabel="تجاهل الخصم"
                cancelLabel="البقاء ومتابعة الإدخال"
                icon="alert"
                tone="danger"
            />

            <ConfirmDialog
                show={show && confirmingStop}
                onConfirm={stopStandingDiscount}
                onCancel={() => setConfirmingStop(false)}
                title="إيقاف خصم القراءات الأسبوعية؟"
                message={
                    standingDiscount &&
                    `لن يُخصم (${withSegment(standingDiscount.terms, standingDiscount.segment)}) من قراءات ${subscription.fullName} التي تُدخل بعد الآن. القراءات السابقة تحتفظ بخصمها.`
                }
                confirmLabel="نعم، أوقف الخصم"
                cancelLabel="إبقاء الخصم"
                icon="alert"
                tone="danger"
            />

            <ConfirmDialog
                show={show && confirmingSave}
                onConfirm={save}
                onCancel={() => setConfirmingSave(false)}
                title={correcting ? 'تأكيد حفظ التصحيح؟' : 'تأكيد إضافة الخصم؟'}
                message={isStanding
                        ? `سيُفعَّل خصم القراءات الأسبوعية (${standingTerms(data.method, data.value)}) لـ ${subscription.fullName} على القراءات التي تُدخل من الآن. هل تريد المتابعة؟`
                        : `${correcting ? 'سيُصحَّح الخصم إلى' : 'سينزل'} ${formatMoney(discount ?? 0)} ₪ من حساب ${subscription.fullName}. هل تريد المتابعة؟`}
                confirmLabel={correcting ? 'نعم، احفظ التصحيح' : 'نعم، أضف الخصم'}
                cancelLabel="رجوع للمراجعة"
                icon="check"
            />
        </>
    );
}
