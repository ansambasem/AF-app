<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the people who approve readings that an approved reading was
 * corrected and went back for approval. Shown under the bell like the
 * user's own actions, so it uses the same `action`/`subject` shape.
 */
class ReadingNeedsReapproval extends Notification
{
    use Queueable;

    public function __construct(public string $subscriptionName, public string $editorName) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{action: string, subject: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'action' => 'meter-reading-needs-reapproval',
            'subject' => "{$this->subscriptionName} — عدّلها {$this->editorName}",
        ];
    }
}
