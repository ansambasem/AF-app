<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use App\Models\SubscriptionTransaction;

/**
 * Why a line of a subscription's account was corrected (cancelled and
 * entered again) or deleted (cancelled only).
 */
enum CorrectionReason: string
{
    use HasOptions;

    case WrongAmount = 'wrong_amount';
    case WrongPaymentMethod = 'wrong_payment_method';
    case WrongCurrency = 'wrong_currency';
    case WrongType = 'wrong_type';
    case WrongDetails = 'wrong_details';
    case WrongSubscription = 'wrong_subscription';
    case Duplicate = 'duplicate';
    case NotReceived = 'not_received';
    case PaymentRefunded = 'payment_refunded';
    case WrongReading = 'wrong_reading';
    case FeeCancelled = 'fee_cancelled';
    case Other = 'other';

    /** Its weekly reading was corrected, so it goes back for approval. */
    case ReadingCorrected = 'reading_corrected';

    /** The standing discount on its weekly reading was given, changed or stopped. */
    case StandingDiscountChanged = 'standing_discount_changed';

    public function label(): string
    {
        return match ($this) {
            self::WrongAmount => 'Wrong amount',
            self::WrongPaymentMethod => 'Wrong payment method',
            self::WrongCurrency => 'Wrong currency or exchange rate',
            self::WrongType => 'Wrong type',
            self::WrongDetails => 'Wrong details',
            self::WrongSubscription => 'Recorded on the wrong subscription',
            self::Duplicate => 'Duplicate entry',
            self::NotReceived => 'Money not received',
            self::PaymentRefunded => 'Payment refunded to the subscription',
            self::WrongReading => 'Reading entered wrongly',
            self::FeeCancelled => 'Fee cancelled',
            self::Other => 'Other reason',
            self::ReadingCorrected => 'Reading corrected',
            self::StandingDiscountChanged => 'Standing discount changed',
        };
    }

    /**
     * The reasons offered when correcting the given line.
     *
     * @return array<int, self>
     */
    public static function forCorrectionOf(SubscriptionTransaction $line): array
    {
        return $line->isPayment() ? self::forPaymentCorrection() : self::forAdjustmentCorrection();
    }

    /**
     * The reasons offered when correcting a payment.
     *
     * @return array<int, self>
     */
    public static function forPaymentCorrection(): array
    {
        return [self::WrongAmount, self::WrongPaymentMethod, self::WrongCurrency, self::WrongDetails, self::Other];
    }

    /**
     * The reasons offered when correcting a charge or a discount.
     *
     * @return array<int, self>
     */
    public static function forAdjustmentCorrection(): array
    {
        return [self::WrongAmount, self::WrongType, self::WrongDetails, self::Other];
    }

    /**
     * The reasons offered when deleting the given line.
     *
     * @return array<int, self>
     */
    public static function forDeletionOf(SubscriptionTransaction $line): array
    {
        return match (true) {
            $line->isPayment() => [self::WrongSubscription, self::Duplicate, self::NotReceived, self::PaymentRefunded, self::Other],
            in_array($line->type, [SubscriptionTransaction::TYPE_METER_READING, SubscriptionTransaction::TYPE_READING_DISCOUNT], true) => [self::WrongReading, self::WrongSubscription, self::Duplicate, self::Other],
            $line->isRegistrationFee() => [self::FeeCancelled, self::WrongAmount, self::WrongSubscription, self::Duplicate, self::Other],
            default => [self::WrongSubscription, self::Duplicate, self::Other],
        };
    }
}
