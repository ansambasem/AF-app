<?php

namespace App\Http\Controllers;

use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Requests\PrintSubscriptionStatementRequest;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubscriptionStatementController extends Controller
{
    use BuildsSubscriptionStatement;

    public function preview(PrintSubscriptionStatementRequest $request): InertiaResponse
    {
        $query = Subscription::query()->readableTo($request->user());
        $subscription = $request->filled('subscription')
            ? $query->findOrFail($request->integer('subscription'))
            : $query->latest('id')->first();
        if ($subscription) {
            $this->authorize('view', $subscription);
        }

        return Inertia::render('Subscriptions/StatementPrint', [
            ...($subscription ? $this->subscriptionStatement($request->user(), $subscription) : ['subscription' => null, 'entries' => []]),
            'printOptions' => [
                'view' => $request->validated('view', 'full'),
                'filters' => collect(['search', 'type', 'method', 'dateFrom', 'dateTo', 'sort'])
                    ->mapWithKeys(fn (string $key): array => [$key => $request->validated('filter.'.$key) ?? ''])->all(),
                'openHistories' => array_map(intval(...), $request->validated('history', [])),
            ],
        ]);
    }

    /**
     * The subscription's account statement: every charge, payment and
     * discount, oldest first, each with the balance it left. Opened from
     * a cash box's entry (`transaction`), it points out that line.
     */
    public function show(Request $request, Subscription $subscription): InertiaResponse
    {
        $this->authorize('view', $subscription);

        return Inertia::render('Subscriptions/Statement', [
            ...$this->subscriptionStatement($request->user(), $subscription),
            'focusLineId' => $request->integer('transaction') ?: null,
        ]);
    }
}
