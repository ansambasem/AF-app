<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\DisconnectionEventType;
use App\Enums\DisconnectionItemStatus;
use App\Enums\DisconnectionReason;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\DailySeries;
use Database\Factories\DisconnectionListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A subscription on a disconnection list. Its date is a plan, never a
 * trigger: once it passes the item shows as overdue until someone acts. A
 * payment changes nothing on the list either; the balance shown is always
 * the current one. Only a confirmed disconnection changes the subscription:
 * its service becomes disconnected and the disconnection fee is charged,
 * once, in the same database transaction.
 */
#[Fillable([
    'disconnection_list_id', 'subscription_id', 'branch_id', 'active_subscription_id', 'reason', 'note', 'scheduled_for', 'status',
    'balance_when_added', 'added_by', 'extensions_count', 'warnings_count', 'last_warned_at', 'removed_at', 'removed_by', 'removal_reason',
    'disconnected_at', 'disconnected_by', 'fee_amount', 'fee_transaction_id',
])]
class DisconnectionListItem extends Model
{
    /** @use HasFactory<DisconnectionListItemFactory> */
    use BelongsToBranch, HasFactory;

    /** What a confirmation can come to for one item, and how it reads. */
    public const OUTCOMES = [
        'disconnected' => 'تم تأكيد القطع',
        'already_disconnected' => 'سبق تأكيد قطعه؛ لم يُحمَّل مرة أخرى',
        'removed' => 'أُزيل من القائمة',
        'service_not_active' => 'خدمته ليست فعّالة الآن؛ راجع حالته قبل التأكيد',
        'list_closed' => 'القائمة مغلقة',
    ];

    protected function casts(): array
    {
        return [
            'reason' => DisconnectionReason::class,
            'status' => DisconnectionItemStatus::class,
            'scheduled_for' => 'date',
            'balance_when_added' => 'decimal:2',
            'last_warned_at' => 'datetime',
            'removed_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'fee_amount' => 'decimal:2',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(DisconnectionList::class, 'disconnection_list_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function disconnectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disconnected_by');
    }

    public function feeTransaction(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTransaction::class, 'fee_transaction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisconnectionEvent::class);
    }

    public function isScheduled(): bool
    {
        return $this->status === DisconnectionItemStatus::Scheduled;
    }

    /**
     * Whether its date has passed while it still waits: it then awaits a
     * manual action — nothing happens to it by itself.
     */
    public function isOverdue(): bool
    {
        return $this->isScheduled() && $this->scheduled_for->lt(DailySeries::today());
    }

    /**
     * The scheduled items whose date has passed.
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), DisconnectionItemStatus::Scheduled)
            ->whereDate($query->qualifyColumn('scheduled_for'), '<', DailySeries::today()->toDateString());
    }

    /**
     * Add a step to the item's history.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function recordEvent(DisconnectionEventType $type, User $actor, ?array $oldValues, ?array $newValues, ?string $reason = null, ?int $messageId = null): DisconnectionEvent
    {
        return $this->events()->create([
            'type' => $type,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => filled($reason) ? trim($reason) : null,
            'subscription_message_id' => $messageId,
            'user_id' => $actor->id,
        ]);
    }

    /**
     * Move the planned date, as many times as needed. It sends nothing and
     * disconnects nothing. Returns false when the item no longer waits.
     */
    public function reschedule(Carbon $date, User $actor, ?string $reason): bool
    {
        return DB::transaction(function () use ($date, $actor, $reason): bool {
            $item = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! $item->isScheduled()) {
                return false;
            }

            $old = $item->scheduled_for->toDateString();

            if ($old === $date->toDateString()) {
                return true;
            }

            $item->update(['scheduled_for' => $date->toDateString(), 'extensions_count' => $item->extensions_count + 1]);
            $item->recordEvent(DisconnectionEventType::Extended, $actor, ['scheduled_for' => $old], ['scheduled_for' => $date->toDateString()], $reason);
            $this->setRawAttributes($item->getAttributes(), true);

            return true;
        });
    }

    /**
     * Take the item off the list. It stays in the list's history, marked
     * removed, and the subscription may be put on a list again. Returns
     * false when the item no longer waits.
     */
    public function remove(User $actor, ?string $reason): bool
    {
        return DB::transaction(function () use ($actor, $reason): bool {
            $item = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! $item->isScheduled()) {
                return false;
            }

            $item->update([
                'status' => DisconnectionItemStatus::Removed,
                'active_subscription_id' => null,
                'removed_at' => now(),
                'removed_by' => $actor->id,
                'removal_reason' => filled($reason) ? trim($reason) : null,
            ]);
            $item->recordEvent(DisconnectionEventType::Removed, $actor, ['status' => DisconnectionItemStatus::Scheduled->value], ['status' => DisconnectionItemStatus::Removed->value], $reason);
            $this->setRawAttributes($item->getAttributes(), true);

            return true;
        });
    }

    /**
     * The company's usual disconnection fee, in shekels: what a confirmation
     * charges unless the person confirming changes it.
     */
    public static function usualFee(): string
    {
        return number_format((float) config('powercollect.disconnection.fee'), 2, '.', '');
    }

    /**
     * Record that the subscription has really been disconnected: its service
     * becomes disconnected and `$fee`, chosen when confirming, is charged as
     * a "Service Disconnection Fee" line, both or neither (a fee of 0
     * charges nothing). The item and the subscription are locked first, so a
     * second request — from the same person or another — finds it done and
     * charges nothing.
     *
     * @return string one of the keys of OUTCOMES
     */
    public function confirmDisconnection(User $actor, string $fee): string
    {
        $fee = number_format((float) $fee, 2, '.', '');

        return DB::transaction(function () use ($actor, $fee): string {
            $item = self::query()->with('list')->lockForUpdate()->findOrFail($this->id);

            if ($item->status === DisconnectionItemStatus::Disconnected) {
                return 'already_disconnected';
            }

            if ($item->status === DisconnectionItemStatus::Removed) {
                return 'removed';
            }

            if ($item->list->isClosed()) {
                return 'list_closed';
            }

            $subscription = Subscription::query()->lockForUpdate()->findOrFail($item->subscription_id);

            if ($subscription->status !== SubscriptionStatus::Active) {
                return 'service_not_active';
            }

            $feeLine = (float) $fee > 0
                ? $subscription->transactions()->create([
                    'recorded_by' => $actor->id,
                    'type' => ChargeType::DisconnectionFee->value,
                    'source_key' => 'disconnection:'.$item->id,
                    'amount' => $fee,
                    'currency_amount' => $fee,
                    'notes' => 'قطع الخدمة — '.$item->list->title,
                ])
                : null;

            $subscription->update(['status' => SubscriptionStatus::Disconnected]);

            $item->update([
                'status' => DisconnectionItemStatus::Disconnected,
                'active_subscription_id' => null,
                'disconnected_at' => now(),
                'disconnected_by' => $actor->id,
                'fee_amount' => $fee,
                'fee_transaction_id' => $feeLine?->id,
            ]);
            $item->recordEvent(
                DisconnectionEventType::Disconnected,
                $actor,
                ['status' => DisconnectionItemStatus::Scheduled->value, 'service_status' => SubscriptionStatus::Active->value],
                ['status' => DisconnectionItemStatus::Disconnected->value, 'service_status' => SubscriptionStatus::Disconnected->value, 'fee' => $fee, 'fee_transaction_id' => $feeLine?->id],
            );
            $this->setRawAttributes($item->getAttributes(), true);

            return 'disconnected';
        });
    }
}
