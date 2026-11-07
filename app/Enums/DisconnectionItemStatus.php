<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a subscription on a disconnection list stands. A scheduled one whose
 * date has passed is shown as overdue, awaiting a manual action: the date
 * never disconnects anyone by itself.
 */
enum DisconnectionItemStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Disconnected = 'disconnected';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled for Disconnection',
            self::Disconnected => 'Disconnected',
            self::Removed => 'Removed from the List',
        };
    }
}
