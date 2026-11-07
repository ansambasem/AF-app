<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReadingEntryMode: string
{
    use HasOptions;

    case Automatic = 'automatic';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic (by schedule)',
            self::Open => 'Open now',
            self::Closed => 'Closed now',
        };
    }
}
