<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccountingType: string
{
    use HasOptions;

    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }
}
