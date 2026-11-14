<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CashTransferStatus: string
{
    use HasOptions;

    case InTransit = 'in_transit';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'In transit',
            self::Received => 'Received',
        };
    }
}
