<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use App\Models\SubscriptionTransaction;

enum CashBoxType: string
{
    use HasOptions;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Clearing = 'clearing';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfers',
            self::Clearing => 'Clearings',
        };
    }

    public static function for(SubscriptionTransaction $transaction): self
    {
        return match (true) {
            $transaction->isClearing() => self::Clearing,
            $transaction->payment_method === PaymentMethod::Cash => self::Cash,
            default => self::BankTransfer,
        };
    }

    public function isCounted(): bool
    {
        return $this === self::Cash;
    }

    public function book(): string
    {
        return $this === self::Cash ? 'cash' : 'bank';
    }

    /** @return list<string> */
    public static function forBook(string $book): array
    {
        return match ($book) {
            'cash' => [self::Cash->value],
            'bank' => [self::BankTransfer->value, self::Clearing->value],
            default => array_column(self::cases(), 'value'),
        };
    }
}
