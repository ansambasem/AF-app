<?php

namespace App\Http\Controllers;

use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionMessageController extends Controller
{
    public function index(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('viewAny', MessageBatch::class);
        $actor = $request->user();
        abort_unless(Subscription::query()->visibleTo($actor)->whereKey($subscription->id)->exists(), 404);
        if ($subscription->archived_at !== null) {
            $this->authorize('view', $subscription);
        }

        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(MessageKind::class)],
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $messages = SubscriptionMessage::query()->visibleTo($actor)
            ->where('subscription_id', $subscription->id)
            ->with(['batch.createdBy', 'sentBy'])
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('kind', $kind)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate(15)
            ->through(fn (SubscriptionMessage $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'phone' => $message->phone,
                'kind' => $message->batch?->kind->value,
                'kindLabel' => $message->batch ? __($message->batch->kind->label()) : 'غير محدد',
                'channel' => $message->batch?->channel->value,
                'channelLabel' => $message->batch ? __($message->batch->channel->label()) : 'غير محدد',
                'status' => $message->status->value,
                'statusLabel' => __($message->status->label()),
                'createdAt' => $message->created_at->toIso8601String(),
                'sentAt' => $message->sent_at?->toIso8601String(),
                'createdBy' => $message->batch?->createdBy?->name,
                'sentBy' => $message->sentBy?->name,
                'error' => $message->error,
            ]);

        return response()->json([
            'messages' => $messages,
            'kinds' => MessageKind::options(),
            'statuses' => MessageStatus::options(),
        ]);
    }
}
