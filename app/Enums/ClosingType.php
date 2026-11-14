<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ClosingType: string
{
    use HasOptions;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily Closing',
            self::Weekly => 'Weekly Closing',
            self::Monthly => 'Monthly Closing',
        };
    }
}
