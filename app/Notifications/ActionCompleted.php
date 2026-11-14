<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A record of something the user just saved, listed under the bell in the
 * top bar. `action` is the same key the save flashes as its status (e.g.
 * `subscription-created`), so both are worded by the frontend's one list of
 * messages; `subject` names the record, when there is one.
 */
class ActionCompleted extends Notification
{
    use Queueable;

    public function __construct(public string $action, public ?string $subject = null) {}

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
     * @return array{action: string, subject: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'action' => $this->action,
            'subject' => $this->subject,
        ];
    }
}
