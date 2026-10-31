<?php

namespace App\Http\Controllers;

use App\Models\MeterBox;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeterBoxSubscriptionController extends Controller
{
    public function index(Request $request, MeterBox $meterBox): JsonResponse|RedirectResponse
    {
        $this->authorize('viewAny', MeterBox::class);
        $this->authorize('viewAny', Subscription::class);
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin() || $meterBox->branch_id === $actor->workBranchId(), 404);

        // The page loads these rows itself; opened directly in the browser, the boxes page is the place to see them.
        if (! $request->expectsJson()) {
            return redirect()->route('meter-boxes.index');
        }

        $validated = $request->validate([
            'sort' => ['nullable', 'in:name,account_number'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $sort = $validated['sort'] ?? 'name';
        $direction = $validated['direction'] ?? 'asc';

        $subscriptions = $meterBox->subscriptions()->visibleTo($actor)
            ->orderedInMeterBox($sort, $direction)
            ->with('circuitBreaker:id,ampere', 'latestMeterReading')
            ->paginate(15)->withQueryString()
            ->through(fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'display_name' => $subscription->displayName(),
                'account_number' => $subscription->account_number,
                'phone' => $subscription->contactPhone(),
                'status' => $subscription->status->value,
                'statusLabel' => __($subscription->status->label()),
                'circuitBreakerAmpere' => $subscription->circuitBreaker?->ampere,
                'lastReading' => $subscription->latestMeterReading?->nextPreviousReading() ?? $subscription->initial_reading,
                'statementUrl' => route('subscriptions.statement', $subscription, absolute: false),
            ]);

        return response()->json($subscriptions);
    }
}
