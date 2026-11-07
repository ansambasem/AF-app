<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A charge (تحميل) recorded by hand on a subscription's account. Weekly
 * readings are charged on their own. A clearing
 * (مقاصة) is in the subscription's favour, so it is not a charge.
 */
enum ChargeType: string
{
    use HasOptions;

    case Penalty = 'penalty';
    case DisconnectionFee = 'disconnection_fee';
    case SubscriptionFee = 'subscription_fee';
    case MinimumCharge = 'minimum_charge';

    public function label(): string
    {
        return match ($this) {
            self::Penalty => 'Financial Penalty',
            self::DisconnectionFee => 'Service Disconnection Fee',
            self::SubscriptionFee => 'Subscription fee',
            self::MinimumCharge => 'Charge Subscriber Minimum',
        };
    }

    /**
     * What a charge of this type usually comes to, suggested in the form,
     * or null when it varies.
     */
    public function usualAmount(): ?float
    {
        $amount = config('powercollect.usual_charges.'.$this->value);

        return $amount === null ? null : (float) $amount;
    }

    /**
     * Whether the charge must say why (it shows on the statement): a
     * penalty always does.
     */
    public function needsReason(): bool
    {
        return $this === self::Penalty;
    }

    /**
     * Every type as the charge form offers it.
     *
     * @return array<int, array{value: string, label: string, usualAmount: ?float, needsReason: bool}>
     */
    public static function formOptions(): array
    {
        return array_map(fn (self $type): array => [
            'value' => $type->value,
            'label' => __($type->label()),
            'usualAmount' => $type->usualAmount(),
            'needsReason' => $type->needsReason(),
        ], self::cases());
    }
}
