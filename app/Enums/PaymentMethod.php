<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::Cheque => 'Cheque',
            self::EWallet => 'E-Wallet',
        };
    }

    /**
     * The ways a payment is recorded now: cash, or a transfer to one of the
     * banks and e-wallets in `powercollect.transfer_banks`. Cheques and
     * other e-wallets remain only on payments recorded before.
     *
     * @return array<int, self>
     */
    public static function offered(): array
    {
        return [self::Cash, self::BankTransfer];
    }

    /**
     * The permission that lets a user take a payment this way: cash, or
     * through a bank or e-wallet.
     */
    public function permission(): PermissionKey
    {
        return $this === self::Cash ? PermissionKey::RecordCashPayments : PermissionKey::RecordTransferPayments;
    }

    /**
     * Whether a payment this way goes through a bank, so the bank and the
     * transfer or cheque number are recorded with it.
     */
    public function throughBank(): bool
    {
        return in_array($this, [self::BankTransfer, self::Cheque], true);
    }
}
