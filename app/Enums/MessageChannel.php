<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a message reaches the subscription.
 */
enum MessageChannel: string
{
    use HasOptions;

    /** Sent by the app through the SMS gateway set up in config/services.php. */
    case Sms = 'sms';

    /** Opened in WhatsApp, already written, for the staff member to send one by one. */
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
