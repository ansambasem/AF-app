import Icon from '@/Components/Icon';
import { formatMoney } from '@/lib/format';
import { BankMark } from '@/Components/Banks';

/** What a box's summary groups its entries by, for each type of box. */
const groupings = {
    bank_transfer: { title: 'ملخص الصندوق حسب البنك', key: (entry) => entry.bankName || 'بنك غير محدد' },
    clearing: { title: 'ملخص الصندوق حسب الخدمة', key: (entry) => entry.service || 'خدمة غير محددة' },
    cash: { title: 'ملخص الصندوق حسب عملة الدفع', key: (entry) => entry.currencyLabel || 'شيكل' },
};

/**
 * A box's entry (as the branch's boxes send it, with its transaction and
 * its statement line) in the shape the summary groups.
 */
export function breakdownEntry(entry) {
    return {
        amount: entry.amount,
        bankName: entry.ledger?.bankName ?? entry.transaction?.bank_name ?? null,
        service: entry.transaction?.notes ?? null,
        currencyLabel: entry.ledger?.currencyLabel ?? null,
        paidAmount: entry.ledger?.paidAmount ?? entry.amount,
        currency: entry.ledger && !entry.ledger.isShekel ? entry.ledger.currencyLabel : 'ILS',
    };
}

/**
 * The entries in effect, grouped by bank (transfers), service (clearings)
 * or the currency they were paid in (cash): each group's count, its total
 * in shekels and, for a foreign currency, what was paid in it. Largest
 * total first.
 */
export function groupBoxEntries(type, entries) {
    const groupKey = (groupings[type] ?? groupings.cash).key;
    const groups = new Map();
    entries.forEach((entry) => {
        const key = groupKey(entry);
        const group = groups.get(key) ?? { key, count: 0, total: 0, paid: 0, foreign: false };
        group.count += 1;
        group.total += Number(entry.amount);
        group.paid += Number(entry.paidAmount ?? entry.amount);
        group.foreign = group.foreign || (entry.currency && entry.currency !== 'ILS');
        groups.set(key, group);
    });

    return [...groups.values()].sort((a, b) => b.total - a.total || a.key.localeCompare(b.key, 'ar'));
}

function GroupMark({ type, label }) {
    if (type === 'bank_transfer') {
        return <BankMark bank={label} />;
    }

    return <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${type === 'clearing' ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'}`}><Icon name={type === 'clearing' ? 'scale' : 'banknotes'} className="h-3.5 w-3.5" /></span>;
}

/**
 * The summary the branch looks over before closing a box (or every open
 * box of a type): its entries in effect grouped by bank, service or
 * currency paid, each with its logo or icon, count and total.
 */
export default function BoxBreakdown({ type, entries, className = '' }) {
    const groups = groupBoxEntries(type, entries);
    const total = groups.reduce((sum, group) => sum + group.total, 0);
    const count = groups.reduce((sum, group) => sum + group.count, 0);
    if (!groups.length) {
        return null;
    }

    return <section aria-label={(groupings[type] ?? groupings.cash).title} className={`overflow-hidden rounded-card border border-gray-100 bg-surface p-4 ${className}`}>
        <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-xs font-semibold text-gray-500">{(groupings[type] ?? groupings.cash).title}</h3>
            <p className="text-xs text-gray-500"><b className="font-display text-gray-700">{count}</b> حركة سارية · <b dir="ltr" className="font-display text-gray-700">{formatMoney(total)}</b> شيكل</p>
        </header>
        <ul className="flex flex-wrap gap-2">
            {groups.map((group) => <li key={group.key} className="inline-flex max-w-full flex-wrap items-center gap-2 rounded-full border border-gray-200 bg-surface py-1 pe-3 ps-1.5 text-xs">
                <GroupMark type={type} label={group.key} />
                <span className="min-w-0 break-words font-semibold text-gray-700">{group.key}</span>
                <span className="text-gray-500"><b className="font-display">{group.count}</b> حركة</span>
                <span className="text-gray-900"><bdi dir="ltr" className="font-display font-bold">{formatMoney(group.total)}</bdi> شيكل</span>
                {group.foreign && <span className="text-gray-500">دُفع <bdi dir="ltr" className="font-display">{formatMoney(group.paid)}</bdi></span>}
            </li>)}
        </ul>
    </section>;
}
