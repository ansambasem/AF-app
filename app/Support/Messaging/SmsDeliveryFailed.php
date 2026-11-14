<?php

namespace App\Support\Messaging;

use RuntimeException;
use Throwable;

/**
 * The SMS gateway turned a message down, or couldn't be reached. A
 * temporary failure (no connection, the provider busy or down) may pass if
 * the message is tried again later; any other refusal won't.
 */
class SmsDeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $isTemporary = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
