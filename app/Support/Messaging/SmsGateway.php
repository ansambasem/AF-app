<?php

namespace App\Support\Messaging;

/**
 * Sends a text message to a phone. Which gateway is used is set in the
 * dashboard's SMS settings, else by `services.sms.driver` (see
 * AppServiceProvider).
 */
interface SmsGateway
{
    /**
     * Send the text to the phone number (as the subscription's record has it).
     *
     * @throws SmsDeliveryFailed when the gateway doesn't accept it
     */
    public function send(string $phone, string $body): void;

    /**
     * Whether messages really leave the app, rather than only being
     * written to the log.
     */
    public function deliversMessages(): bool;
}
