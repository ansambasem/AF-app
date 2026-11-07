<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use Database\Factories\MessageBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One send to a group of subscriptions, with a message for each of them.
 * `branch_id` is empty when a Super Admin wrote to several branches at once.
 */
#[Fillable(['branch_id', 'kind', 'channel', 'body', 'week_start', 'created_by', 'operation_id'])]
class MessageBatch extends Model
{
    /** @use HasFactory<MessageBatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => MessageKind::class,
            'channel' => MessageChannel::class,
            'week_start' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SubscriptionMessage::class);
    }

    /**
     * Only the sends the given user may see: all of them for a Super Admin,
     * otherwise those to their own branch.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $user->workBranchId()));
    }

    /**
     * Count the messages in each status, as `pending_count`, `sent_count`
     * and `failed_count`, plus `messages_count` for them all.
     */
    #[Scope]
    protected function withStatusCounts(Builder $query): void
    {
        $query->withCount([
            'messages',
            'messages as pending_count' => fn (Builder $messages) => $messages->where('status', MessageStatus::Pending),
            'messages as sent_count' => fn (Builder $messages) => $messages->where('status', MessageStatus::Sent),
            'messages as failed_count' => fn (Builder $messages) => $messages->where('status', MessageStatus::Failed),
        ]);
    }
}
