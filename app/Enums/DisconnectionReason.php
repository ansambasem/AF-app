<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a subscription was put on a disconnection list. The same codes are
 * used in the lists, their filters, history and reports.
 */
enum DisconnectionReason: string
{
    use HasOptions;

    case LatePayment = 'late_payment';
    case AccumulatedDebt = 'accumulated_debt';
    case Violation = 'violation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LatePayment => 'Late Payment',
            self::AccumulatedDebt => 'Accumulated Debt',
            self::Violation => 'Violation',
            self::Other => 'Other',
        };
    }
}
