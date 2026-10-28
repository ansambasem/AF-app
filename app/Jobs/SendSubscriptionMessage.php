<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\SubscriptionMessage;
use App\Support\Messaging\SmsDeliveryFailed;
use App\Support\Messaging\SmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one SMS to a subscription through the SMS gateway, and records
 * whether it went out. A message that was already sent is left alone, so
 * sending a batch again only retries what's still waiting or failed. When
 * the gateway can't be reached or is busy, the message stays waiting and is
 * tried again later, and is marked failed only once its tries run out.
 */
class SendSubscriptionMessage implements ShouldQueue
{
    use Queueable;

    /**
     * How many times the message is tried in all.
     */
    public int $tries = 3;

    /**
     * The seconds to wait before each try after the first.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public SubscriptionMessage $message) {}

    public function handle(SmsGateway $gateway): void
    {
        $message = $this->message->fresh();

        if ($message === null || $message->status === MessageStatus::Sent) {
            return;
        }

        try {
            $gateway->send($message->phone, $message->body);
        } catch (SmsDeliveryFailed $exception) {
            if ($this->canTryAgain($exception)) {
                $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));

                return;
            }

            $message->markFailed($exception->getMessage());

            return;
        }

        $message->markSent();
    }

    /**
     * A temporary failure is tried again while tries are left. A message sent
     * right away (the sync queue) can't wait for a later try.
     */
    private function canTryAgain(SmsDeliveryFailed $exception): bool
    {
        return $exception->isTemporary
            && $this->job !== null
            && $this->job->getConnectionName() !== 'sync'
            && $this->attempts() < $this->tries;
    }
}
