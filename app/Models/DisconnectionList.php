<?php

namespace App\Models;

use App\Enums\DisconnectionEventType;
use App\Enums\DisconnectionItemStatus;
use App\Enums\DisconnectionReason;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\DisconnectionListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A branch's list of subscriptions to disconnect, managed by hand: staff add
 * and remove subscriptions, set and extend their dates, warn them, and
 * confirm each disconnection once it has really been carried out. A branch
 * may keep several lists at once (one per campaign, say); a subscription is
 * on one of them at most while it waits for its disconnection.
 */
#[Fillable(['branch_id', 'title', 'notes', 'created_by', 'closed_at', 'closed_by'])]
class DisconnectionList extends Model
{
    /** @use HasFactory<DisconnectionListFactory> */
    use BelongsToBranch, HasFactory;

    /** Why a subscription could not be added, keyed by its code. */
    public const SKIP_REASONS = [
        'other_branch' => 'من فرع آخر',
        'not_active' => 'خدمته غير فعّالة (مفصول أو قيد الانتظار)',
        'already_listed' => 'مدرج في قائمة قطع أخرى بانتظار القطع',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DisconnectionListItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Put each of the subscriptions on the list with the same reason, note
     * and date, each on its own so one that cannot be added never stops the
     * others. Only the list's branch's active subscriptions that are not
     * already waiting on a list are added; what they owe does not matter.
     *
     * @param  iterable<int, Subscription>  $subscriptions
     * @return array{added: array<int, int>, skipped: array<int, array{subscriptionId: int, name: string, accountNumber: ?string, reason: string}>}
     */
    public function addSubscriptions(iterable $subscriptions, DisconnectionReason $reason, ?string $note, Carbon $scheduledFor, User $actor): array
    {
        $added = [];
        $skipped = [];

        foreach ($subscriptions as $subscription) {
            $why = $this->addSubscription($subscription, $reason, $note, $scheduledFor, $actor);

            if ($why === null) {
                $added[] = $subscription->id;
            } else {
                $skipped[] = [
                    'subscriptionId' => $subscription->id,
                    'name' => $subscription->displayName(),
                    'accountNumber' => $subscription->account_number,
                    'reason' => self::SKIP_REASONS[$why],
                ];
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Add one subscription, returning why it could not be added (a key of
     * SKIP_REASONS), or null once it is on the list.
     */
    private function addSubscription(Subscription $subscription, DisconnectionReason $reason, ?string $note, Carbon $scheduledFor, User $actor): ?string
    {
        if ($subscription->branch_id !== $this->branch_id) {
            return 'other_branch';
        }

        try {
            return DB::transaction(function () use ($subscription, $reason, $note, $scheduledFor, $actor): ?string {
                $current = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

                if ($current->status !== SubscriptionStatus::Active) {
                    return 'not_active';
                }

                if (DisconnectionListItem::query()->where('active_subscription_id', $current->id)->exists()) {
                    return 'already_listed';
                }

                $item = $this->items()->create([
                    'subscription_id' => $current->id,
                    'branch_id' => $current->branch_id,
                    'active_subscription_id' => $current->id,
                    'reason' => $reason,
                    'note' => filled($note) ? trim($note) : null,
                    'scheduled_for' => $scheduledFor->toDateString(),
                    'status' => DisconnectionItemStatus::Scheduled,
                    'balance_when_added' => number_format($current->balance(), 2, '.', ''),
                    'added_by' => $actor->id,
                ]);

                $item->recordEvent(DisconnectionEventType::Added, $actor, null, [
                    'reason' => $reason->value,
                    'note' => $item->note,
                    'scheduled_for' => $item->scheduled_for->toDateString(),
                    'balance' => $item->balance_when_added,
                ]);

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Added to another list at the same moment by someone else.
            return 'already_listed';
        }
    }
}
