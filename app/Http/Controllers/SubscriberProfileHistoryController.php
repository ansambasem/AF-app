<?php

namespace App\Http\Controllers;

use App\Models\SubscriberProfileChange;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

/**
 * The history of a person's personal details, shown from any one of their
 * subscriptions: who changed what, when, and from which branch.
 */
class SubscriberProfileHistoryController extends Controller
{
    /** The most changes shown; the newest come first. */
    private const LIMIT = 50;

    public function show(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        $changes = $subscription->profile->changes()->with(['user:id,name', 'branch:id,name'])->limit(self::LIMIT)->get();

        return response()->json([
            'data' => $changes->map(fn (SubscriberProfileChange $change): array => [
                'id' => $change->id,
                'at' => $change->created_at->timezone(config('app.business_timezone'))->format('d/m/Y H:i'),
                'userName' => $change->user?->name,
                'branchName' => $change->branch?->name,
                'changes' => collect($change->changes)->map(fn (array $values, string $field): array => [
                    'field' => $field,
                    'label' => SubscriberProfileChange::LABELS[$field] ?? $field,
                    'from' => $values['from'],
                    'to' => $values['to'],
                ])->values()->all(),
            ])->values(),
        ]);
    }
}
