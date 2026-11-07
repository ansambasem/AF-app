<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MessageStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to Send',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }
}
