<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MeterReadingStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Approved => 'Approved',
        };
    }
}
