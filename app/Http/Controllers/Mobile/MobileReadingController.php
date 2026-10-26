<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileMeterReadingRequest;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MobileReadingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MeterReading::class);

        $validated = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'subscription_status' => ['nullable', 'in:all,active,suspended,disconnected'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $branchId = $request->user()->branch_id;
        // Someone who may not browse earlier weeks always gets the latest one.
        $canBrowseWeeks = $request->user()->can('browseWeeks', MeterReading::class);
        $weekStart = isset($validated['week']) && $canBrowseWeeks
            ? MeterReading::weekStartFor(Carbon::parse($validated['week']), $branchId)
            : MeterReading::latestEndedWeekStart($branchId);
        $week = $weekStart->toDateString();
        $search = trim($validated['search'] ?? '');
        $subscriptions = Subscription::query()
            ->visibleTo($request->user())
            ->when(! $request->user()->hasPermission(PermissionKey::ViewMeterReadings), fn ($query) => $query->whereIn('meter_box_id',
                ReadingAssignment::query()->forMobileReader($request->user())->whereHas('cycle', fn ($cycles) => $cycles->whereDate('week_start', $week))->select('meter_box_id')))
            ->when(($validated['subscription_status'] ?? 'active') !== 'all', fn ($query) => $query->where('status', $validated['subscription_status'] ?? SubscriptionStatus::Active))
            ->with([
                'meterBox:id,box_number',
                'meterReadings' => fn ($query) => $query->visibleTo($request->user())
                    ->whereDate('week_start', '<=', $week)->orderByDesc('week_start')->limit(1),
            ])
            ->matchingSearch($search)
            ->orderBy('full_name')->orderBy('id')->paginate(25);

        return response()->json([
            'week_start' => $week,
            'week_end' => MeterReading::weekEndFor($weekStart, $branchId)->toDateString(),
            'week_options' => MeterReading::recentWeekOptions($branchId, $canBrowseWeeks ? 8 : 1),
            'data' => $subscriptions->getCollection()->map(function (Subscription $subscription) use ($week): array {
                $latest = $subscription->meterReadings->first();
                $reading = $latest?->week_start->toDateString() === $week ? $latest : null;

                return [
                    'id' => $subscription->id,
                    'full_name' => $subscription->displayName(),
                    'subscription_status' => $subscription->status->value,
                    'subscription_status_label' => __($subscription->status->label()),
                    'account_number' => $subscription->account_number,
                    'meter_box_number' => $subscription->meterBox?->box_number,
                    'previous_reading' => $reading?->previous_reading ?? $latest?->current_reading ?? $subscription->initial_reading,
                    'current_reading' => $reading?->current_reading,
                    'consumption' => $reading?->consumption,
                    'amount_due' => $reading?->amount_due,
                    'status' => $reading?->status->value,
                ];
            })->all(),
            'current_page' => $subscriptions->currentPage(),
            'last_page' => $subscriptions->lastPage(),
            'total' => $subscriptions->total(),
        ]);
    }

    public function store(StoreMobileMeterReadingRequest $request): JsonResponse
    {
        if ($existingReading = $request->existingReading()) {
            return $this->responseFor($existingReading);
        }

        $subscription = Subscription::with(['tariff', 'circuitBreaker', 'standingDiscount'])
            ->findOrFail($request->integer('subscription_id'));
        $reading = MeterReading::recordFor(
            $subscription,
            $request->weekStart(),
            $request->float('current_reading'),
            $request->user(),
            $request->input('notes'),
            $request->validated('mobile_operation_id'),
        );

        return $this->responseFor($reading);
    }

    private function responseFor(MeterReading $reading): JsonResponse
    {
        return response()->json([
            'id' => $reading->id,
            'mobile_operation_id' => $reading->mobile_operation_id,
            'status' => $reading->status->value,
            'current_reading' => $reading->current_reading,
            // Far above what the subscription usually uses: it waits for a reviewer to confirm it.
            'unusual_consumption' => $reading->isUnusual(),
        ], 201);
    }
}
