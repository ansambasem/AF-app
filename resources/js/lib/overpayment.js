/**
 * How a payment compares with what the subscription owes. The server decides
 * (config/powercollect.php, 'payments'); this only tells the collector first,
 * so keep the two numbers in step with it.
 */
export const OVERPAYMENT_MULTIPLIER = 2;
export const OVERPAYMENT_CONFIRMATION_MINIMUM = 500;

/**
 * 'none' while the payment is within what is owed, 'above' once it leaves
 * the subscription in credit, and 'confirm' when it is so far above that it
 * is probably a slip (5000 for 50) and must be confirmed to be saved.
 */
export function overpaymentLevel(amount, owed) {
    const paid = Number(amount);

    if (!(paid > owed)) {
        return 'none';
    }

    return paid > owed * OVERPAYMENT_MULTIPLIER && paid - owed >= OVERPAYMENT_CONFIRMATION_MINIMUM ? 'confirm' : 'above';
}
