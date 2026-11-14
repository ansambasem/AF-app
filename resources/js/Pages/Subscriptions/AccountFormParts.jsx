import { usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { describeBalance } from '@/lib/accountStatement';
import { BALANCE_CHIP_CLASSES, BALANCE_HINTS, balanceInText } from '@/lib/balance';
import { initials } from '@/lib/format';

/**
 * The pieces the payment, charge and discount windows share: the
 * subscription strip, labels, choice tiles, the big amount box, the dark
 * summary panel beside the form, and the done screen after saving.
 */

const STATUS_DOTS = { active: 'bg-emerald-500', suspended: 'bg-amber-500', disconnected: 'bg-gray-400' };

/** A balance's chip, by its tone (see lib/balance.js). */
export const BALANCE_CHIPS = BALANCE_CHIP_CLASSES;

/** Each tone's selected tile and header icon: red for a charge (عليه), green for a discount (له), graphite otherwise. */
const TONES = {
    red: {
        tile: 'border-brand-500 shadow-[0_0_0_4px_rgb(var(--brand-500)/0.12)]',
        icon: 'bg-brand-gradient text-white',
        dot: 'border-brand-500 bg-brand-500',
    },
    green: {
        tile: 'border-emerald-600 shadow-[0_0_0_4px_rgb(5_150_105/0.12)]',
        icon: 'bg-gradient-to-br from-emerald-600 to-emerald-800 text-white',
        dot: 'border-emerald-600 bg-emerald-600',
    },
    amber: {
        tile: 'border-amber-600 shadow-[0_0_0_4px_rgb(217_119_6/0.12)]',
        icon: 'bg-gradient-to-br from-amber-500 to-amber-700 text-white',
        dot: 'border-amber-600 bg-amber-600',
    },
    graphite: {
        tile: 'border-gray-900 shadow-[0_0_0_4px_rgb(var(--gray-900)/0.07)]',
        icon: 'bg-graphite-gradient text-white',
        dot: 'border-gray-900 bg-gray-900',
    },
};

/** A described balance (describeBalance()) as text: "377 ₪", "-9.10 ₪" or "0 ₪", its minus kept before the number. */
export function balanceText(balance) {
    return balanceInText(balance.signed);
}

/** A field's label, as FormField draws it: the star when required, the guidance (`hint`) behind an "!". */
export function FieldLabel({ htmlFor, required = false, hint, children }) {
    return (
        <div className="mb-2 flex items-center gap-1">
            <InputLabel htmlFor={htmlFor}>
                {children}
                {required && <span className="text-brand-500"> *</span>}
            </InputLabel>
            {hint && <InfoTip label={`عن حقل ${typeof children === 'string' ? children : ''}`.trim()}>{hint}</InfoTip>}
        </div>
    );
}

/** The subscription at the top of the form, with their balance. */
export function SubscriptionStrip({ subscription, balance, balanceLabel = 'الرصيد الحالي' }) {
    const described = describeBalance(balance);

    return (
        <div className="flex flex-wrap items-center gap-3.5 rounded-[18px] border border-gray-100 bg-gray-50 px-3.5 py-3">
            <div className="flex min-w-0 flex-1 basis-52 items-center gap-3.5">
                <span className="relative flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-[14px] bg-graphite-gradient font-display text-[15px] font-bold text-white">
                    {initials(subscription.fullName)}
                    <span
                        className={`absolute -bottom-0.5 -start-0.5 h-[13px] w-[13px] rounded-full border-[2.5px] border-gray-50 ${STATUS_DOTS[subscription.status] ?? 'bg-gray-400'}`}
                        aria-hidden="true"
                    />
                </span>
                <div className="min-w-0">
                    <p className="break-words text-[16.5px] font-bold text-gray-900">{subscription.fullName}</p>
                    <p className="text-[13.5px] text-gray-500">
                        حساب{' '}
                        <span dir="ltr" className="font-display">
                            {subscription.accountNumber}
                        </span>
                        {subscription.meterBoxNumber && ` · طبلون ${subscription.meterBoxNumber}`}
                        {subscription.branchName && ` · ${subscription.branchName}`}
                    </p>
                </div>
            </div>
            <div className="ms-auto flex shrink-0 flex-col items-end gap-0.5">
                <span className="text-[12.5px] text-gray-500">{balanceLabel}</span>
                <span
                    title={BALANCE_HINTS[described.tone]}
                    className={`whitespace-nowrap rounded-[10px] px-2.5 py-0.5 font-display text-[17px] font-bold ${BALANCE_CHIPS[described.tone]}`}
                >
                    {balanceText(described)}
                </span>
            </div>
        </div>
    );
}

/**
 * One choice as a big radio tile, in the form's tone. `stacked` puts the
 * icon above the words (three in a row); `shortcut` is the number key
 * that picks it.
 */
export function ChoiceTile({ name, value, checked, onChange, icon, title, hint, tone = 'graphite', stacked = false, shortcut = null }) {
    const look = TONES[tone];

    return (
        <label
            className={`relative flex cursor-pointer gap-3 rounded-[18px] border-[1.5px] bg-surface px-4 py-3.5 text-start transition focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-gray-900 ${
                stacked ? 'items-center sm:flex-col sm:items-start sm:gap-2 sm:px-3.5' : 'items-center'
            } ${checked ? look.tile : 'border-gray-200 hover:border-gray-300'}`}
        >
            <input type="radio" name={name} value={value} checked={checked} onChange={() => onChange(value)} className="sr-only" />
            <span
                className={`flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-[13px] transition ${checked ? look.icon : 'bg-gray-100 text-gray-700'}`}
            >
                <Icon name={icon} className="h-[18px] w-[18px]" strokeWidth={1.8} />
            </span>
            <span className="min-w-0">
                <b className="block text-[15.5px] text-gray-900">{title}</b>
                {hint && <small className="block text-[13px] leading-snug text-gray-500">{hint}</small>}
            </span>
            <span
                className={`flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition ${
                    stacked ? 'ms-auto sm:absolute sm:end-3 sm:top-3 sm:ms-0' : 'ms-auto'
                } ${checked ? look.dot : 'border-gray-300'}`}
                aria-hidden="true"
            >
                {checked && <span className="h-2 w-2 rounded-full bg-surface" />}
            </span>
            {shortcut && (
                <span
                    className="absolute end-11 top-3 hidden rounded-md border border-gray-100 bg-surface px-1.5 font-display text-[11px] font-semibold leading-5 text-gray-500 sm:inline-block"
                    aria-hidden="true"
                >
                    {shortcut}
                </span>
            )}
        </label>
    );
}

/**
 * The big amount field: the number, its unit beside it, and a footer of
 * quick picks (`children`). Red while `error` is set.
 */
export function AmountBox({ id, inputRef, value, onChange, unit, placeholder = '0', label, error = false, autoFocus = false, readOnly = false, children }) {
    return (
        <div
            className={`rounded-[22px] border-[1.5px] bg-surface px-[18px] pb-3.5 pt-4 transition ${
                error
                    ? 'border-brand-500 shadow-[0_0_0_5px_rgb(var(--brand-500)/0.08)]'
                    : 'border-gray-200 focus-within:border-brand-500 focus-within:shadow-[0_0_0_5px_rgb(var(--brand-500)/0.12)]'
            }`}
        >
            <div className="flex items-center gap-3.5">
                <input
                    ref={inputRef}
                    autoFocus={autoFocus}
                    id={id}
                    name={id}
                    required
                    inputMode="decimal"
                    autoComplete="off"
                    dir="ltr"
                    placeholder={placeholder}
                    aria-label={label}
                    value={value}
                    readOnly={readOnly}
                    onChange={onChange}
                    className="w-full min-w-0 flex-1 border-0 bg-transparent p-0 text-end font-display text-[34px] font-extrabold leading-tight text-gray-900 placeholder:text-gray-300 focus:ring-0 sm:text-[44px]"
                />
                <span className="flex h-12 min-w-16 shrink-0 flex-col items-center justify-center rounded-[14px] border border-gray-100 bg-gray-100 px-3.5 font-display text-xl font-extrabold leading-none text-gray-700">
                    {unit}
                </span>
            </div>
            {children && <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-gray-200 pt-3">{children}</div>}
        </div>
    );
}

/** A quick value under the amount; `tone="green"` marks the one that settles everything. */
export function QuickPick({ onClick, tone = null, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1 text-[13.5px] font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 ${
                tone === 'green'
                    ? 'border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                    : 'border-gray-100 bg-gray-50 text-gray-700 hover:border-gray-200 hover:bg-surface hover:text-gray-900'
            }`}
        >
            {children}
        </button>
    );
}

/** An amber note under a field, or red with `bad`. */
export function FieldWarning({ bad = false, children }) {
    return (
        <p
            className={`mt-2.5 flex items-start gap-2.5 rounded-[14px] border px-3 py-2.5 text-[13.5px] leading-relaxed ${
                bad ? 'border-brand-500/30 bg-brand-500/5 text-brand-600' : 'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-300'
            }`}
        >
            <Icon name="warning" className="mt-0.5 h-[18px] w-[18px] shrink-0" />
            <span>{children}</span>
        </p>
    );
}

/** The dark panel beside the form: what saving will do, before it is saved. */
export function SummaryPanel({ title, tag, children }) {
    const user = usePage().props.auth?.user?.name ?? '';
    const today = new Date().toLocaleDateString('ar-EG-u-nu-latn', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    return (
        <aside
            aria-live="polite"
            className="relative flex flex-col gap-[18px] overflow-hidden bg-graphite-gradient p-5 text-white sm:p-6 lg:col-start-2 lg:row-span-3 lg:row-start-1 lg:overflow-y-auto"
        >
            <span className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" aria-hidden="true" />
            <span
                className="pointer-events-none absolute -bottom-32 -end-20 h-80 w-80 rounded-full bg-[radial-gradient(closest-side,rgb(165_29_38/0.25),transparent)]"
                aria-hidden="true"
            />

            <h4 className="relative flex items-center gap-2 font-luxe text-lg font-bold">
                <Icon name="receipt" className="h-[18px] w-[18px]" strokeWidth={1.8} />
                {title}
                {tag && (
                    <span className="ms-auto rounded-full bg-white/10 px-2.5 py-0.5 font-sans text-[11.5px] font-semibold text-white/80">{tag}</span>
                )}
            </h4>

            {children}

            <ul className="relative hidden gap-2 text-[13.5px] text-white/75 lg:grid">
                <li className="flex items-center gap-2">
                    <Icon name="user" className="h-[18px] w-[18px] text-white/50" />
                    يُسجَّل باسم <b className="font-semibold text-white">{user}</b>
                </li>
                <li className="flex items-center gap-2">
                    <Icon name="calendar" className="h-[18px] w-[18px] text-white/50" />
                    <b className="font-semibold text-white">{today}</b>
                </li>
                <li className="flex items-center gap-2">
                    <Icon name="ledger" className="h-[18px] w-[18px] text-white/50" />
                    يظهر في كشف حساب المشترك
                </li>
            </ul>
        </aside>
    );
}

/** The big figure at the top of the summary, green or red, with a line under it. */
export function SummaryFigure({ label, value, tone, note }) {
    return (
        <div className="relative">
            <p className="text-[13px] text-white/60">{label}</p>
            <p
                className={`text-end font-display text-[32px] font-extrabold leading-tight sm:text-[40px] ${tone === 'green' ? 'text-emerald-200' : tone === 'red' ? 'text-red-200' : ''}`}
                dir="ltr"
            >
                {value}
                <span className="ms-1.5 text-lg font-semibold text-white/70">₪</span>
            </p>
            {note && <p className="mt-0.5 text-[13px] text-white/60">{note}</p>}
        </div>
    );
}

/**
 * The summary's rows: `[label, value, tone?]`, a line, then the last row
 * larger. `tone` is 'green', 'red', or 'after' to color the result by the
 * balance it leaves.
 */
export function SummaryLedger({ rows, result }) {
    return (
        <dl className="relative grid gap-2.5 rounded-[18px] border border-white/10 bg-white/5 p-3.5 text-sm text-white/75">
            {rows.map(([label, value, tone]) => (
                <div key={label} className="flex items-baseline justify-between gap-3">
                    <dt>{label}</dt>
                    <dd
                        className={`font-display text-[15px] font-semibold ${tone === 'green' ? 'text-emerald-300' : tone === 'red' ? 'text-red-300' : 'text-white'}`}
                    >
                        {value}
                    </dd>
                </div>
            ))}
            {result && (
                <>
                    <div className="h-px bg-white/10" aria-hidden="true" />
                    <div className="flex items-baseline justify-between gap-3">
                        <dt>{result.label}</dt>
                        <dd className={`font-display text-lg font-semibold ${result.className ?? 'text-white'}`}>{result.value}</dd>
                    </div>
                </>
            )}
        </dl>
    );
}

/** On the dark summary panel: red while owed, green in credit, plain when settled. */
const PANEL_BALANCE_CLASSES = { owes: 'text-red-300', credit: 'text-emerald-300', settled: 'text-white' };

/** The balance a save leaves, coloured as balances are everywhere (see lib/balance.js). */
export function balanceAfterRow(label, balance) {
    const after = balance === null ? null : describeBalance(balance);

    return {
        label,
        value: after ? balanceText(after) : '—',
        className: after ? PANEL_BALANCE_CLASSES[after.tone] : 'text-white/50',
    };
}

/** After saving: what was recorded, as a receipt, with its voucher to print (`printUrl`), "another" and "done". */
export function DoneScreen({ tone = 'green', title, text, rows, anotherLabel, onAnother, onDone, printUrl = null }) {
    return (
        <div role="dialog" aria-modal="true" aria-label={title} className="px-6 pb-8 pt-10 text-center sm:px-8">
            <span
                className={`mx-auto mb-3.5 flex h-[76px] w-[76px] items-center justify-center rounded-3xl ${
                    tone === 'red'
                        ? 'bg-brand-500/10 text-brand-600'
                        : tone === 'amber'
                          ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                          : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                }`}
            >
                <Icon name="check" className="h-10 w-10" strokeWidth={2.2} />
            </span>
            <h3 className="font-luxe text-[26px] font-bold text-gray-900">{title}</h3>
            <p className="mt-1.5 text-gray-600">{text}</p>
            <dl className="mx-auto mt-6 w-full max-w-[420px] rounded-[20px] border border-gray-100 bg-gray-50 px-[18px] py-1.5 text-start">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4 border-b border-dashed border-gray-200 py-2.5 text-[14.5px] last:border-0">
                        <dt className="text-gray-500">{label}</dt>
                        <dd className="font-semibold text-gray-900">{value}</dd>
                    </div>
                ))}
            </dl>
            <div className="mt-6 flex flex-wrap justify-center gap-2.5">
                {printUrl && (
                    <SecondaryButton onClick={() => window.open(printUrl, '_blank')} title="يُفتح السند في نافذة جديدة جاهزًا للطباعة" className="tone-print h-12 rounded-[14px] px-5 text-[15.5px]">
                        <Icon name="printer" className="h-[18px] w-[18px]" />
                        طباعة السند
                    </SecondaryButton>
                )}
                {onAnother && (
                    <SecondaryButton onClick={onAnother} className="h-12 rounded-[14px] px-5 text-[15.5px]">
                        <Icon name="plus" className="h-[18px] w-[18px]" strokeWidth={2} />
                        {anotherLabel}
                    </SecondaryButton>
                )}
                <PrimaryButton type="button" onClick={onDone} autoFocus className="h-12 min-w-28 rounded-[14px] px-5 text-[15.5px]">
                    تم
                </PrimaryButton>
            </div>
        </div>
    );
}

/** The window's header: its icon in the form's tone, title and one line under it, and close. */
export function FormHeader({ titleId, icon, tone = 'graphite', title, subtitle, onClose }) {
    return (
        <div className="flex shrink-0 items-center gap-3.5 border-b border-gray-100 px-5 py-4 sm:px-6 sm:py-5 lg:col-start-1 lg:row-start-1">
            <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px] shadow-sm ${TONES[tone].icon}`}>
                <Icon name={icon} className="h-[18px] w-[18px]" strokeWidth={1.8} />
            </span>
            <div className="min-w-0">
                <h3 id={titleId} className="font-luxe text-[22px] font-bold leading-tight text-gray-900">
                    {title}
                </h3>
                <p className="mt-0.5 text-[13.5px] text-gray-500">{subtitle}</p>
            </div>
            <button
                type="button"
                onClick={onClose}
                aria-label="إغلاق"
                className="ms-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-gray-100 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
            >
                <Icon name="close" className="h-[18px] w-[18px]" strokeWidth={2} />
            </button>
        </div>
    );
}

/** The window's footer: shortcuts, cancel, and the save button in the form's tone. */
export function FormFooter({ onCancel, submitLabel, disabled, processing, tone = 'red', shortcuts = null }) {
    return (
        <div className="flex shrink-0 items-center gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:px-6 lg:col-start-1 lg:row-start-3">
            <SecondaryButton onClick={onCancel} className="h-12 rounded-[14px] px-5 text-[15.5px]">
                إلغاء
            </SecondaryButton>
            <span className="hidden items-center gap-1.5 text-[13px] text-gray-500 xl:flex">
                <span className="kbd" dir="ltr">
                    Ctrl + Enter
                </span>
                للحفظ{shortcuts && <> · {shortcuts}</>}
            </span>
            <button
                type="submit"
                disabled={disabled || processing}
                className={`ms-auto inline-flex h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-[14px] px-5 text-[15.5px] font-bold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:cursor-not-allowed disabled:opacity-55 disabled:shadow-none disabled:grayscale-[0.6] sm:min-w-[230px] sm:flex-none ${
                    tone === 'green'
                        ? 'bg-gradient-to-b from-emerald-600 to-emerald-800 shadow-[0_12px_26px_-12px_rgb(4_120_87)]'
                        : tone === 'amber'
                          ? 'bg-gradient-to-b from-amber-500 to-amber-700 shadow-[0_12px_26px_-12px_rgb(180_83_9)]'
                        : 'bg-brand-gradient shadow-[0_12px_26px_-12px_rgb(165_29_38)]'
                }`}
            >
                <Icon name="check" className="h-[18px] w-[18px]" strokeWidth={2.2} />
                {processing ? 'جارٍ الحفظ...' : submitLabel}
            </button>
        </div>
    );
}
