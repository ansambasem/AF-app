import { useEffect, useId, useRef, useState } from 'react';
import { useHttp, usePage } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import Switch from '@/Components/Switch';
import { useResourceForm } from '@/hooks/useResourceForm';
import { describeBalance, paymentInShekels } from '@/lib/accountStatement';
import { overpaymentLevel } from '@/lib/overpayment';
import { formatClock, formatMoney, normalizeDecimalInput } from '@/lib/format';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';
import { balanceText, FieldLabel, SubscriptionStrip } from './AccountFormParts';
import { CorrectionReasonFields, EMPTY_CORRECTION, OriginalLine } from './CorrectionFields';
import { BankReceivedDateField } from '@/Components/BankReceivedDate';

const QUICK_AMOUNTS = [50, 100, 200];

/**
 * Each transfer bank or e-wallet's logo, color and kind; one not listed here gets a plain tile.
 * Every logo is a square image with its own padding, so all marks share one size and fit.
 */
const BANKS = {
    'بنك فلسطين': { logo: '/images/banks/bank-of-palestine.webp', color: '#b8007a', kind: 'تحويل بنكي' },
    'جوال باي': { logo: '/images/banks/jawwal-pay.webp', color: '#7cb342', kind: 'محفظة' },
    'محفظة بالباي': { logo: '/images/banks/palpay.webp', color: '#9b30e0', kind: 'محفظة' },
    'البنك الإسلامي الفلسطيني': { logo: '/images/banks/palestine-islamic-bank.webp', color: '#173d69', kind: 'تحويل بنكي' },
    'البنك الإسلامي العربي': { logo: '/images/banks/arab-islamic-bank.webp', color: '#7b2d8e', kind: 'تحويل بنكي' },
    'بنك القدس': { logo: '/images/banks/quds-bank.webp', color: '#74bd44', kind: 'تحويل بنكي' },
};

function useBankLook(bank) {
    const managed = usePage().props.paymentOptions?.banks?.find((option) => option.name === bank);
    return managed?.logo ? { ...BANKS[bank], logo: managed.logo } : BANKS[bank];
}

export const inputClass =
    'block h-[50px] w-full rounded-[14px] border-[1.5px] border-gray-200 bg-surface px-4 text-base text-gray-900 transition placeholder:text-gray-400 hover:border-brand-500/45 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 read-only:bg-gray-50 read-only:text-gray-500';

/** One way of paying, as a big radio tile. */
function MethodTile({ value, checked, onChange, icon, title, hint }) {
    return (
        <label
            className={`relative flex cursor-pointer items-center gap-3 rounded-[18px] border-[1.5px] bg-surface px-4 py-3.5 transition focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-gray-900 ${
                checked ? 'border-brand-500 bg-brand-500/[0.06] shadow-[0_0_0_4px_rgb(var(--brand-500)/0.12)]' : 'border-gray-200 hover:border-gray-300'
            }`}
        >
            <input type="radio" name="payment_method" value={value} checked={checked} onChange={() => onChange(value)} className="sr-only" />
            <span
                className={`flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-[13px] transition ${
                    checked ? 'bg-brand-gradient text-white' : 'bg-gray-100 text-gray-700'
                }`}
            >
                <Icon name={icon} className="h-[18px] w-[18px]" strokeWidth={1.8} />
            </span>
            <span className="min-w-0">
                <b className={`block text-[15.5px] ${checked ? 'text-brand-600' : 'text-gray-900'}`}>{title}</b>
                <small className="block text-[13px] text-gray-500">{hint}</small>
            </span>
            <span
                className={`ms-auto flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition ${
                    checked ? 'border-brand-500 bg-brand-500' : 'border-gray-300'
                }`}
                aria-hidden="true"
            >
                {checked && <span className="h-2 w-2 rounded-full bg-surface" />}
            </span>
        </label>
    );
}

export function BankMark({ bank, className = 'h-10 w-10 rounded-xl' }) {
    const look = useBankLook(bank);

    if (!look) {
        return (
            <span className={`flex shrink-0 items-center justify-center bg-gray-100 text-gray-600 ${className}`}>
                <Icon name="bank" className="h-1/2 w-1/2" />
            </span>
        );
    }

    return (
        <span className={`shrink-0 overflow-hidden border border-gray-100 bg-white shadow-[0_4px_10px_-6px_rgb(0_0_0/0.4)] ${className}`}>
            <img src={look.logo} alt="" className="h-full w-full object-contain" />
        </span>
    );
}

/** A bank or e-wallet as a radio row: brand mark, name, kind, and the choice indicator. */
export function BankRow({ bank, checked, name, onChange, required = false }) {
    const look = useBankLook(bank);

    return (
        <label
            className={`relative flex min-h-[58px] cursor-pointer items-center gap-3 rounded-[14px] border-[1.5px] px-3 py-2 transition focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-gray-900 ${
                checked ? 'border-brand-500 bg-brand-500/[0.06] shadow-[0_0_0_3px_rgb(var(--brand-500)/0.12)]' : 'border-gray-100 bg-surface hover:border-gray-300'
            }`}
        >
            <input type="radio" name={name} value={bank} required={required} checked={checked} onChange={() => onChange(bank)} className="sr-only" />
            <BankMark bank={bank} />
            <span className="min-w-0">
                <b className={`block truncate text-[14.5px] ${checked ? 'text-brand-600' : 'text-gray-900'}`}>{bank}</b>
                <small className="block text-xs font-medium text-gray-500">{look?.kind ?? 'تحويل'}</small>
            </span>
            <span
                className={`ms-auto flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition ${
                    checked ? 'border-brand-500 bg-brand-500' : 'border-gray-300'
                }`}
                aria-hidden="true"
            >
                {checked && <span className="h-2 w-2 rounded-full bg-surface" />}
            </span>
        </label>
    );
}

export function BankDropdown({ banks, id, name, onChange, value }) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const listboxId = useId();
    const selectedLook = useBankLook(value);

    useEffect(() => {
        if (!open) {
            return;
        }

        function closeOutside(event) {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        }

        function closeOnEscape(event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', closeOutside);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    function select(bank) {
        onChange(bank);
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <input type="hidden" name={name} value={value} />
            <button
                type="button"
                id={id}
                aria-controls={listboxId}
                aria-expanded={open}
                aria-haspopup="listbox"
                onClick={() => setOpen((current) => !current)}
                className={`flex min-h-[58px] w-full items-center gap-3 rounded-[14px] border-[1.5px] bg-surface px-3 py-2 text-start transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/15 ${
                    open ? 'border-brand-500 ring-4 ring-brand-500/15' : 'border-gray-200 hover:border-brand-500/45'
                }`}
            >
                {value ? (
                    <>
                        <BankMark bank={value} />
                        <span className="min-w-0">
                            <b className="block truncate text-[14.5px] text-gray-900">{value}</b>
                            <small className="block text-xs font-medium text-gray-500">{selectedLook?.kind ?? 'تحويل'}</small>
                        </span>
                    </>
                ) : (
                    <span className="text-sm text-gray-400">اختر البنك أو المحفظة المحوّل منها</span>
                )}
                <Icon name="chevron-down" className={`ms-auto h-4 w-4 shrink-0 text-gray-400 transition ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <ul
                    id={listboxId}
                    role="listbox"
                    aria-label="البنك المرسل"
                    className="animate-modal-panel absolute inset-x-0 z-30 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-gray-100 bg-surface p-1.5 shadow-lift"
                >
                    <li role="none">
                        <button
                            type="button"
                            role="option"
                            aria-selected={!value}
                            onClick={() => select('')}
                            className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-gray-50 ${
                                !value ? 'bg-gray-50 font-semibold text-gray-900' : 'text-gray-600'
                            }`}
                        >
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50 text-gray-400">
                                <Icon name="close" className="h-4 w-4" />
                            </span>
                            <span className="text-sm">غير محدد</span>
                            {!value && <Icon name="check" className="ms-auto h-4 w-4 text-brand-600" strokeWidth={2} />}
                        </button>
                    </li>
                    {banks.map((bank) => {
                        const isSelected = value === bank;
                        const look = BANKS[bank];

                        return (
                            <li key={bank} role="none">
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => select(bank)}
                                    className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-gray-50 ${
                                        isSelected ? 'bg-gray-50' : ''
                                    }`}
                                    style={isSelected && look?.color ? { backgroundColor: `${look.color}0d` } : undefined}
                                >
                                    <BankMark bank={bank} />
                                    <span className="min-w-0">
                                        <b className="block text-[14.5px] text-gray-900">{bank}</b>
                                        <small className="block text-xs font-medium text-gray-500">{look?.kind ?? 'تحويل'}</small>
                                    </span>
                                    {isSelected && <Icon name="check" className="ms-auto h-4 w-4 shrink-0 text-brand-600" strokeWidth={2} />}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

/** The dark panel beside the form: what will be recorded and what it does to the balance, before saving. */
function PaymentSummary({ amount, currency, inShekels, balance, methodText, collector }) {
    const before = describeBalance(balance);
    const after = inShekels === null ? null : describeBalance(Number(balance) - inShekels);
    const owed = Number(balance) > 0 ? Number(balance) : 0;
    const coverage = owed > 0 && inShekels !== null ? Math.min(100, Math.round((inShekels / owed) * 100)) : 0;
    const now = new Date();

    return (
        <aside
            aria-live="polite"
            className="relative flex flex-col gap-[18px] overflow-hidden bg-graphite-gradient p-5 text-white sm:p-6 lg:col-start-2 lg:row-span-3 lg:row-start-1 lg:overflow-y-auto"
        >
            <span className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" aria-hidden="true" />
            <span className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <span className="absolute -bottom-32 -end-20 h-80 w-80 rounded-full bg-[radial-gradient(closest-side,rgb(165_29_38/0.25),transparent)]" />
            </span>

            <h4 className="relative flex items-center gap-2 font-luxe text-lg font-bold">
                <Icon name="receipt" className="h-[18px] w-[18px]" strokeWidth={1.8} />
                ملخص الدفعة
                <span className="ms-auto rounded-full bg-white/10 px-2.5 py-0.5 font-sans text-[11.5px] font-semibold text-white/80">قبل الحفظ</span>
            </h4>

            <div className="relative">
                <p className="text-[13px] text-white/60">المبلغ</p>
                <p className="text-end font-display text-[34px] font-extrabold leading-tight sm:text-[40px]" dir="ltr">
                    {formatMoney(amount || 0)}
                    <span className="ms-1.5 text-lg font-semibold text-white/70">{currency === 'ILS' ? '₪' : currency}</span>
                </p>
            </div>

            <dl className="relative grid gap-2.5 rounded-[18px] border border-white/10 bg-white/5 p-3.5 text-sm text-white/75">
                <div className="flex items-baseline justify-between gap-3">
                    <dt>الرصيد الحالي</dt>
                    <dd className="font-display text-[15px] font-semibold text-white">{balanceText(before)}</dd>
                </div>
                <div className="flex items-baseline justify-between gap-3">
                    <dt>هذه الدفعة</dt>
                    <dd className="font-display text-[15px] font-semibold text-white" dir="ltr">
                        {formatMoney(inShekels ?? 0)} ₪
                    </dd>
                </div>
                <div className="h-px bg-white/10" aria-hidden="true" />
                <div className="flex items-baseline justify-between gap-3">
                    <dt>الرصيد بعد الدفعة</dt>
                    <dd
                        className={`font-display text-lg font-semibold ${after ? { owes: 'text-red-300', credit: 'text-emerald-300', settled: 'text-white' }[after.tone] : 'text-white/50'}`}
                    >
                        {after ? balanceText(after) : '—'}
                    </dd>
                </div>
            </dl>

            {owed > 0 && (
                <div className="relative">
                    <div className="mb-1.5 flex justify-between text-[12.5px] text-white/60">
                        <span>تغطية المبلغ المستحق</span>
                        <span className="font-display">{coverage}%</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-white/10" aria-hidden="true">
                        <div
                            className="h-full rounded-full bg-gradient-to-l from-emerald-400 to-emerald-500 transition-[width] duration-500"
                            style={{ width: `${coverage}%` }}
                        />
                    </div>
                </div>
            )}

            <ul className="relative hidden gap-2 text-[13.5px] text-white/75 lg:grid">
                <li className="flex items-center gap-2">
                    <Icon name="banknotes" className="h-[18px] w-[18px] text-white/50" />
                    الطريقة: <b className="font-semibold text-white">{methodText}</b>
                </li>
                <li className="flex items-center gap-2">
                    <Icon name="user" className="h-[18px] w-[18px] text-white/50" />
                    المحصّل: <b className="font-semibold text-white">{collector}</b>
                </li>
                <li className="flex items-center gap-2">
                    <Icon name="clock" className="h-[18px] w-[18px] text-white/50" />
                    اليوم، <b className="font-display font-semibold text-white">{formatClock(`${now.getHours()}:${now.getMinutes()}`)}</b>
                </li>
            </ul>
        </aside>
    );
}

/** After saving: what was recorded, the voucher it got and the balance it left, with its receipt to print. */
function PaymentReceipt({ receipt, subscriptionName, title, onAnother, onDone }) {
    const after = describeBalance(receipt.balanceAfter);
    const rows = [
        ['رقم السند', receipt.voucherNumber ?? '—'],
        ['المبلغ', `${formatMoney(receipt.amount)} ${receipt.currency === 'ILS' ? '₪' : receipt.currency}`],
        ...(receipt.currency !== 'ILS' ? [['القيمة بالشيكل', `${formatMoney(receipt.inShekels)} ₪`]] : []),
        ['الطريقة', receipt.methodText],
        ['الرصيد بعد الدفعة', balanceText(after)],
    ];

    return (
        <div className="px-6 pb-8 pt-10 text-center sm:px-8">
            <span className="mx-auto mb-3.5 flex h-[76px] w-[76px] items-center justify-center rounded-3xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <Icon name="check" className="h-10 w-10" strokeWidth={2.2} />
            </span>
            <h3 className="font-luxe text-[26px] font-bold text-gray-900">{title}</h3>
            <p className="mt-1.5 text-gray-600">
                {formatMoney(receipt.currency === 'ILS' ? receipt.amount : receipt.inShekels)} شيكل من {subscriptionName} · الرصيد الجديد {balanceText(after)}
            </p>
            <dl className="mx-auto mt-6 w-full max-w-[420px] rounded-[20px] border border-gray-100 bg-gray-50 px-[18px] py-1.5 text-start">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4 border-b border-dashed border-gray-200 py-2.5 text-[14.5px] last:border-0">
                        <dt className="text-gray-500">{label}</dt>
                        <dd className="font-semibold text-gray-900">{value}</dd>
                    </div>
                ))}
            </dl>
            <div className="mt-6 flex flex-wrap justify-center gap-2.5">
                {receipt.receiptUrl && (
                    <SecondaryButton
                        onClick={() => window.open(receipt.receiptUrl, '_blank')}
                        title="يُفتح سند القبض في نافذة جديدة جاهزًا للطباعة"
                        className="tone-print h-12 rounded-[14px] px-5 text-[15.5px]"
                    >
                        <Icon name="printer" className="h-[18px] w-[18px]" />
                        طباعة السند
                    </SecondaryButton>
                )}
                {onAnother && (
                    <SecondaryButton onClick={onAnother} className="h-12 rounded-[14px] px-5 text-[15.5px]">
                        <Icon name="plus" className="h-[18px] w-[18px]" strokeWidth={2} />
                        دفعة جديدة
                    </SecondaryButton>
                )}
                <PrimaryButton type="button" onClick={onDone} autoFocus className="h-12 min-w-28 rounded-[14px] px-5 text-[15.5px]">
                    تم
                </PrimaryButton>
            </div>
        </div>
    );
}

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
                                        <div className="animate-menu mt-3 grid gap-4 rounded-[20px] border border-gray-100 bg-gray-50 p-4">
                                            <fieldset>
                                                <legend className="mb-2 text-[14.5px] font-semibold text-gray-700">
                                                    البنك المستلم <span className="text-brand-600">*</span>
                                                </legend>
                                                <div className="grid gap-2">
                                                    {transferBanks.map((bank) => (
                                                        <BankRow
                                                            key={bank}
                                                            bank={bank}
                                                            checked={data.bank_name === bank}
                                                            name="bank_name"
                                                            onChange={(value) => setData('bank_name', value)}
                                                            required
                                                        />
                                                    ))}
                                                </div>
                                                <InputError message={errors.bank_name} className="mt-2" />
                                            </fieldset>

                                            <div>
                                                <FieldLabel htmlFor="sender_bank_name">
                                                    البنك المرسل
                                                </FieldLabel>
                                                <BankDropdown
                                                    banks={senderBanks}
                                                    id="sender_bank_name"
                                                    name="sender_bank_name"
                                                    value={data.sender_bank_name}
                                                    onChange={(value) => setData('sender_bank_name', value)}
                                                />
                                                <InputError message={errors.sender_bank_name} className="mt-2" />
                                            </div>
                                            <BankReceivedDateField value={data.bank_received_on} onChange={(value) => setData('bank_received_on', value)} error={errors.bank_received_on} />

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div>
                                                    <div className="mb-2 flex items-center justify-between gap-2.5">
                                                        <label htmlFor="sender_name" className="text-[14.5px] font-semibold text-gray-700">
                                                            اسم المحوِّل <span className="text-brand-600">*</span>
                                                        </label>
                                                        <Switch
                                                            checked={senderIsSubscriber}
                                                            onChange={toggleSender}
                                                            label={<span className="text-[13.5px] font-medium text-gray-600">المشترك نفسه</span>}
                                                            ariaLabel="المحوِّل هو المشترك نفسه"
                                                        />
                                                    </div>
                                                    <input
                                                        id="sender_name"
                                                        name="sender_name"
                                                        required
                                                        readOnly={senderIsSubscriber}
                                                        placeholder="اسم صاحب الحساب الذي حُوّل منه المبلغ"
                                                        value={data.sender_name}
                                                        onChange={(e) => setData('sender_name', e.target.value)}
                                                        className={inputClass}
                                                    />
                                                    <InputError message={errors.sender_name} className="mt-2" />
                                                </div>
                                                <div>
                                                    <FieldLabel htmlFor="reference_number" hint="من إشعار الحوالة">
                                                        الرقم المرجعي
                                                    </FieldLabel>
                                                    <input
                                                        id="reference_number"
                                                        name="reference_number"
                                                        dir="ltr"
                                                        autoComplete="off"
                                                        placeholder="مثال: TRX-48213"
                                                        value={data.reference_number}
                                                        onChange={(e) => setData((current) => ({ ...current, reference_number: e.target.value, confirm_duplicate_reference: false }))}
                                                        className={`${inputClass} text-end font-display placeholder:font-sans placeholder:font-normal`}
                                                    />
                                                    <InputError message={errors.reference_number} className="mt-2" />
                                                    {referenceCheck.processing && <p className="mt-2 text-xs text-gray-500">جارٍ التحقق من الرقم المرجعي...</p>}
                                                    {referenceStatus?.conflict && (
                                                        <div className="mt-2 rounded-lg bg-amber-50 p-2 text-xs font-medium text-amber-800">
                                                            <p>
                                                                الرقم المرجعي مكرر: مسجَّل مسبقًا في{' '}
                                                                <a href={referenceStatus.conflict.url} className="underline">
                                                                    {referenceStatus.conflict.voucherNumber
                                                                        ? `السند ${referenceStatus.conflict.voucherNumber} — `
                                                                        : 'دفعة '}
                                                                    {referenceStatus.conflict.subscriptionName}
                                                                </a>
                                                            </p>
                                                            <label className="mt-1.5 flex items-center gap-2">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={Boolean(data.confirm_duplicate_reference)}
                                                                    onChange={(e) => setData('confirm_duplicate_reference', e.target.checked)}
                                                                />
                                                                أؤكد تسجيل الدفعة بالرقم نفسه
                                                            </label>
                                                        </div>
                                                    )}
                                                    {referenceStatus?.available && !referenceStatus.warning && (
                                                        <p className="mt-2 text-xs font-medium text-emerald-600">الرقم المرجعي متاح.</p>
                                                    )}
                                                    {referenceStatus?.warning && (
                                                        <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs font-medium text-amber-800">
                                                            تنبيه فقط: توجد دفعة اليوم بالمبلغ واسم المحوِّل نفسيهما —{' '}
                                                            <a href={referenceStatus.warning.url} className="underline">
                                                                {referenceStatus.warning.voucherNumber ? `السند ${referenceStatus.warning.voucherNumber}` : 'الدفعة'}
                                                            </a>
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
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
