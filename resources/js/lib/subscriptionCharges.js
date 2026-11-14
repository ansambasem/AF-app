/** Selecting minimum charge fills the configured amount; other charges retain typed amounts. */
export function amountForChargeType(current, type, subscription, chargeTypes) {
    if (type === 'minimum_charge') {
        return String(subscription.minimumPayment ?? '0');
    }

    const amount = current.type === 'minimum_charge' ? '' : current.amount;
    const usual = type === 'subscription_fee' ? subscription.subscriptionFee : chargeTypes.find((option) => option.value === type)?.usualAmount;

    return amount === '' && usual ? String(usual) : amount;
}
