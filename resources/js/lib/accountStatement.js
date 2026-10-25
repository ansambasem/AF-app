import { csvText } from './csv.js';
import { formatAmount, roundToCents } from './currency.js';
import { signedBalance } from './balance.js';

/**
 * The statement page's filters, applied in the browser. Every line already
 * carries the balance it left, so hiding some lines never changes the
 * balances shown on the others.
 */
/**
 * Whether a line matches the "نوع الحركة" filter: 'debit' (every charge),
 * 'credit' (every payment and discount), or one type of line.
 */
function matchesType(entry, type) {
    if (type === 'debit') {
        return !entry.isCredit;
    }

    if (type === 'credit') {
        return entry.isCredit;
    }

    return entry.type === type;
}

export function filterStatementEntries(entries, { search = '', type = '', method = '', dateFrom = '', dateTo = '' } = {}) {
    if (dateFrom && dateTo && dateFrom > dateTo) {
        return [];
    }

    const query = search.trim().toLocaleLowerCase();

    return entries.filter((entry) => {
        const date = entry.date.slice(0, 10);
        const text = [
            entry.description,
            entry.typeLabel,
            entry.details,
            entry.voucherNumber,
            entry.systemVoucherNumber,
            entry.manualVoucherNumber,
            entry.amount,
            entry.recordedByName,
            entry.bankName,
            entry.senderBankName,
            entry.referenceNumber,
        ]
            .filter((value) => value !== undefined && value !== null)
            .join(' ')
            .toLocaleLowerCase();

        return (
            (!query || text.includes(query)) &&
            (!type || matchesType(entry, type)) &&
            (!method || entry.paymentMethod === method) &&
            (!dateFrom || date >= dateFrom) &&
            (!dateTo || date <= dateTo)
        );
    });
}

function cents(value) {
    return Math.round(Number(value) * 100);
}

function money(valueInCents) {
    return (valueInCents / 100).toFixed(2);
}

/**
 * The line a folded line shows under in the compact statement: the
 * standing line that corrected it (following a chain of corrections), or
 * else the standing line billed from the same weekly reading. Null when
 * nothing replaced it — a plain cancellation or refund.
 */
function foldAnchor(original, byId, hidden, entries) {
    let line = original;

    for (let step = 0; line && hidden.has(line.id) && step < entries.length; step++) {
        line = line.cancellation?.correctionId ? byId.get(line.cancellation.correctionId) : null;
    }

    if (line && !hidden.has(line.id)) {
        return line.id;
    }

    if (!original.meterReadingId) {
        return null;
    }

    const sameReading = entries.filter((entry) => !hidden.has(entry.id) && entry.meterReadingId === original.meterReadingId);

    return (sameReading.find((entry) => entry.type === original.type) ?? sameReading.find((entry) => entry.type === 'meter_reading'))?.id ?? null;
}

/**
 * The statement without what was undone: every cancelled line whose
 * reversals take back exactly its amount is left out together with them,
 * since the pair adds up to nothing. Each line left shows the balance as
 * if they had never been recorded, which ends where the full statement
 * ends. A line that corrected a left-out one (or was billed again from the
 * same reading) carries it, with its reversal, in `history`, oldest first.
 * A line cancelled only partly (a payment refunded in part) stays.
 *
 * @returns {{ entries: Array<object>, hiddenCount: number }}
 */
export function compactStatementEntries(entries) {
    const byId = new Map(entries.map((entry) => [entry.id, entry]));
    const effects = new Map();
    const reversalsOf = new Map();
    let previousBalance = 0;

    for (const entry of entries) {
        effects.set(entry.id, cents(entry.balance) - previousBalance);
        previousBalance = cents(entry.balance);

        if (entry.reverses) {
            reversalsOf.set(entry.reverses.id, [...(reversalsOf.get(entry.reverses.id) ?? []), entry]);
        }
    }

    const hidden = new Set();

    for (const entry of entries) {
        const reversals = reversalsOf.get(entry.id) ?? [];
        const net = reversals.reduce((sum, reversal) => sum + effects.get(reversal.id), effects.get(entry.id));

        if (entry.cancellation && reversals.length && net === 0) {
            [entry, ...reversals].forEach((line) => hidden.add(line.id));
        }
    }

    const histories = new Map();

    for (const entry of entries) {
        if (hidden.has(entry.id)) {
            const original = entry.cancellation ? entry : byId.get(entry.reverses.id);
            const anchor = foldAnchor(original, byId, hidden, entries);

            if (anchor !== null) {
                histories.set(anchor, [...(histories.get(anchor) ?? []), entry]);
            }
        }
    }

    let balance = 0;
    const shown = entries
        .filter((entry) => !hidden.has(entry.id))
        .map((entry) => {
            balance += effects.get(entry.id);

            return { ...entry, balance: money(balance), history: histories.get(entry.id) ?? [] };
        });

    return { entries: shown, hiddenCount: hidden.size };
}

/**
 * Shows a weekly reading's standing discount inside its reading's line:
 * a discount line right after the standing reading line it was billed
 * with becomes that line's `discountLine`, and the line shows the balance
 * after both. Anything else is left as it is.
 */
export function withReadingDiscounts(entries) {
    const rows = [];

    for (const entry of entries) {
        const reading = rows.at(-1);

        if (
            entry.type === 'reading_discount' &&
            !entry.cancellation &&
            entry.meterReadingId &&
            reading?.type === 'meter_reading' &&
            !reading.cancellation &&
            !reading.discountLine &&
            reading.meterReadingId === entry.meterReadingId
        ) {
            rows[rows.length - 1] = {
                ...reading,
                balance: entry.balance,
                discountLine: entry,
                history: [...(reading.history ?? []), ...(entry.history ?? [])].sort((first, second) => first.lineNumber - second.lineNumber),
            };
        } else {
            rows.push(entry);
        }
    }

    return rows;
}

/**
 * Which statement lines belong together: a line, what cancelled or
 * refunded it, what corrected it, and the standing discount billed from
 * the same weekly reading. Maps the id of each line in such a chain to
 * the chain's number (1, 2, … in order of its first line); a line on its
 * own is left out.
 *
 * @returns {Map<number, number>}
 */
export function relatedLineChains(entries) {
    const ids = new Set(entries.map((entry) => entry.id));
    const parent = new Map(entries.map((entry) => [entry.id, entry.id]));
    const root = (id) => {
        while (parent.get(id) !== id) {
            parent.set(id, parent.get(parent.get(id)));
            id = parent.get(id);
        }

        return id;
    };
    const link = (first, second) => {
        if (ids.has(first) && ids.has(second)) {
            parent.set(root(second), root(first));
        }
    };
    const readings = new Map();

    for (const entry of entries) {
        link(entry.id, entry.reverses?.id);
        link(entry.id, entry.corrects?.id);
        link(entry.id, entry.cancellation?.correctionId);

        if (entry.meterReadingId && readings.has(entry.meterReadingId)) {
            link(readings.get(entry.meterReadingId), entry.id);
        } else if (entry.meterReadingId) {
            readings.set(entry.meterReadingId, entry.id);
        }
    }

    const sizes = new Map();

    for (const entry of entries) {
        sizes.set(root(entry.id), (sizes.get(root(entry.id)) ?? 0) + 1);
    }

    const numbers = new Map();
    const chains = new Map();

    for (const entry of entries) {
        const chain = root(entry.id);

        if (sizes.get(chain) > 1) {
            if (!numbers.has(chain)) {
                numbers.set(chain, numbers.size + 1);
            }

            chains.set(entry.id, numbers.get(chain));
        }
    }

    return chains;
}

/** Each chain of related lines gets one of these colours (cycling), as "r g b". */
const CHAIN_COLORS = ['37 99 235', '217 119 6', '147 51 234', '13 148 136', '219 39 119', '101 163 13'];

/** The colour of a chain of related lines (its number from relatedLineChains()), or null for a line on its own. */
export function chainColor(chain) {
    return chain ? CHAIN_COLORS[(chain - 1) % CHAIN_COLORS.length] : null;
}

/** What a reading's line comes to after its standing discount, in shekels. */
export function netOfReadingDiscount(entry) {
    return money(cents(entry.amount) - cents(entry.discountLine?.amount ?? 0));
}

/**
 * The two ways a statement can be shown: compact (without cancelled lines
 * and the reversals that take them back) or every line as recorded.
 */
export const STATEMENT_VIEWS = [
    { value: 'compact', label: 'عرض مختصر', hint: 'دون الحركات الملغاة وقيودها العكسية، وخصم القراءة الأسبوعية داخل قراءتها' },
    { value: 'full', label: 'كل الحركات', hint: 'كل حركة كما سُجّلت، ومنها الملغاة وقيودها العكسية' },
];

/**
 * How a balance reads: a positive balance is what the subscription owes
 * (عليه), a negative one is credit in their favour (له). `signed` is how it
 * is shown (see lib/balance.js): the number with its minus sign when it is
 * credit; `amount` is its size without the sign.
 */
export function describeBalance(balance) {
    const value = Number(balance);

    if (value > 0) {
        return { amount: formatAmount(value), signed: signedBalance(value), label: 'عليه', tone: 'owes' };
    }

    if (value < 0) {
        return { amount: formatAmount(-value), signed: signedBalance(value), label: 'له', tone: 'credit' };
    }

    return { amount: '0', signed: '0', label: 'مسدّد', tone: 'settled' };
}

/** A line's state as the statement marks it: corrected, cancelled, a correction, or nothing. */
function entryState(entry) {
    if (entry.cancellation) {
        return entry.cancellation.wasCorrected ? 'مُصحّحة' : 'ملغاة';
    }

    return entry.isCorrection ? 'تصحيح' : '';
}

/**
 * The statement's lines (the ones shown, oldest first) as a CSV file for
 * Excel: every column of the statement, with each line's side (عليه or
 * له) and the balance it left, in shekels, with its sign (negative is
 * credit), as the accountant adds it up.
 */
export function statementCsv(entries) {
    const rows = [
        [
            '#', 'تاريخ الحركة', 'رقم السند', 'البيان', 'التفاصيل', 'نوع الحركة', 'عليه / له', 'المبلغ', 'العملة', 'سعر الصرف',
            'طريقة الدفع', 'البنك المرسل', 'البنك المستلم', 'الرقم المرجعي', 'رقم الصندوق', 'الرصيد (شيكل)', 'حالة الرصيد',
            'الحالة', 'اسم المستخدم', 'تاريخ الدفع الفعلي', 'سبب تأخر التسجيل', 'تاريخ وصول الحوالة إلى البنك',
        ],
        ...entries.map((entry) => {
            const balance = describeBalance(entry.balance);

            return [
                entry.lineNumber, entry.date, entry.voucherNumber ?? '', entry.description, entry.details ?? '', entry.typeLabel,
                entry.isCredit ? 'له' : 'عليه', Number(entry.amount), entry.currencyLabel, entry.exchangeRate ?? '',
                entry.paymentMethodLabel ?? '', entry.senderBankName ?? '', entry.bankName ?? '', entry.referenceNumber ?? '', entry.cashBox ?? '',
                Number(entry.balance), balance.label, entryState(entry), entry.recordedByName ?? '', entry.paidOn ?? '', entry.lateReason ?? '', entry.bankReceivedOn ?? '',
            ];
        }),
    ];

    return csvText(rows);
}

/**
 * The shekels a payment takes off the balance: the amount at the
 * exchange rate (always 1 for shekels). Null until both are valid.
 */
export function paymentInShekels(amount, currency, exchangeRate) {
    const value = Number(amount);
    const rate = currency === 'ILS' ? 1 : Number(exchangeRate);

    if (!(value > 0) || !(rate > 0)) {
        return null;
    }

    return roundToCents(value * rate);
}

/**
 * What a discount takes off, in shekels: a percentage of what the
 * subscription owes, kilowatts at their kilo price, or the shekels given.
 * Mirrors SubscriptionTransaction::discountFor(). Null until the value is valid.
 */
export function discountAmount(method, value, owed, kiloPrice) {
    const number = Number(value);

    if (value === '' || !(number > 0)) {
        return null;
    }

    const amount = { percentage: (Number(owed) * number) / 100, kilowatt: number * Number(kiloPrice), shekel: number }[method];

    return amount === undefined ? null : roundToCents(amount);
}
