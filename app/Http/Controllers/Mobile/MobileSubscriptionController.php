<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->hasPermission(PermissionKey::RecordMeterReadings), 403);

        $weekStart = MeterReading::latestEndedWeekStart($actor->workBranchId());
        $week = $weekStart->toDateString();
        $subscriptions = Subscription::query()
            ->visibleTo($actor)
            ->whereIn('meter_box_id', ReadingAssignment::query()->forMobileReader($actor)
                ->whereHas('cycle', fn ($query) => $query->whereDate('week_start', $week))->select('meter_box_id'))
            ->where('status', SubscriptionStatus::Active)
            ->with([
                'meterBox:id,box_number,name,name_suffix,location,sub_area_id', 'meterBox.subArea',
                'latestMeterReading',
                'meterReadings' => fn ($query) => $query->whereDate('week_start', '<=', $week)->orderByDesc('week_start')->limit(4),
            ])
            ->orderBy('id')
            ->paginate(500);

        return response()->json([
            'week_start' => $week,
            'week_end' => MeterReading::weekEndFor($weekStart, $actor->workBranchId())->toDateString(),
            'can_record_readings_now' => $actor->can('create', [MeterReading::class, $weekStart]),
            'data' => $subscriptions->getCollection()->map(function (Subscription $subscription) use ($week): array {
                $currentWeekReading = $subscription->meterReadings->first(fn (MeterReading $reading): bool => $reading->week_start->toDateString() === $week);

                return [
                    'id' => $subscription->id,
                    'account_number' => $subscription->account_number,
                    'full_name' => $subscription->displayName(),
                    'meter_box_id' => $subscription->meter_box_id,
                    'meter_box_number' => $subscription->meterBox?->box_number,
                    'meter_box_name' => $subscription->meterBox?->displayName(),
                    'meter_box_location' => $subscription->meterBox?->location,
                    'sub_area_id' => $subscription->meterBox?->sub_area_id,
                    'sub_area_name' => $subscription->meterBox?->subArea?->name,
                    'previous_reading' => $currentWeekReading?->previous_reading
                        ?? $subscription->latestMeterReading?->nextPreviousReading()
                        ?? $subscription->initial_reading,
                    'current_reading' => $currentWeekReading?->requires_reentry ? null : $currentWeekReading?->current_reading,
                    'reading_status' => $currentWeekReading?->status?->value,
                    'recent_readings' => $subscription->meterReadings
                        ->reject(fn (MeterReading $reading): bool => $reading->week_start->toDateString() === $week)
                        ->take(3)
                        ->map(fn (MeterReading $reading): array => [
                            'week_start' => $reading->week_start->toDateString(),
                            'current_reading' => $reading->current_reading,
                            'consumption' => $reading->consumption,
                        ])
                        ->values()
                        ->all(),
                ];
            })->all(),
            'current_page' => $subscriptions->currentPage(),
            'last_page' => $subscriptions->lastPage(),
            'total' => $subscriptions->total(),
        ]);
    }
}
