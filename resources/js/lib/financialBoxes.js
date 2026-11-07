const currencyCodes = ['ILS', 'USD', 'JOD'];
const statusOrder = ['open', 'received'];
const boxState = (box) => box.status === 'pending' ? 'open' : box.status;

export const activeBoxEntries = (box) => (box.payments ?? []).filter((entry) => !entry.transaction || entry.transaction.status === 'active');
export const boxPersonName = (box) => box.user?.name ?? '—';

const searchableText = (value) => String(value ?? '').replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660)).toLocaleLowerCase('ar').trim();

/** The receiving bank or wallet, including entries saved before ledger details were available. */
export const boxEntryBank = (entry) => String(entry.ledger?.bankName ?? entry.transaction?.bank_name ?? '').trim() || 'بنك غير محدد';

export function boxBankOptions(entries) {
    const counts = new Map();
    entries.forEach((entry) => {
        const bank = boxEntryBank(entry);
        counts.set(bank, (counts.get(bank) ?? 0) + 1);
    });

    return [...counts].sort(([a], [b]) => a.localeCompare(b, 'ar')).map(([value, count]) => ({ value, count }));
}

/** Bank, status and text filters narrow the same set of entries together. */
export function filterBoxEntries(entries, { bank = '', state = 'all', search = '' } = {}) {
    return entries.filter((entry) => {
        const active = (entry.transactionStatus ?? entry.transaction?.status ?? 'active') === 'active';
        const matchesState = state === 'active' ? active && !isFlaggedEntry(entry)
            : state === 'rejected' ? active && isFlaggedEntry(entry)
                : state === 'cancelled' ? !active : true;

        return (!bank || boxEntryBank(entry) === bank) && matchesState && boxEntryMatches(entry, search);
    });
}

export function boxEntryMatches(entry, search) {
    const query = searchableText(search);
    const transaction = entry.transaction ?? {};
    return !query || [transaction.subscription?.subscription_name, transaction.subscription?.full_name,
        transaction.subscription?.account_number, transaction.manual_voucher_number, transaction.voucher_number,
        transaction.reference_number, transaction.bank_name, transaction.notes, entry.ledger?.voucherNumber,
        entry.ledger?.referenceNumber].some((value) => searchableText(value).includes(query));
}

export function financialBoxMatches(box, search) {
    const query = searchableText(search);
    return !query || [box.number, boxPersonName(box)].some((value) => searchableText(value).includes(query))
        || (box.payments ?? []).some((entry) => boxEntryMatches(entry, query));
}

/** Active entries only; currencies stay separate, including old foreign cash. */
export function financialBoxTotals(box) {
    if (!box.payments) {
        return Object.fromEntries(currencyCodes.map((currency) => [currency, Number(box.totals?.[currency] ?? 0)]));
    }

    const entries = activeBoxEntries(box);
    return Object.fromEntries(currencyCodes.map((currency) => [currency, entries.filter((entry) => entry.currency === currency).reduce((sum, entry) => sum + Number(entry.amount), 0)]));
}

export function financialBoxesTotals(boxes) {
    return boxes.reduce((totals, box) => {
        const amounts = financialBoxTotals(box);
        return Object.fromEntries(currencyCodes.map((currency) => [currency, totals[currency] + amounts[currency]]));
    }, { ILS: 0, USD: 0, JOD: 0 });
}

export function filterFinancialBoxes(boxes, { status = 'all', person = '', search = '' } = {}) {
    return boxes.filter((box) => (status === 'all' || boxState(box) === status)
        && (!person || String(box.user?.id) === person)
        && financialBoxMatches(box, search));
}

export function sortFinancialBoxes(boxes, { key = 'status', direction = 'asc' } = {}) {
    const values = {
        number: (box) => Number(box.number),
        total: (box) => financialBoxTotals(box).ILS,
        dates: (box) => new Date(box.closedAt ?? box.closed_at ?? box.opened_at).getTime() || 0,
        audited: (box) => box.audit?.audited_at ? new Date(box.audit.audited_at).getTime() || null : null,
        status: (box) => statusOrder.indexOf(boxState(box)),
    };
    const value = values[key] ?? values.status;
    return [...boxes].sort((a, b) => {
        const first = value(a);
        const second = value(b);
        if (first === null || second === null) {
            if (first !== second) {
                return first === null ? 1 : -1;
            }
        }

        return (first - second) * (direction === 'asc' ? 1 : -1)
            || statusOrder.indexOf(boxState(a)) - statusOrder.indexOf(boxState(b)) || Number(a.number) - Number(b.number);
    });
}

/**
 * A box stays open until the branch confirms it closed.
 */
export const boxStatusLabels = {
    open: ['مفتوح', 'blue'],
    received: ['مُغلق', 'green'],
};

/** Historical audit metadata does not change the open/closed status. */
export function boxStatus(box) {
    return boxStatusLabels[boxState(box)];
}

/**
 * An entry's status as everyone sees it, with its pill tone: in effect,
 * cancelled on the subscriber's statement, or a transfer or clearing found
 * wrong when it was checked. Confirming transfers and clearings is the
 * auditors' work, so an entry not yet checked simply stays in effect.
 * Takes an entry as the box pages send it (`transactionStatus`) or as the
 * branch's boxes do (`transaction.status`).
 */
export function entryStatus(entry) {
    if ((entry.transactionStatus ?? entry.transaction?.status ?? 'active') !== 'active') {
        return ['ملغاة', 'gray'];
    }
    if (entry.status === 'missing') {
        return ['غير موجودة', 'red'];
    }
    if (entry.status === 'rejected') {
        return ['مرفوضة', 'red'];
    }

    return ['سارية', 'green'];
}

/** Whether a transfer or clearing was found wrong when it was checked. */
export const isFlaggedEntry = (entry) => ['missing', 'rejected'].includes(entry.status);

/** The financial effects still awaiting final approval; historical postings stay out. */
export function auditApprovalSummary(boxes) {
    const pending = boxes.filter((box) => box.status === 'received' && !box.audit);
    const transfers = pending.filter((box) => box.type === 'bank_transfer').flatMap(activeBoxEntries);
    const drafts = transfers.filter((entry) => entry.audit && !entry.audit.posted_at && !entry.audit.adjustment_transaction_id);
    const differences = drafts.map((entry) => Math.round(Number(entry.audit.difference) * 100));
    return {
        boxes: pending.length, transfers: transfers.length,
        exceptions: differences.filter((amount) => amount !== 0).length,
        automaticMatches: transfers.filter((entry) => !entry.audit).length,
        debit: differences.filter((amount) => amount > 0).reduce((sum, amount) => sum + amount, 0) / 100,
        credit: -differences.filter((amount) => amount < 0).reduce((sum, amount) => sum + amount, 0) / 100,
    };
}
