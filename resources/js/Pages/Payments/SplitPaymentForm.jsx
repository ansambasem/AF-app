import { BankReceivedDateField } from '@/Components/BankReceivedDate';
import { useEffect, useRef, useState } from 'react';
import { useForm, useHttp } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { describeBalance } from '@/lib/accountStatement';
import { formatMoney, initials, normalizeDecimalInput } from '@/lib/format';
import { balanceText, BALANCE_CHIPS, FieldLabel } from '@/Pages/Subscriptions/AccountFormParts';
import { BankDropdown, BankRow } from '@/Components/Banks';
import { inputClass } from '@/Pages/Subscriptions/PaymentModal';

/** The most subscriptions one transfer is divided between (the server's limit). */
const MAX_PARTS = 20;
const SEARCH_DELAY = 300;

const STATUS_DOTS = { active: 'bg-emerald-500', suspended: 'bg-amber-500', disconnected: 'bg-gray-400' };

/** An amount in whole agorot, so sums of cents never drift. */
function toCents(value) {
    const cents = Math.round(Number(value || 0) * 100);

    return Number.isFinite(cents) ? cents : 0;
}

function fromCents(cents) {
    return cents / 100;
}

/** One subscription of the split, laid out like the subscription strip of the payment form, with the share it pays. */
function PartRow({ part, onAmount, onRemove, error }) {
    const after = describeBalance(Number(part.subscription.balance) - Number(part.amount || 0));

    return (
        <li className="grid gap-2">
            <div className="flex flex-wrap items-center gap-x-3.5 gap-y-3 rounded-[18px] border border-gray-100 bg-gray-50 px-3.5 py-3">
                <div className="flex min-w-0 flex-1 basis-52 items-center gap-3.5">
                    <span className="relative flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-[14px] bg-graphite-gradient font-display text-[15px] font-bold text-white">
                        {initials(part.subscription.fullName)}
                        <span className={`absolute -bottom-0.5 -start-0.5 h-[13px] w-[13px] rounded-full border-[2.5px] border-gray-50 ${STATUS_DOTS[part.subscription.status] ?? 'bg-gray-400'}`} aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="break-words text-[16.5px] font-bold text-gray-900">{part.subscription.fullName}</p>
                        <p className="text-[13.5px] text-gray-500">
                            حساب{' '}
                            <span dir="ltr" className="font-display">
                                {part.subscription.accountNumber}
                            </span>
                            {part.subscription.meterBoxNumber && ` · طبلون ${part.subscription.meterBoxNumber}`}
                        </p>
                    </div>
                </div>

                <div className="flex flex-col items-end gap-0.5">
                    <span className="text-[12.5px] text-gray-500">الرصيد الحالي</span>
                    <span className={`whitespace-nowrap rounded-[10px] px-2.5 py-0.5 font-display text-[15px] font-bold ${BALANCE_CHIPS[describeBalance(part.subscription.balance).tone]}`}>
                        {balanceText(describeBalance(part.subscription.balance))}
                    </span>
                </div>

                <div className="w-36">
                    <label htmlFor={`part-${part.subscription.id}`} className="sr-only">
                        {`مبلغ ${part.subscription.fullName}`}
                    </label>
                    <div className="relative">
                        <input
                            id={`part-${part.subscription.id}`}
                            inputMode="decimal"
                            autoComplete="off"
                            dir="ltr"
                            placeholder="0.00"
                            aria-label={`مبلغ ${part.subscription.fullName}`}
                            value={part.amount}
                            onChange={(event) => onAmount(normalizeDecimalInput(event.target.value))}
                            className={`${inputClass} pe-9 text-end font-display text-lg font-bold`}
                        />
                        <span className="pointer-events-none absolute inset-y-0 end-3.5 flex items-center font-display text-base font-bold text-gray-400" aria-hidden="true">
                            ₪
                        </span>
                    </div>
                </div>

                <div className="flex w-28 flex-col items-end gap-0.5">
                    <span className="text-[12.5px] text-gray-500">بعد الدفعة</span>
                    <span className={`whitespace-nowrap rounded-[10px] px-2.5 py-0.5 font-display text-[15px] font-bold ${BALANCE_CHIPS[after.tone]}`}>{balanceText(after)}</span>
                </div>

                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`إزالة ${part.subscription.fullName}`}
                    className="rounded-xl p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                >
                    <Icon name="close" className="h-[18px] w-[18px]" />
                </button>
            </div>
            {error && <InputError message={error} />}
        </li>
    );
}

/** The panel shown once the split is recorded: what was saved, and each part's receipt. */
function RecordedSplit({ recorded, onAnother }) {
    return (
        <div className="space-y-5 rounded-panel border border-emerald-500/30 bg-emerald-500/[0.06] p-6">
            <div className="flex items-center gap-3">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
                    <Icon name="check" className="h-6 w-6" />
                </span>
                <div>
                    <h3 className="text-xl font-bold text-gray-900">سُجّلت الدفعة المقسّمة</h3>
                    <p className="text-sm text-gray-600">
                        {formatMoney(recorded.total)} ₪ · {recorded.bankName} · مرجع{' '}
                        <span dir="ltr" className="font-display font-semibold">
                            {recorded.reference}
                        </span>
                    </p>
                </div>
            </div>

            <ul className="divide-y divide-gray-100 overflow-hidden rounded-[18px] border border-gray-100 bg-surface">
                {recorded.parts.map((part) => (
                    <li key={part.accountNumber} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
                        <b className="min-w-0 flex-1 basis-44 text-gray-900">{part.subscriptionName}</b>
                        <span className="text-gray-500">الرصيد بعدها {balanceText(describeBalance(part.balance))}</span>
                        <b className="font-display text-base text-emerald-700 dark:text-emerald-400">{formatMoney(part.amount)} ₪</b>
                        <a
                            href={part.receiptUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="tone-print inline-flex items-center gap-1.5 rounded-control border px-2.5 py-1 text-xs font-semibold transition"
                        >
                            <Icon name="printer" className="h-3.5 w-3.5" />
                            السند
                        </a>
                    </li>
                ))}
            </ul>

            <PrimaryButton type="button" onClick={onAnother}>
                <Icon name="plus" className="h-4 w-4" />
                دفعة مقسّمة جديدة
            </PrimaryButton>
        </div>
    );
}

/**
 * One bank transfer divided between any subscriptions the user chooses: the
 * transfer's details are entered once, then each subscription is given its
 * share. It can be saved only when the shares add up to exactly what was
 * transferred; the server records a payment on each subscription together,
 * or none.
 */
export default function SplitPaymentForm({ transferBanks, senderBanks, bare = false, onDirtyChange, initialSubscription = null }) {
    const form = useForm({
        total_amount: '',
        bank_name: '',
        sender_bank_name: '',
        bank_received_on: '',
        sender_name: '',
        reference_number: '',
        notes: '',
        parts: [],
        confirm_duplicate_reference: false,
        confirm_overpayment: false,
    });
    const { data, setData, errors, processing } = form;
    const [parts, setParts] = useState(() => (initialSubscription ? [{ subscription: initialSubscription, amount: '' }] : []));
    const [recorded, setRecorded] = useState(null);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [term, setTerm] = useState('');
    const [found, setFound] = useState({ subscriptions: [], hasMore: false, searched: false });
    const [identityNote, setIdentityNote] = useState(null);
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;
    const referenceCheck = useHttp();
    const referenceCheckRef = useRef(referenceCheck);
    referenceCheckRef.current = referenceCheck;
    const [referenceStatus, setReferenceStatus] = useState(null);

    // Whether the reference is free or already on a payment, said as it is typed; a repeated one is allowed once confirmed.
    useEffect(() => {
        const reference = data.reference_number.trim();

        if (reference === '') {
            setReferenceStatus(null);

            return undefined;
        }

        const timer = setTimeout(() => {
            referenceCheckRef.current
                .get(`/payments/split/reference-status?${new URLSearchParams({ reference_number: reference })}`)
                .then((status) => setReferenceStatus(status ? { ...status, reference } : null))
                .catch(() => setReferenceStatus(null));
        }, SEARCH_DELAY);

        return () => {
            clearTimeout(timer);
            referenceCheckRef.current.cancel();
        };
    }, [data.reference_number]);

    // The subscriptions matching what was typed, offered to add to the split.
    useEffect(() => {
        if (term.trim() === '') {
            setFound({ subscriptions: [], hasMore: false, searched: false });

            return undefined;
        }

        const timer = setTimeout(() => {
            httpRef.current
                .get(`/payments/search?${new URLSearchParams({ search: term.trim() })}`)
                .then((result) => setFound({ subscriptions: result.subscriptions, hasMore: result.hasMore, searched: true }))
                .catch(() => setFound({ subscriptions: [], hasMore: false, searched: true }));
        }, SEARCH_DELAY);

        return () => clearTimeout(timer);
    }, [term]);

    const totalCents = toCents(data.total_amount);
    const allocatedCents = parts.reduce((sum, part) => sum + toCents(part.amount), 0);
    const remainingCents = totalCents - allocatedCents;
    const everyPartHasAmount = parts.every((part) => toCents(part.amount) > 0);
    const detailsFilled = [data.bank_name, data.sender_name.trim(), data.reference_number.trim()].every(Boolean);
    const distributed = totalCents > 0 && remainingCents === 0 && parts.length >= 2 && everyPartHasAmount;
    const currentStatus = referenceStatus?.reference === data.reference_number.trim() ? referenceStatus : null;
    const duplicateReference = Boolean(currentStatus?.conflict) || (Boolean(errors.reference_number) && errors.reference_number.includes('مسجَّل مسبقًا'));
    const canSave = distributed && detailsFilled && !processing && (!duplicateReference || data.confirm_duplicate_reference);

    function addSubscription(subscription) {
        setParts((current) =>
            current.length >= MAX_PARTS || current.some((part) => part.subscription.id === subscription.id) ? current : [...current, { subscription, amount: '' }],
        );
        setTerm('');
        setIdentityNote(null);
        form.clearErrors();
    }

    function setAmount(index, amount) {
        setParts((current) => current.map((part, position) => (position === index ? { ...part, amount } : part)));
        form.clearErrors('parts', 'confirm_overpayment');
        setData('confirm_overpayment', false);
    }

    function removePart(index) {
        setParts((current) => current.filter((_, position) => position !== index));
    }

    /** Adds the other subscriptions under the first one's identity number, which a payer often settles together. */
    function addIdentitySiblings() {
        const first = parts[0]?.subscription;

        if (!first) {
            return;
        }

        httpRef.current
            .get(`/payments/search?${new URLSearchParams({ siblings_of: first.id })}`)
            .then((result) => {
                const present = new Set(parts.map((part) => part.subscription.id));
                const added = result.subscriptions.filter((subscription) => !present.has(subscription.id));

                setParts((current) => [...current, ...added.map((subscription) => ({ subscription, amount: '' }))].slice(0, MAX_PARTS));
                setIdentityNote(added.length > 0 ? `أُضيف ${added.length} من الاشتراكات الأخرى بنفس رقم الهوية.` : 'لا توجد اشتراكات أخرى بنفس رقم الهوية.');
            })
            .catch(() => setIdentityNote('تعذّر جلب الاشتراكات الأخرى.'));
    }

    /** Puts what is still left on the last subscription of the list. */
    function putRemainderOnLast() {
        if (parts.length === 0 || remainingCents === 0) {
            return;
        }

        const last = parts.length - 1;
        const amount = toCents(parts[last].amount) + remainingCents;

        if (amount > 0) {
            setAmount(last, String(fromCents(amount)));
        }
    }

    function save() {
        setConfirmingSave(false);
        form.transform((current) => ({
            ...current,
            parts: parts.map((part) => ({ subscription_id: part.subscription.id, amount: part.amount })),
        }));
        form.post('/payments/split', {
            preserveScroll: true,
            onSuccess: (page) => {
                setRecorded(page.flash?.recordedSplitPayment ?? null);
                setParts([]);
                form.reset();
                form.clearErrors();
            },
        });
    }

    // Whether something is entered that closing the form would lose; the modal asks before throwing it away.
    const dirty = !recorded && (parts.length !== (initialSubscription ? 1 : 0) || parts.some((part) => part.amount !== '') || data.total_amount !== '' || data.sender_name !== '' || data.reference_number !== '' || data.bank_name !== '' || data.bank_received_on !== '');
    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    if (recorded) {
        return <RecordedSplit recorded={recorded} onAnother={() => setRecorded(null)} />;
    }

    const meterTone = distributed ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'border-amber-500/40 bg-amber-500/[0.08] text-gray-800';

    return (
        <div className={`grid gap-[22px] ${bare ? '' : 'rounded-panel border border-gray-100 bg-surface p-5 shadow-card sm:p-6'}`}>
            <div>
                <FieldLabel htmlFor="split_total" required>
                    المبلغ المحوَّل (الإجمالي)
                </FieldLabel>
                <div
                    className={`rounded-[22px] border-[1.5px] bg-surface px-[18px] py-4 transition ${
                        errors.total_amount
                            ? 'border-brand-500 shadow-[0_0_0_5px_rgb(var(--brand-500)/0.08)]'
                            : 'border-gray-200 focus-within:border-brand-500 focus-within:shadow-[0_0_0_5px_rgb(var(--brand-500)/0.12)]'
                    }`}
                >
                    <div className="flex items-center gap-3.5">
                        <input
                            id="split_total"
                            autoFocus
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0.00"
                            dir="ltr"
                            value={data.total_amount}
                            onChange={(event) => {
                                setData('total_amount', normalizeDecimalInput(event.target.value));
                                form.clearErrors('total_amount', 'parts');
                            }}
                            className="min-w-0 flex-1 border-0 bg-transparent p-0 text-end font-display text-[38px] font-extrabold leading-tight text-gray-900 placeholder:text-gray-300 focus:outline-none focus:ring-0 sm:text-[44px]"
                        />
                        <span className="shrink-0 font-display text-[28px] font-bold text-gray-400" aria-hidden="true">
                            ₪
                        </span>
                    </div>
                </div>
                {errors.total_amount && (
                    <p role="alert" className="mt-2 flex items-center gap-1.5 text-[13.5px] text-brand-600">
                        <Icon name="info" className="h-[18px] w-[18px]" />
                        {errors.total_amount}
                    </p>
                )}
            </div>

            <div className="grid gap-4 rounded-[20px] border border-gray-100 bg-gray-50 p-4">
                <fieldset>
                    <legend className="mb-2 text-[14.5px] font-semibold text-gray-700">
                        البنك المستلم <span className="text-brand-600">*</span>
                    </legend>
                    <div className="grid gap-2">
                        {transferBanks.map((bank) => (
                            <BankRow key={bank} bank={bank} checked={data.bank_name === bank} name="bank_name" onChange={(value) => setData('bank_name', value)} />
                        ))}
                    </div>
                    <InputError message={errors.bank_name} className="mt-2" />
                </fieldset>

                <div>
                    <FieldLabel htmlFor="sender_bank_name">
                        البنك المرسل
                    </FieldLabel>
                    <BankDropdown banks={senderBanks} id="sender_bank_name" name="sender_bank_name" value={data.sender_bank_name} onChange={(value) => setData('sender_bank_name', value)} />
                    <InputError message={errors.sender_bank_name} className="mt-2" />
                </div>
                <BankReceivedDateField value={data.bank_received_on} onChange={(value) => setData('bank_received_on', value)} error={errors.bank_received_on} />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FieldLabel htmlFor="split_sender" required>
                            اسم المحوِّل
                        </FieldLabel>
                        <input
                            id="split_sender"
                            maxLength={255}
                            placeholder="اسم صاحب الحساب الذي حُوّل منه المبلغ"
                            value={data.sender_name}
                            onChange={(event) => setData('sender_name', event.target.value)}
                            className={inputClass}
                        />
                        <InputError message={errors.sender_name} className="mt-2" />
                    </div>
                    <div>
                        <FieldLabel htmlFor="split_reference" required hint="من إشعار الحوالة">
                            الرقم المرجعي
                        </FieldLabel>
                        <input
                            id="split_reference"
                            dir="ltr"
                            autoComplete="off"
                            maxLength={100}
                            placeholder="مثال: TRX-48213"
                            value={data.reference_number}
                            onChange={(event) => {
                                setData((current) => ({ ...current, reference_number: event.target.value, confirm_duplicate_reference: false }));
                                form.clearErrors('reference_number');
                            }}
                            className={`${inputClass} text-end font-display placeholder:font-sans placeholder:font-normal`}
                        />
                        <InputError message={currentStatus?.conflict ? null : errors.reference_number} className="mt-2" />
                        {referenceCheck.processing && <p className="mt-2 text-xs text-gray-500">جارٍ التحقق من الرقم المرجعي...</p>}
                        {currentStatus?.available && !referenceCheck.processing && <p className="mt-2 text-xs font-medium text-emerald-600">الرقم المرجعي متاح.</p>}
                    </div>
                </div>

                {duplicateReference && (
                    <div role="alert" className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-gray-900">
                        <p className="mb-2 font-medium text-amber-900 dark:text-amber-200">
                            الرقم المرجعي مكرر: مسجَّل مسبقًا
                            {currentStatus?.conflict ? (
                                <>
                                    {' في '}
                                    <a href={currentStatus.conflict.url} className="underline">
                                        {currentStatus.conflict.voucherNumber ? `السند ${currentStatus.conflict.voucherNumber} — ` : 'دفعة '}
                                        {currentStatus.conflict.subscriptionName}
                                    </a>
                                </>
                            ) : (
                                ' على دفعة أخرى'
                            )}
                            . يمكنك المتابعة بعد التأكيد.
                        </p>
                        <label className="flex cursor-pointer items-center gap-2 font-semibold">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                checked={data.confirm_duplicate_reference}
                                onChange={(event) => setData('confirm_duplicate_reference', event.target.checked)}
                            />
                            أؤكد أن هذا الرقم المرجعي مسجَّل على دفعة أخرى وأريد المتابعة
                        </label>
                    </div>
                )}

                <p className="flex items-center gap-2 text-[13.5px] text-gray-500">
                    <Icon name="info" className="h-4 w-4 shrink-0" />
                    تُكتب بيانات التحويل مرة واحدة، وتُحفظ كما هي على دفعة كل مشترك.
                </p>
            </div>

            <div>
                <FieldLabel htmlFor="split_search" required hint="أي مشتركين تختارهم، ولو بهويات مختلفة">
                    توزيع المبلغ على المشتركين
                </FieldLabel>

                {parts.length > 0 && (
                    <ul className="mb-3 grid gap-2.5">
                        {parts.map((part, index) => (
                            <PartRow
                                key={part.subscription.id}
                                part={part}
                                onAmount={(amount) => setAmount(index, amount)}
                                onRemove={() => removePart(index)}
                                error={errors[`parts.${index}.amount`] ?? errors[`parts.${index}.subscription_id`]}
                            />
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap items-start gap-3">
                    <div className="relative min-w-0 flex-1 basis-72">
                        <Icon name="search" className="pointer-events-none absolute inset-y-0 start-4 my-auto h-[18px] w-[18px] text-gray-400" />
                        <input
                            id="split_search"
                            type="search"
                            aria-label="إضافة مشترك إلى هذه الدفعة"
                            autoComplete="off"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder={parts.length === 0 ? 'ابحث عن مشترك بالاسم أو رقم الحساب أو الجوال…' : 'إضافة مشترك آخر إلى هذه الدفعة…'}
                            disabled={parts.length >= MAX_PARTS}
                            className={`${inputClass} ps-11`}
                        />
                        {found.searched && (
                            <ul className="animate-modal-panel absolute inset-x-0 z-30 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-gray-100 bg-surface p-1.5 shadow-lift">
                                {found.subscriptions.length === 0 && <li className="px-3 py-3 text-center text-sm text-gray-500">لا يوجد مشترك يطابق بحثك.</li>}
                                {found.subscriptions.map((subscription) => {
                                    const added = parts.some((part) => part.subscription.id === subscription.id);

                                    return (
                                        <li key={subscription.id}>
                                            <button
                                                type="button"
                                                disabled={added}
                                                onClick={() => addSubscription(subscription)}
                                                className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-gray-50 disabled:opacity-40"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <b className="block truncate text-[14.5px] text-gray-900">{subscription.fullName}</b>
                                                    <small className="block text-xs font-medium text-gray-500">
                                                        <span dir="ltr" className="font-display">
                                                            {subscription.accountNumber}
                                                        </span>
                                                        {subscription.meterBoxNumber && ` · طبلون ${subscription.meterBoxNumber}`}
                                                    </small>
                                                </span>
                                                <span className={`whitespace-nowrap rounded-[10px] px-2 py-0.5 font-display text-[13px] font-bold ${added ? 'bg-gray-100 text-gray-700' : BALANCE_CHIPS[describeBalance(subscription.balance).tone]}`}>
                                                    {added ? 'مُضاف' : balanceText(describeBalance(subscription.balance))}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                                {found.hasMore && <li className="px-3 py-2 text-center text-xs text-gray-500">هناك نتائج أخرى؛ أضف إلى البحث ما يضيّقه.</li>}
                            </ul>
                        )}
                    </div>
                    {parts.length > 0 && (
                        <SecondaryButton onClick={addIdentitySiblings} className="h-[50px]">
                            <Icon name="users" className="h-4 w-4" />
                            كل اشتراكات هذه الهوية
                        </SecondaryButton>
                    )}
                </div>
                {identityNote && <p className="mt-2 text-[13.5px] text-gray-600">{identityNote}</p>}
                <InputError message={errors.parts} className="mt-2" />
            </div>

            <div className={`rounded-xl border px-4 py-3 text-sm ${meterTone}`} role="status">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="flex items-center gap-2 font-semibold">
                        <Icon name={distributed ? 'check' : 'alert'} className="h-[18px] w-[18px] shrink-0" />
                        {distributed
                            ? 'وُزّع المبلغ كاملًا'
                            : totalCents === 0
                              ? 'أدخل المبلغ المحوَّل ثم وزّعه على المشتركين'
                              : parts.length < 2
                                ? 'أضف مشتركَين على الأقل'
                                : remainingCents > 0
                                  ? `بقي ${formatMoney(fromCents(remainingCents))} ₪ لم يُوزَّع`
                                  : remainingCents < 0
                                    ? `الموزَّع يزيد ${formatMoney(fromCents(-remainingCents))} ₪ على المحوَّل`
                                    : 'أدخل مبلغ كل مشترك'}
                    </p>
                    <div className="flex items-center gap-3">
                        {parts.length > 0 && remainingCents > 0 && (
                            <button type="button" onClick={putRemainderOnLast} className="text-[13.5px] font-semibold text-brand-600 hover:underline">
                                ضع الباقي على آخر مشترك
                            </button>
                        )}
                        <span className="font-display font-bold text-gray-900">
                            {formatMoney(fromCents(allocatedCents))} <span className="font-sans text-[13px] font-medium text-gray-500">من</span> {formatMoney(fromCents(totalCents))} ₪
                        </span>
                    </div>
                </div>
                <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                    <div
                        className={`h-full rounded-full transition-all ${distributed ? 'bg-emerald-500' : 'bg-amber-500'}`}
                        style={{ width: `${totalCents > 0 ? Math.min(100, (allocatedCents / totalCents) * 100) : 0}%` }}
                    />
                </div>
            </div>

            {errors.confirm_overpayment && (
                <div role="alert" className="rounded-xl border border-brand-500/40 bg-brand-500/[0.07] px-4 py-3 text-sm text-gray-900">
                    <p className="flex items-start gap-2 font-medium">
                        <Icon name="alert" className="mt-0.5 h-[18px] w-[18px] shrink-0" />
                        <span>{errors.confirm_overpayment}</span>
                    </p>
                    <label className="mt-2 flex cursor-pointer items-center gap-2 font-semibold">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                            checked={data.confirm_overpayment}
                            onChange={(event) => setData('confirm_overpayment', event.target.checked)}
                        />
                        أؤكد أن المبالغ صحيحة
                    </label>
                </div>
            )}

            <div>
                <FieldLabel htmlFor="split_notes" hint="تظهر في كشف كل مشترك">
                    ملاحظة
                </FieldLabel>
                <input id="split_notes" maxLength={1000} autoComplete="off" placeholder="أي تفصيل يُحفظ مع الدفعة" value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={inputClass} />
                <InputError message={errors.notes} className="mt-2" />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
                <p className="max-w-xl text-[13.5px] text-gray-500">
                    تُسجَّل <b className="text-gray-800">دفعة منفصلة لكل مشترك</b> في كشفه، وتُحفظ كلها معًا أو لا تُحفظ أيٌّ منها.
                </p>
                <PrimaryButton type="button" disabled={!canSave} onClick={() => setConfirmingSave(true)} className="h-12 px-6 text-[15px]">
                    {parts.length >= 2 ? `تسجيل الدفعة على ${parts.length} مشتركين` : 'تسجيل الدفعة'}
                    <Icon name="check" className="h-[18px] w-[18px]" />
                </PrimaryButton>
            </div>

            <ConfirmDialog
                show={confirmingSave}
                onConfirm={save}
                onCancel={() => setConfirmingSave(false)}
                title="تسجيل الدفعة المقسّمة؟"
                message={`ستُسجَّل ${parts.length} دفعات بمجموع ${formatMoney(fromCents(totalCents))} ₪ محوَّلة من ${data.sender_name.trim()} إلى ${data.bank_name} (مرجع ${data.reference_number.trim()}). هل تريد المتابعة؟`}
                confirmLabel="نعم، سجّل"
                icon="check"
            />
        </div>
    );
}
