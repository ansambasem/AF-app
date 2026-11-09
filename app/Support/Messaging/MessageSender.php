<?php

namespace App\Support\Messaging;

use App\Enums\MessageStatus;
use App\Models\MessageBatch;
use App\Models\SubscriptionMessage;
use Throwable;

/**
 * Sends a send's SMS right away, in the request that asked for them, one
 * subscription after another, instead of leaving them to the queue: a
 * message the gateway refuses (or that fails in any other way) is marked
 * failed with why, and the others still go out. Only messages not sent yet
 * are sent, so sending again never repeats a message.
 */
class MessageSender
{
    public function __construct(private SmsGateway $gateway) {}

    /**
     * Send the batch's messages still waiting, and those that failed when
     * `$includeFailed`, and say how it went.
     *
     * @return array{sent: int, failed: int, total: int, delivers: bool}
     */
    public function sendNow(MessageBatch $batch, bool $includeFailed = false): array
    {
        // A long list must not stop halfway because the page was closed or the default time limit ran out.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $statuses = $includeFailed ? [MessageStatus::Pending, MessageStatus::Failed] : [MessageStatus::Pending];
        $sent = 0;
        $failed = 0;

        $batch->messages()->whereIn('status', $statuses)->lazyById(200)->each(function (SubscriptionMessage $message) use (&$sent, &$failed): void {
            try {
                $this->gateway->send($message->phone, $message->body);
            } catch (SmsDeliveryFailed $exception) {
                $message->markFailed($exception->getMessage());
                $failed++;

                return;
            } catch (Throwable $exception) {
                report($exception);
                $message->markFailed('تعذّر الإرسال لخطأ غير متوقع.');
                $failed++;

                return;
            }

            $message->markSent();
            $sent++;
        });

        return ['sent' => $sent, 'failed' => $failed, 'total' => $sent + $failed, 'delivers' => $this->gateway->deliversMessages()];
    }

    /**
     * What the person who sent them is told: how many went out and how many
     * failed, and that nothing really left while no gateway is set up.
     *
     * @param  array{sent: int, failed: int, total: int, delivers: bool}  $result
     */
    public static function summary(array $result): string
    {
        if (! $result['delivers']) {
            return sprintf('لم تُضبط بوابة الرسائل بعد، فسُجّلت %d رسالة دون أن تصل للمشتركين. اضبطها من إعدادات الرسائل.', $result['total']);
        }

        return $result['failed'] === 0
            ? sprintf('أُرسلت الرسائل كلها: %d من %d.', $result['sent'], $result['total'])
            : sprintf('أُرسلت %d رسالة وفشلت %d من %d؛ تجد سبب كل فشل في صفحة الإرسال، ويمكنك إعادة إرسال ما فشل.', $result['sent'], $result['failed'], $result['total']);
    }
}
