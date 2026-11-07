import BankReceivedDate from '@/Components/BankReceivedDate';
import './financial-boxes.css';
import StatusPill from '@/Components/DataTable/StatusPill';
import LatePaymentDetails from '@/Components/LatePaymentDetails';
import { formatMoney } from '@/lib/format';

const currencyLabels = { ILS: 'شيكل', USD: 'دولار', JOD: 'دينار' };

/** A cell holding more than one line, which wraps rather than cutting its text. */
const stacked = { whiteSpace: 'normal' };

/**
 * The columns a box entry shares with the subscriber's statement, with
 * their widths: the transaction as it was recorded. Related details share a
 * column, one per line, so every detail fits on the screen without
 * scrolling sideways: the voucher over its date, the reference over its
 * banks, the type over its description, the amount in shekels over what
 * was paid in another currency and its rate, and the payment method over
 * who recorded it. Each table of entries puts its own number, box,
 * subscriber and state around them.
 */
export const ledgerColumns = {
    before: [['السند والتاريخ', 108], ['المرجع والبنك', 150]],
    after: [['البيان ونوع الحركة', 150], ['المبلغ والعملة', 105], ['طريقة الدفع والمستخدم', 110]],
};

/** The classes of a table of box entries: fixed column widths and tighter cells. */
export const ledgerTableClass = 'financial-ledger-table w-full text-start text-sm';

/**
 * The first cell of a box's entries: the box's number, with the entry's
 * place in the box under it.
 */
export function BoxNumberCell({ number, position }) {
    return <td style={stacked}>
        <bdi dir="ltr" className="block font-display font-semibold text-gray-900">{number ?? '—'}</bdi>
        <span className="mt-0.5 block font-display text-xs text-gray-400">#{position}</span>
    </td>;
}

function Dash() {
    return <span className="text-gray-300">—</span>;
}

/**
 * The entry's transaction as the statement shows it, from the server's
 * `ledger`; an entry without one (a transaction since removed) shows what
 * the entry itself kept.
 */
export function ledgerOf(entry) {
    if (entry.ledger) return entry.ledger;
    const transaction = entry.transaction ?? {};

    return {
        voucherNumber: entry.voucherNumber ?? transaction.manual_voucher_number ?? transaction.voucher_number ?? null,
        referenceNumber: entry.referenceNumber ?? transaction.reference_number ?? null,
        bankName: entry.bankName ?? transaction.bank_name ?? null,
        senderBankName: entry.senderBank ?? transaction.sender_bank_name ?? null,
    };
}

/** The entry's voucher over its date, and its reference over its banks: the cells before its subscriber. */
export function LedgerLeadCells({ ledger }) {
    return <>
        <td style={stacked}>
            <span className="block font-display font-semibold text-gray-900">{ledger.voucherNumber ? <bdi dir="ltr">{ledger.voucherNumber}</bdi> : <Dash />}</span>
            {ledger.date && <span className="mt-0.5 block font-display text-xs text-gray-500" title="تاريخ الحركة"><bdi dir="ltr">{ledger.date.split(' ').map((part) => <span key={part} className="inline-block whitespace-nowrap pe-1">{part}</span>)}</bdi></span>}
            <LatePaymentDetails paidOn={ledger.paidOn} recordedAt={ledger.date} reason={ledger.lateReason} />
            <BankReceivedDate value={ledger.bankReceivedOn} />
        </td>
        <td className="text-xs leading-5 text-gray-700" style={stacked}>
            {ledger.referenceNumber ? <span className="block break-all font-display text-sm text-gray-900" title="الرقم المرجعي"><bdi dir="ltr">{ledger.referenceNumber}</bdi></span> : !ledger.bankName && <Dash />}
            {ledger.senderBankName && <span className="block">من: {ledger.senderBankName}</span>}
            {ledger.bankName && <span className="block">إلى: {ledger.bankName}</span>}
        </td>
    </>;
}

/** The entry's description and type, amount with its currency and rate, and method with who recorded it, the cells after its subscriber. */
export function LedgerTrailCells({ ledger, amount, currency, cancelled }) {
    return <>
        <td style={stacked}>
            {ledger.typeLabel && <span className="inline-flex items-center gap-1.5"><StatusPill tone={ledger.isCredit ? 'green' : 'red'} label={ledger.isCredit ? 'له' : 'عليه'} /><span className="font-medium text-gray-900">{ledger.typeLabel}</span></span>}
            <span className="mt-1 block text-xs leading-5 text-gray-600">{ledger.description ?? <Dash />}</span>
        </td>
        <td style={stacked}>
            <b className={`block font-display ${cancelled ? 'text-gray-400 line-through' : 'text-emerald-700 dark:text-emerald-400'}`}><bdi dir="ltr">{formatMoney(amount)}</bdi></b>
            <span className="mt-0.5 block text-xs text-gray-500">{currencyLabels[currency] ?? currency}</span>
            {ledger.isShekel === false && currency === 'ILS' && <span className="block text-xs text-gray-500">دُفعت <bdi dir="ltr" className="font-display">{formatMoney(ledger.paidAmount)}</bdi> {ledger.currencyLabel}</span>}
            {ledger.exchangeRate && <span className="block whitespace-nowrap text-xs text-gray-500">سعر الصرف <bdi dir="ltr" className="font-display">{ledger.exchangeRate}</bdi></span>}
        </td>
        <td className="text-gray-700" style={stacked}>
            <span className="block">{ledger.paymentMethodLabel ?? <Dash />}</span>
            <span className="mt-0.5 block text-xs text-gray-500" title="اسم المستخدم">{ledger.recordedByName ?? <Dash />}</span>
        </td>
    </>;
}
