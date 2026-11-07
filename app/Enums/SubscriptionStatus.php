<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SubscriptionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Suspended = 'suspended';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Disconnected => 'Disconnected',
        };
    }
}
