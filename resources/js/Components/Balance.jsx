import { BALANCE_CHIP_CLASSES, BALANCE_HINTS, BALANCE_TEXT_CLASSES, BALANCE_WORDS, balanceTone, isWordUnit, signedBalance } from '@/lib/balance';

/**
 * A subscription's balance, the same on every page: positive (owed, in the
 * company's favour) in red, negative (credit, in the subscription's favour)
 * with its minus sign in green, zero in grey. Hovering says what it means.
 * `colored={false}` keeps the sign but no colour, for running balances on a
 * statement; `chip` sets it on a soft background. On paper (printing, or a
 * page laid out for print) its word follows it — عليه, له or مسدّد — since
 * colour and hover are lost there; `paperWord={false}` leaves it out, and
 * `word` shows it on screen too (for a subscriber's current balance).
 */
export default function Balance({ value, colored = true, chip = false, unit = '₪', paperWord = true, word = false, className = '' }) {
    const tone = balanceTone(value);
    const colors = colored ? (chip ? `rounded-[10px] px-2.5 py-0.5 ${BALANCE_CHIP_CLASSES[tone]}` : BALANCE_TEXT_CLASSES[tone]) : '';

    const wordUnit = unit && isWordUnit(unit);

    // The isolate characters travel with the text, so a printout that copies the cell's text keeps the minus before the number.
    return (
        <>
            <span title={BALANCE_HINTS[tone]} className={`inline-block whitespace-nowrap font-display tabular-nums ${colors} ${className}`}>
                <bdi dir="ltr">
                    {'\u2066'}
                    {signedBalance(value)}
                    {unit && !wordUnit && ` ${unit}`}
                    {'\u2069'}
                </bdi>
                {wordUnit && ` ${unit}`}
            </span>
            {word ? <span className="ms-1.5 text-base font-bold"> {BALANCE_WORDS[tone]}</span> : paperWord && <span className="balance-paper-word"> {BALANCE_WORDS[tone]}</span>}
        </>
    );
}
