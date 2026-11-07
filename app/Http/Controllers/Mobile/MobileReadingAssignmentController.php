<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Enums\ReadingSubmissionStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Models\ReadingSubmission;
use App\Models\Subscription;
use App\Models\User;
use App\Support\DailySeries;
use App\Support\FieldReadingAuthorization;
use App\Support\FieldReadingSync;
use App\Support\RecurringReadingDistribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The field reader's assignments for the app to keep offline, and the
 * readings it uploads for them. Assignments can be downloaded ahead of the
 * reading day and viewed, but readings are entered on the reading day only
 * (`entry_open`); each comes with a signed work authorisation that its
 * readings carry back.
 */
class MobileReadingAssignmentController extends Controller
{
    /** The most readings one upload may carry. */
    public const MAX_READINGS = 200;

    public function __construct(private FieldReadingSync $sync) {}

    /**
     * The reader's approved assignments of open cycles whose readings can
     * still be uploaded, with each box's active subscriptions. Downloading
     * marks each one downloaded at its version.
     */
    public function index(Request $request, RecurringReadingDistribution $distribution): JsonResponse
    {
        $reader = $request->user();
        abort_unless($reader->hasPermission(PermissionKey::RecordMeterReadings), 403);

        $today = DailySeries::today();
        $branch = Branch::query()->find($reader->workBranchId());
        if ($branch !== null) {
            $distribution->prepare($branch);
        }
        $leadDays = ReadingEntrySetting::forBranch($reader->workBranchId())->assignment_lead_days ?? 1;
        $assignments = ReadingAssignment::query()
            ->forMobileReader($reader)
            ->whereHas('cycle', fn (Builder $cycles) => $cycles->whereDate('reading_date', '>=', $today->copy()->subDays(ReadingCycle::SYNC_GRACE_DAYS)->toDateString()))
            ->with(['cycle', 'meterBox.subArea'])
            ->orderBy('reading_cycle_id')
            ->get()
            ->filter(fn (ReadingAssignment $assignment): bool => $today->toDateString() >= $assignment->cycle->reading_date->copy()
                ->subDays($leadDays)->toDateString())
            ->values();

        DB::transaction(function () use ($assignments, $reader): void {
            foreach ($assignments as $assignment) {
                if (! $assignment->isDownloaded()) {
                    $assignment->update(['downloaded_at' => now(), 'downloaded_version' => $assignment->version]);
                    $assignment->recordEvent('downloaded', $reader, $assignment->channel, $assignment->user_id);
                }
            }
        });

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'business_date' => $today->toDateString(),
            'next_reading' => $branch === null ? null : $this->nextReading($reader, $branch, $distribution),
            'assignments' => $assignments->map(fn (ReadingAssignment $assignment) => $this->assignmentData($assignment, $today->toDateString()))->values(),
        ]);
    }

    /**
     * Upload readings taken offline. Each is answered on its own — accepted,
     * held for review or refused — and an upload retried with the same
     * operation ids is answered the same way without saving anything twice.
     */
    public function sync(Request $request): JsonResponse
    {
        $reader = $request->user();
        abort_unless($reader->hasPermission(PermissionKey::RecordMeterReadings), 403);

        $validated = $request->validate([
            'readings' => ['required', 'array', 'min:1', 'max:'.self::MAX_READINGS],
            'readings.*.operation_id' => ['required', 'uuid', 'distinct'],
            'readings.*.assignment_id' => ['required', 'integer'],
            'readings.*.authorization' => ['required', 'string', 'max:1000'],
            'readings.*.subscription_id' => ['required', 'integer'],
            'readings.*.current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'readings.*.captured_at' => ['required', 'date'],
            'readings.*.device_id' => ['nullable', 'string', 'max:100'],
            'readings.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'results' => array_map(fn (array $reading): array => $this->sync->submit($reader, $reading), $validated['readings']),
        ]);
    }

    /**
     * Preview the effective upcoming distribution without issuing work authorisations
     * or marking boxes downloaded before the configured download window.
     *
     * @return array<string, mixed>
     */
    private function nextReading(User $reader, Branch $branch, RecurringReadingDistribution $distribution): array
    {
        $upcoming = $distribution->upcoming($branch);
        $overrides = $upcoming['cycle']?->assignments()->get()->keyBy('meter_box_id') ?? collect();
        $boxIds = ReadingDistribution::query()->where('branch_id', $branch->id)
            ->where('channel', ReadingAssignmentChannel::Mobile)->where('user_id', $reader->id)
            ->whereNotIn('meter_box_id', $overrides->keys())->pluck('meter_box_id')
            ->merge($overrides->filter(fn (ReadingAssignment $assignment): bool => $assignment->status === ReadingAssignmentStatus::Approved
                && $assignment->channel === ReadingAssignmentChannel::Mobile && $assignment->user_id === $reader->id)->keys());
        $active = fn (Builder $subscriptions) => $subscriptions->where('branch_id', $branch->id)
            ->where('status', SubscriptionStatus::Active)->whereNull('archived_at');
        $boxes = MeterBox::query()->where('branch_id', $branch->id)->whereIn('id', $boxIds)
            ->whereHas('subscriptions', $active)->withCount(['subscriptions as subscribers' => $active])
            ->with(['subArea', 'subscriptions' => fn (HasMany $subscriptions): Builder => $active($subscriptions->getQuery())->orderBy('account_number')])
            ->orderBy('name')->orderBy('name_suffix')->orderBy('box_number')->get();

        return [
            'reading_date' => $upcoming['readingDate']->toDateString(),
            'boxes' => $boxes->map(fn (MeterBox $box): array => [
                'id' => $box->id, 'name' => $box->displayName(), 'number' => $box->box_number,
                'location' => $box->location, 'sub_area_id' => $box->sub_area_id,
                'sub_area_name' => $box->subArea?->name, 'subscribers' => (int) $box->subscribers,
                'subscriptions' => $box->subscriptions->map(fn (Subscription $subscription): array => [
                    'id' => $subscription->id, 'name' => $subscription->displayName(),
                    'account_number' => $subscription->account_number, 'phone' => $subscription->contactPhone(),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentData(ReadingAssignment $assignment, string $today): array
    {
        $cycle = $assignment->cycle;
        $weekStart = $cycle->week_start;
        $subscriptions = Subscription::query()
            ->where('branch_id', $assignment->branch_id)
            ->where('meter_box_id', $assignment->meter_box_id)
            ->where('status', SubscriptionStatus::Active)
            ->whereNull('archived_at')
            ->with(['meterReadings' => fn ($query) => $query->whereDate('week_start', '<=', $weekStart->toDateString())->orderByDesc('week_start')->limit(4)])
            ->orderBy('account_number')
            ->get();
        $held = ReadingSubmission::query()->where('reading_assignment_id', $assignment->id)->where('user_id', $assignment->user_id)
            ->where('status', ReadingSubmissionStatus::NeedsReview)->latest('id')->get()->unique('subscription_id')->keyBy('subscription_id');

        return [
            'id' => $assignment->id,
            'version' => $assignment->version,
            'authorization' => FieldReadingAuthorization::issue($assignment),
            'cycle' => [
                'id' => $cycle->id,
                'week_start' => $weekStart->toDateString(),
                'week_end' => $cycle->week_end->toDateString(),
                'reading_date' => $cycle->reading_date->toDateString(),
                'entry_open' => $cycle->reading_date->toDateString() === $today,
                'entry_starts_at' => $cycle->reading_date->copy()->shiftTimezone(config('app.business_timezone'))->startOfDay()->toIso8601String(),
                'entry_ends_at' => $cycle->reading_date->copy()->shiftTimezone(config('app.business_timezone'))->endOfDay()->toIso8601String(),
                'sync_deadline' => $cycle->syncDeadline()->toIso8601String(),
            ],
            'meter_box' => [
                'id' => $assignment->meterBox->id,
                'number' => $assignment->meterBox->box_number,
                'name' => $assignment->meterBox->displayName(),
                'location' => $assignment->meterBox->location,
                'sub_area_id' => $assignment->meterBox->sub_area_id,
                'sub_area_name' => $assignment->meterBox->subArea?->name,
            ],
            'subscriptions' => $subscriptions->map(function (Subscription $subscription) use ($weekStart, $held): array {
                $current = $subscription->meterReadings->first(fn (MeterReading $reading): bool => $reading->week_start->equalTo($weekStart));
                $previous = $subscription->meterReadings->first(fn (MeterReading $reading): bool => $reading->week_start->lt($weekStart));
                $heldReading = $held->get($subscription->id);

                return [
                    'id' => $subscription->id,
                    'name' => $subscription->displayName(),
                    'account_number' => $subscription->account_number,
                    'previous_reading' => $current?->previous_reading ?? $previous?->nextPreviousReading() ?? $subscription->initial_reading,
                    'already_read' => $current !== null || $heldReading !== null,
                    'current_reading' => $current?->requires_reentry ? null : ($current?->current_reading ?? $heldReading?->current_reading),
                    'reading_status' => $current?->status?->value ?? ($heldReading === null ? null : ReadingSubmissionStatus::NeedsReview->value),
                    'recent_readings' => $subscription->meterReadings->filter(fn (MeterReading $reading): bool => $reading->week_start->lt($weekStart))->take(3)
                        ->map(fn (MeterReading $reading): array => ['week_start' => $reading->week_start->toDateString(), 'current_reading' => $reading->current_reading, 'consumption' => $reading->consumption])->values(),
                ];
            })->values(),
        ];
    }
}
