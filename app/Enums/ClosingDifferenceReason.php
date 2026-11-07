<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why the counted cash is not what the closing expects.
 */
enum ClosingDifferenceReason: string
{
    use HasOptions;

    case WrongChange = 'wrong_change';
    case DamagedCurrency = 'damaged_currency';
    case UnrecordedPayment = 'unrecorded_payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WrongChange => 'Wrong change given',
            self::DamagedCurrency => 'Damaged currency',
            self::UnrecordedPayment => 'Payment not recorded',
            self::Other => 'Other',
        };
    }
}
