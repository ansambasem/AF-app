<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CashTransferMethod: string
{
    use HasOptions;

    case HandDelivery = 'hand_delivery';
    case BankDeposit = 'bank_deposit';

    public function label(): string
    {
        return match ($this) {
            self::HandDelivery => 'Handed to the treasury',
            self::BankDeposit => 'Deposited in the company account',
        };
    }
}
