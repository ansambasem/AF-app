import Icon from '@/Components/Icon';
import { BALANCE_HINTS, BALANCE_TEXT_CLASSES } from '@/lib/balance';

/** A described balance (describeBalance()) as it reads everywhere: "50 ₪", "-9.10 ₪" or "0 ₪", coloured. */
export function BalanceText({ balance }) {
    return (
        <bdi dir="ltr" title={BALANCE_HINTS[balance.tone]} className={`inline-block whitespace-nowrap font-display font-bold tabular-nums ${BALANCE_TEXT_CLASSES[balance.tone]}`}>
            {balance.signed} ₪
        </bdi>
    );
}

/** The subscription's standing discount, taken off each weekly reading, beside their name. */
export function StandingDiscountBadge({ discount }) {
    return (
        <span
            title={discount.notes ?? undefined}
            className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
        >
            <Icon name="discount" className="h-3.5 w-3.5" />
            خصم القراءات الأسبوعية: {discount.terms}
            {discount.segment && ` · ${discount.segment}`}
        </span>
    );
}

/** What the balance will be once the form is saved. */
export function BalanceAfter({ label, balanceAfter, placeholder }) {
    return (
        <p className="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600">
            {label}: {balanceAfter ? <BalanceText balance={balanceAfter} /> : <span className="text-gray-400">{placeholder}</span>}
        </p>
    );
}
