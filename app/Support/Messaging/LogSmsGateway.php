<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Writes each message to the application log instead of sending it — the
 * default until an SMS provider is set up.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $body): void
    {
        Log::info('SMS (not sent: no SMS gateway set up)', ['phone' => $phone, 'body' => $body]);
    }

    public function deliversMessages(): bool
    {
        return false;
    }
}
