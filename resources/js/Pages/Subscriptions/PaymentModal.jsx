import { useEffect, useId, useRef, useState } from 'react';
import { useHttp, usePage } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import { useResourceForm } from '@/hooks/useResourceForm';
import { paymentInShekels } from '@/lib/accountStatement';
import { overpaymentLevel } from '@/lib/overpayment';
import { formatMoney, normalizeDecimalInput } from '@/lib/format';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';
import { FieldLabel, SubscriptionStrip } from './AccountFormParts';
import { CorrectionReasonFields, EMPTY_CORRECTION, OriginalLine } from './CorrectionFields';
import { inputClass, MethodTile, PaymentReceipt, PaymentSummary, TransferFields } from './PaymentModalParts';

export { inputClass };

const QUICK_AMOUNTS = [50, 100, 200];


/**
 * Record a payment on a subscription's account: how much, in shekels, and
 * how it was paid — in cash, or
 * by a transfer to one of the company's banks or e-wallets, with who sent
 * it and its reference. A dark panel beside the form shows what it does to
 * the balance before saving; once saved, the window shows the receipt with
 * the voucher number. Keys: Ctrl + Enter saves, 1 and 2 pick the method.
 *
 * With `correcting` (a statement line), it corrects that payment instead:
 * the form starts from it, `balance` leaves it out, and saving cancels it
 * and records this one in its place, under a new voucher number, with one
 * of `correctionReasons`.
 */
export default function PaymentModal({
    show,
    onClose,
    subscription,
    balance,
    paymentMethods,
    transferBanks,
    senderBanks,
    correcting = null,
    correctionReasons = [],
}) {
    const titleId = useId();
    const amountInput = useRef(null);
    const collector = usePage().props.auth?.user?.name ?? '';
    const currencies = usePage().props.paymentOptions?.currencies ?? [{ code: 'ILS', label: 'شيكل', rate: '1' }];
    const recorded = correcting?.recorded;
    const form = useResourceForm(
        correcting ? `/subscriptions/${subscription.id}/transactions` : `/subscriptions/${subscription.id}/payments`,
        correcting,
        recorded
            ? {
                  amount: String(recorded.amount),
                  currency: recorded.currency,
                  payment_method: paymentMethods.some((method) => method.value === recorded.payment_method)
                      ? recorded.payment_method
                      : 'bank_transfer',
                  bank_name: recorded.bank_name === 'بال باي' ? 'محفظة بالباي' : recorded.bank_name,
                  sender_bank_name: recorded.sender_bank_name ?? '',
                  bank_received_on: recorded.bank_received_on ?? '',
                  sender_name: recorded.sender_name || subscription.fullName,
                  reference_number: recorded.reference_number,
                  confirm_duplicate_reference: false,
                  confirm_overpayment: false,
                  cash_box: recorded.cash_box,
                  manual_voucher_number: recorded.manual_voucher_number,
                  notes: recorded.notes,
                  ...EMPTY_CORRECTION,
              }
            : {
                  amount: '',
                  currency: 'ILS',
                  // A bank transfer when the user may take one, else the way they may.
                  payment_method: paymentMethods.some((method) => method.value === 'bank_transfer') ? 'bank_transfer' : (paymentMethods[0]?.value ?? 'bank_transfer'),
                  bank_name: '',
                  sender_bank_name: '',
                  bank_received_on: '',
                  // Who the transfer came from: the subscription unless someone else paid.
                  sender_name: subscription.fullName,
                  reference_number: '',
                  confirm_duplicate_reference: false,
                  confirm_overpayment: false,
                  cash_box: '',
                  manual_voucher_number: '',
                  notes: '',
              },
    );
    const { data, setData, errors } = form;
    const [senderIsSubscriber, setSenderIsSubscriber] = useState(!recorded?.sender_name || recorded.sender_name === subscription.fullName);
    const [detailsOpen, setDetailsOpen] = useState(Boolean(recorded?.notes));
    const [discarding, setDiscarding] = useState(false);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [receipt, setReceipt] = useState(null);
    const [referenceStatus, setReferenceStatus] = useState(null);
    const referenceCheck = useHttp();

    const throughBank = data.payment_method === 'bank_transfer';
    const selectedCurrency = currencies.find((currency) => currency.code === data.currency);
    const exchangeRate = selectedCurrency?.rate;
    const inShekels = paymentInShekels(data.amount, data.currency, exchangeRate);
    const currencyLabel = selectedCurrency?.label ?? data.currency;
    const currencyMark = data.currency === 'ILS' ? '₪' : data.currency;
    const owed = Number(balance) > 0 ? Number(balance) : 0;
    // More than is owed leaves the subscription in credit; far more is probably a slip and must be confirmed.
    const overpayment = overpaymentLevel(inShekels, owed);
    const methodText = throughBank
        ? data.bank_name
            ? `تحويل ${data.sender_bank_name ? `من ${data.sender_bank_name} ` : ''}إلى ${data.bank_name}`
            : 'تحويل بنكي'
        : 'نقد';
    const methods = ['bank_transfer', 'cash'].filter((method) => paymentMethods.some((option) => option.value === method));
    const detailErrors = Boolean(errors.notes);

    useEffect(() => {
        const reference = data.reference_number.trim();

        if (!show || !throughBank || reference === '') {
            referenceCheck.cancel();
            setReferenceStatus(null);

            return undefined;
        }

        const timer = setTimeout(() => {
            const query = new URLSearchParams({
                reference_number: reference,
                amount: data.amount,
                currency: data.currency,
                sender_name: data.sender_name,
            });

            if (correcting?.id) {
                query.set('ignore_transaction_id', correcting.id);
            }

            referenceCheck
                .get(`/subscriptions/${subscription.id}/payments/reference-status?${query}`)
                .then((status) => setReferenceStatus(status ?? null))
                .catch(() => setReferenceStatus(null));
        }, 400);

        return () => {
            clearTimeout(timer);
            referenceCheck.cancel();
        };
    }, [show, throughBank, data.reference_number, data.amount, data.currency, data.sender_name, correcting?.id, subscription.id]);

    function close() {
        setDiscarding(false);
        setReceipt(null);
        setDetailsOpen(false);
        setSenderIsSubscriber(true);
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

    function setAmount(value) {
        setData((current) => ({ ...current, amount: value, confirm_overpayment: false }));
        form.clearErrors('amount');
        amountInput.current?.focus();
    }

    function setCurrency(code) {
        setData((current) => ({ ...current, currency: code, confirm_overpayment: false }));
        form.clearErrors('currency', 'exchange_rate');
    }

    function toggleSender(isSubscription) {
        setSenderIsSubscriber(isSubscription);
        setData('sender_name', isSubscription ? subscription.fullName : '');
        form.clearErrors('sender_name');

        if (!isSubscription) {
            requestAnimationFrame(() => document.getElementById('sender_name')?.focus());
        }
    }

    /** 1 picks the transfer, 2 cash — unless a field is being typed in. */
    function onKeyDown(event) {
        submitOnCtrlEnter(event);

        const typing = event.target.matches('textarea, select, input:not([type=radio]):not([type=checkbox])');

        if (!typing && !event.ctrlKey && !event.metaKey && !event.altKey && ['1', '2'].includes(event.key)) {
            const method = methods[Number(event.key) - 1];

            if (method) {
                event.preventDefault();
                setData('payment_method', method);
            }
        }
    }

    function submit(event) {
        event.preventDefault();

        if (!validateFormFields(event.currentTarget, form)) {
            return;
        }

        if (!(Number(data.amount) > 0)) {
            form.setError('amount', 'أدخل مبلغًا أكبر من صفر.');
            amountInput.current?.focus();

            return;
        }

        setConfirmingSave(true);
    }

    function save() {
        setConfirmingSave(false);

        const recorded = { amount: data.amount, currency: data.currency, inShekels, methodText };

        form.save({
            preserveScroll: true,
            onSuccess: (page) => {
                const flashed = page.flash?.recordedPayment ?? {};

                setReceipt({
                    ...recorded,
                    amount: flashed.amount ?? recorded.amount,
                    currency: flashed.currency ?? recorded.currency,
                    inShekels: flashed.inShekels ?? recorded.inShekels,
                    voucherNumber: flashed.voucherNumber ?? null,
                    receiptUrl: flashed.receiptUrl ?? null,
                    balanceAfter: flashed.balance ?? Number(balance) - (inShekels ?? 0),
                });
                form.resetAndClearErrors();
                setSenderIsSubscriber(true);
                setDetailsOpen(false);
            },
        });
    }

    return (
        <>
            <Modal show={show} onClose={requestClose} maxWidth="5xl" panelClassName="!overflow-clip">
                {receipt ? (
                    <div role="dialog" aria-modal="true" aria-label={correcting ? 'صُحّحت الدفعة' : 'سُجّلت الدفعة'}>
                        <PaymentReceipt
                            receipt={receipt}
                            subscriptionName={subscription.fullName}
                            title={correcting ? 'صُحّحت الدفعة' : 'سُجّلت الدفعة'}
                            onAnother={correcting ? null : () => setReceipt(null)}
                            onDone={close}
                        />
                    </div>
                ) : (
                    <form
                        noValidate
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        onSubmit={submit}
                        onInput={(e) => clearErrorOnInput(e, form)}
                        onKeyDown={onKeyDown}
                        className="flex max-h-[calc(100dvh-6rem)] flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_340px] lg:grid-rows-[auto_minmax(0,1fr)_auto]"
                    >
                        <div className="flex shrink-0 items-center gap-3.5 border-b border-gray-100 px-5 py-4 sm:px-6 sm:py-5 lg:col-start-1 lg:row-start-1">
                            <span
                                className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px] text-white shadow-sm ${
                                    correcting ? 'bg-gradient-to-br from-amber-500 to-amber-700' : 'bg-graphite-gradient'
                                }`}
                            >
                                <Icon name={correcting ? 'repeat' : 'card'} className="h-[18px] w-[18px]" strokeWidth={1.8} />
                            </span>
                            <div className="min-w-0">
                                <h3 id={titleId} className="font-luxe text-[22px] font-bold leading-tight text-gray-900">
                                    {correcting ? 'تصحيح دفعة' : 'تسجيل دفعة'}
                                </h3>
                                <p className="mt-0.5 text-[13.5px] text-gray-500">
                                    {correcting
                                        ? 'صحّح الدفعة؛ تبقى الأصلية في الكشف ملغاة مع سبب التصحيح.'
                                        : 'سجّل المبلغ المستلم، ويُحدَّث الرصيد تلقائيًا.'}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={requestClose}
                                aria-label="إغلاق"
                                className="ms-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-gray-100 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                            >
                                <Icon name="close" className="h-[18px] w-[18px]" strokeWidth={2} />
                            </button>
                        </div>

                        {/* On phones the fields and the summary scroll together; side by side they each scroll alone. */}
                        <div className="min-h-0 flex-1 overflow-y-auto lg:contents">
                            <div className="grid grid-cols-1 content-start gap-[22px] px-5 pb-3 pt-5 sm:px-6 lg:col-start-1 lg:row-start-2 lg:min-h-0 lg:overflow-y-auto">
                                <SubscriptionStrip
                                    subscription={subscription}
                                    balance={balance}
                                    balanceLabel={correcting ? 'الرصيد بدون الدفعة الأصلية' : 'الرصيد الحالي'}
                                />

                                {correcting && <OriginalLine entry={correcting} />}

                                <div>
                                    <FieldLabel htmlFor="amount" required>
                                        المبلغ المستلم
                                    </FieldLabel>
                                    <div
                                        className={`rounded-[22px] border-[1.5px] bg-surface px-[18px] pb-3.5 pt-4 transition ${
                                            errors.amount
                                                ? 'border-brand-500 shadow-[0_0_0_5px_rgb(var(--brand-500)/0.08)]'
                                                : 'border-gray-200 hover:border-brand-500/45 focus-within:border-brand-500 focus-within:shadow-[0_0_0_5px_rgb(var(--brand-500)/0.12)]'
                                        }`}
                                    >
                                        <div className="flex items-center gap-3">
                                            <input
                                                ref={amountInput}
                                                id="amount"
                                                name="amount"
                                                required
                                                autoFocus
                                                inputMode="decimal"
                                                autoComplete="off"
                                                placeholder="0.00"
                                                dir="ltr"
                                                aria-describedby={errors.amount ? `${titleId}-amount-error` : undefined}
                                                value={data.amount}
                                                onChange={(e) => setData((current) => ({ ...current, amount: normalizeDecimalInput(e.target.value), confirm_overpayment: false }))}
                                                className="min-w-0 flex-1 border-0 bg-transparent p-0 text-end font-display text-[38px] font-extrabold leading-tight text-gray-900 placeholder:text-gray-300 focus:outline-none focus:ring-0 sm:text-[44px]"
                                            />
                                            <div className="w-40 shrink-0 sm:w-44">
                                                <label htmlFor="payment-currency" className="sr-only">عملة الدفع</label>
                                                <SearchableSelect
                                                    id="payment-currency"
                                                    name="currency"
                                                    required
                                                    value={data.currency}
                                                    onChange={setCurrency}
                                                    options={currencies.map((currency) => ({ value: currency.code, label: `${currency.label} (${currency.code})` }))}
                                                    searchable={false}
                                                    clearable={false}
                                                />
                                            </div>
                                        </div>
                                        {data.currency !== 'ILS' && <p className={`mt-3 text-sm ${Number(exchangeRate) > 0 ? 'text-gray-500' : 'text-brand-600'}`} role="status">{Number(exchangeRate) > 0 ? <>سعر الصرف المعتمد: 1 {currencyLabel} = <bdi className="font-display font-semibold">{Number(exchangeRate)}</bdi> شيكل</> : 'لم يُضبط سعر لهذه العملة؛ يجب ضبطه من صفحة العملات وأسعار الصرف أولًا.'}</p>}
                                        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-gray-200 pt-3">
                                            {QUICK_AMOUNTS.map((quick) => (
                                                <button
                                                    key={quick}
                                                    type="button"
                                                    onClick={() => setAmount(String(quick))}
                                                    className="rounded-full border border-gray-100 bg-gray-50 px-3 py-1 font-display text-[13.5px] font-semibold text-gray-700 transition hover:border-gray-200 hover:bg-surface hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                                                >
                                                    {quick}
                                                </button>
                                            ))}
                                            {owed > 0 && (
                                                <button
                                                    type="button"
                                                    disabled={data.currency !== 'ILS' && !(Number(exchangeRate) > 0)}
                                                    onClick={() => setAmount(String(Number((owed / (data.currency === 'ILS' ? 1 : Number(exchangeRate))).toFixed(2))))}
                                                    className="rounded-full border border-emerald-500/40 bg-emerald-500/10 px-3 py-1 text-[13.5px] font-semibold text-emerald-700 transition hover:bg-emerald-500/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 dark:text-emerald-400"
                                                >
                                                    تسديد كامل الدين
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <InputError message={errors.currency} className="mt-2" />
                                    <InputError message={errors.exchange_rate} className="mt-2" />
                                    {errors.amount && (
                                        <p
                                            id={`${titleId}-amount-error`}
                                            role="alert"
                                            className="mt-2 flex items-center gap-1.5 text-[13.5px] text-brand-600"
                                        >
                                            <Icon name="info" className="h-[18px] w-[18px]" />
                                            {errors.amount}
                                        </p>
                                    )}
                                    {overpayment !== 'none' && (
                                        <div
                                            role={overpayment === 'confirm' ? 'alert' : 'status'}
                                            className={`mt-3 rounded-xl border px-4 py-3 text-sm ${
                                                overpayment === 'confirm'
                                                    ? 'border-brand-500/40 bg-brand-500/[0.07] text-gray-900'
                                                    : 'border-amber-500/40 bg-amber-500/[0.08] text-gray-800'
                                            }`}
                                        >
                                            <p className="flex items-start gap-2 font-medium">
                                                <Icon name="alert" className="mt-0.5 h-[18px] w-[18px] shrink-0" />
                                                <span>
                                                    {overpayment === 'confirm' ? 'المبلغ أكبر بكثير من المستحق' : 'المبلغ أكبر من المستحق'} على المشترك (
                                                    <bdi className="font-display">{formatMoney(owed)}</bdi> ₪)، وسيبقى له رصيد دائن قدره{' '}
                                                    <bdi className="font-display font-bold">{formatMoney((inShekels ?? 0) - owed)}</bdi> ₪.
                                                    {overpayment === 'confirm' && ' تأكد أنك لم تكتب رقمًا زائدًا بالخطأ.'}
                                                </span>
                                            </p>
                                            {overpayment === 'confirm' && (
                                                <label className="mt-2 flex cursor-pointer items-center gap-2 font-semibold">
                                                    <input
                                                        type="checkbox"
                                                        className="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                                        checked={data.confirm_overpayment}
                                                        onChange={(e) => setData('confirm_overpayment', e.target.checked)}
                                                    />
                                                    أؤكد أن المبلغ <bdi className="font-display">{formatMoney(data.amount)}</bdi> {currencyMark} صحيح
                                                </label>
                                            )}
                                            <InputError message={errors.confirm_overpayment} className="mt-2" />
                                        </div>
                                    )}
                                    {data.currency !== 'ILS' && inShekels !== null && <p role="status" className="mt-3 text-sm text-gray-600">القيمة المسجلة على حساب المشترك: <bdi className="font-display font-semibold">{formatMoney(inShekels)}</bdi> شيكل.</p>}
                                </div>

                                <fieldset>
                                    <legend className="mb-2 text-[14.5px] font-semibold text-gray-700">
                                        طريقة الدفع <span className="text-brand-600">*</span>
                                    </legend>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {methods.map((method) =>
                                            method === 'bank_transfer' ? (
                                                <MethodTile
                                                    key={method}
                                                    value={method}
                                                    checked={throughBank}
                                                    onChange={(value) => setData('payment_method', value)}
                                                    icon="bank"
                                                    title="تحويل بنكي أو محفظة"
                                                    hint={transferBanks.join('، ')}
                                                />
                                            ) : (
                                                <MethodTile
                                                    key={method}
                                                    value={method}
                                                    checked={data.payment_method === method}
                                                    onChange={(value) => setData('payment_method', value)}
                                                    icon="banknotes"
                                                    title="نقد"
                                                    hint="استُلم المبلغ نقدًا"
                                                />
                                            ),
                                        )}
                                    </div>
                                    <InputError message={errors.payment_method} className="mt-2" />

                                    {throughBank && (
                                        <TransferFields
                                            data={data}
                                            setData={setData}
                                            errors={errors}
                                            transferBanks={transferBanks}
                                            senderBanks={senderBanks}
                                            senderIsSubscriber={senderIsSubscriber}
                                            onSenderToggle={toggleSender}
                                            checkingReference={referenceCheck.processing}
                                            referenceStatus={referenceStatus}
                                        />
                                    )}

                                    {!throughBank && (
                                        <div className="animate-menu mt-3 grid gap-4 rounded-[20px] border border-gray-100 bg-gray-50 p-4">
                                            <div>
                                                <FieldLabel htmlFor="manual_voucher_number" hint="رقم الوصل الورقي">
                                                    رقم السند اليدوي
                                                </FieldLabel>
                                                <input
                                                    id="manual_voucher_number"
                                                    name="manual_voucher_number"
                                                    dir="ltr"
                                                    autoComplete="off"
                                                    placeholder="مثال: 00412"
                                                    value={data.manual_voucher_number}
                                                    onChange={(e) => setData('manual_voucher_number', e.target.value)}
                                                    className={`${inputClass} text-end font-display placeholder:font-sans placeholder:font-normal`}
                                                />
                                                <InputError message={errors.manual_voucher_number} className="mt-2" />
                                            </div>
                                        </div>
                                    )}
                                </fieldset>

                                {correcting && <CorrectionReasonFields form={form} reasons={correctionReasons} />}

                                <div>
                                    <button
                                        type="button"
                                        aria-expanded={detailsOpen || detailErrors}
                                        aria-controls={`${titleId}-details`}
                                        onClick={() => setDetailsOpen(!(detailsOpen || detailErrors))}
                                        className="flex items-center gap-2 rounded-lg text-start text-[14.5px] font-semibold text-gray-600 transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                    >
                                        <Icon
                                            name="chevron-down"
                                            className={`h-[18px] w-[18px] shrink-0 transition-transform ${detailsOpen || detailErrors ? 'rotate-180' : ''}`}
                                            strokeWidth={2}
                                        />
                                        تفاصيل إضافية <span className="font-medium text-gray-500">(اختياري: ملاحظة)</span>
                                    </button>
                                    {(detailsOpen || detailErrors) && (
                                        <div id={`${titleId}-details`} className="animate-menu mt-3.5 grid gap-3.5">
                                            <div>
                                                <FieldLabel htmlFor="payment_notes" hint="تظهر في كشف الحساب">
                                                    ملاحظة
                                                </FieldLabel>
                                                <input
                                                    id="payment_notes"
                                                    name="notes"
                                                    autoComplete="off"
                                                    placeholder="أي تفصيل يُحفظ مع الدفعة"
                                                    value={data.notes}
                                                    onChange={(e) => setData('notes', e.target.value)}
                                                    className={inputClass}
                                                />
                                                <InputError message={errors.notes} className="mt-2" />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <PaymentSummary
                                amount={data.amount}
                                currency={data.currency}
                                inShekels={inShekels}
                                balance={balance}
                                methodText={methodText}
                                collector={collector}
                            />
                        </div>

                        <div className="flex shrink-0 items-center gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:px-6 lg:col-start-1 lg:row-start-3">
                            <SecondaryButton onClick={requestClose} className="h-12 rounded-[14px] px-5 text-[15.5px]">
                                إلغاء
                            </SecondaryButton>
                            <span className="hidden items-center gap-1.5 text-[13px] text-gray-500 xl:flex">
                                <span className="kbd" dir="ltr">
                                    Ctrl + Enter
                                </span>
                                للحفظ ·<span className="kbd">1</span>
                                <span className="kbd">2</span>
                                للطريقة
                            </span>
                            <PrimaryButton
                                type="submit"
                                disabled={
                                    !(Number(data.amount) > 0) ||
                                    inShekels === null ||
                                    form.processing ||
                                    (Boolean(referenceStatus?.conflict) && !data.confirm_duplicate_reference) ||
                                    (overpayment === 'confirm' && !data.confirm_overpayment)
                                }
                                className={`ms-auto h-12 min-w-0 flex-1 rounded-[14px] px-5 text-[15.5px] font-bold sm:min-w-[230px] sm:flex-none ${
                                    correcting ? '!bg-none !bg-amber-600 !shadow-[0_12px_26px_-12px_rgb(180_83_9)]' : ''
                                }`}
                            >
                                <Icon name="check" className="h-[18px] w-[18px]" strokeWidth={2.2} />
                                {form.processing
                                    ? 'جارٍ الحفظ...'
                                    : correcting
                                      ? 'حفظ التصحيح'
                                      : Number(data.amount) > 0
                                        ? `تسجيل ${formatMoney(data.amount)} ${currencyMark}`
                                        : 'تسجيل الدفعة'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </Modal>

            <ConfirmDialog
                show={show && discarding}
                onConfirm={close}
                onCancel={() => setDiscarding(false)}
                title="تجاهل الدفعة؟"
                message="أدخلت بيانات لم تُحفظ بعد. إذا أغلقت النافذة الآن فستفقدها."
                confirmLabel="تجاهل الدفعة"
                cancelLabel="البقاء ومتابعة الإدخال"
                icon="alert"
                tone="danger"
            />

            <ConfirmDialog
                show={show && confirmingSave}
                onConfirm={save}
                onCancel={() => setConfirmingSave(false)}
                title={correcting ? 'تأكيد حفظ التصحيح؟' : 'تأكيد تسجيل الدفعة؟'}
                message={`${correcting ? 'ستُصحَّح الدفعة إلى' : 'ستُسجَّل دفعة'} ${formatMoney(data.amount)} ${currencyMark} ${methodText} على حساب ${subscription.fullName}${data.currency !== 'ILS' ? `، بقيمة ${formatMoney(inShekels)} شيكل` : ''}.${throughBank && data.bank_received_on ? ` تاريخ وصول الحوالة إلى البنك: ${data.bank_received_on}.` : ''} هل تريد المتابعة؟`}
                confirmLabel={correcting ? 'نعم، احفظ التصحيح' : 'نعم، سجّل الدفعة'}
                cancelLabel="رجوع للمراجعة"
                icon="check"
            />
        </>
    );
}
