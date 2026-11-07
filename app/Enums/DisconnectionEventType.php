<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * One step in the history of a subscription on a disconnection list.
 */
enum DisconnectionEventType: string
{
    use HasOptions;

    case Added = 'added';
    case Extended = 'extended';
    case Warned = 'warned';
    case Removed = 'removed';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'Added to the List',
            self::Extended => 'Disconnection Date Changed',
            self::Warned => 'Warning Sent',
            self::Removed => 'Removed from the List',
            self::Disconnected => 'Disconnection Confirmed',
        };
    }
}
