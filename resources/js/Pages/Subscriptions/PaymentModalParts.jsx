import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { describeBalance } from '@/lib/accountStatement';
import { formatClock, formatMoney } from '@/lib/format';
import InputError from '@/Components/InputError';
import Switch from '@/Components/Switch';
import { BankReceivedDateField } from '@/Components/BankReceivedDate';
import { BankDropdown, BankRow } from '@/Components/Banks';
import { balanceText, FieldLabel } from './AccountFormParts';

export const inputClass =
    'block h-[50px] w-full rounded-[14px] border-[1.5px] border-gray-200 bg-surface px-4 text-base text-gray-900 transition placeholder:text-gray-400 hover:border-brand-500/45 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 read-only:bg-gray-50 read-only:text-gray-500';

/** One way of paying, as a big radio tile. */
export function MethodTile({ value, checked, onChange, icon, title, hint }) {
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

/** The dark panel beside the form: what will be recorded and what it does to the balance, before saving. */
export function PaymentSummary({ amount, currency, inShekels, balance, methodText, collector }) {
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
export function PaymentReceipt({ receipt, subscriptionName, title, onAnother, onDone }) {
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
 * A transfer's details: the company bank it reached, the bank it came from
 * and the day it arrived, who sent it (the subscriber unless switched off)
 * and its reference, with what the reference check found.
 */
export function TransferFields({ data, setData, errors, transferBanks, senderBanks, senderIsSubscriber, onSenderToggle, checkingReference, referenceStatus }) {
    return (
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
                            onChange={onSenderToggle}
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
                    {checkingReference && <p className="mt-2 text-xs text-gray-500">جارٍ التحقق من الرقم المرجعي...</p>}
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
    );
}
