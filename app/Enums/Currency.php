<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The currencies a payment can be made in. Accounts are kept in shekels;
 * a payment in another currency is converted at the rate it was taken at.
 */
enum Currency: string
{
    use HasOptions;

    case Shekel = 'ILS';
    case Dinar = 'JOD';
    case Dollar = 'USD';
    case Euro = 'EUR';

    public function label(): string
    {
        return match ($this) {
            self::Shekel => 'Shekel',
            self::Dinar => 'Dinar',
            self::Dollar => 'Dollar',
            self::Euro => 'Euro',
        };
    }
}
