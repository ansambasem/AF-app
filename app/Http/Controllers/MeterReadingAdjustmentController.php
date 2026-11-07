<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApprovesCorrectedReadings;
use App\Http\Requests\AdjustLastReadingRequest;
use App\Http\Requests\PreviewLastReadingRequest;
use App\Http\Requests\UpdateReadingPricingRequest;
use App\Models\MeterReading;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Notifications\ReadingNeedsReapproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Changes to a reading after the week was entered: editing it as the last
 * reading (with its preview), adjusting the reading the next week starts
 * from (a correction or a new meter, with its preview), and its price and
 * minimum.
 */
class MeterReadingAdjustmentController extends Controller
{
    use ApprovesCorrectedReadings;

    public function previewAdjustment(AdjustLastReadingRequest $request, MeterReading $meterReading): JsonResponse
    {
        $newReading = $request->float('current_reading');
        $reset = $request->isMeterChange();
        $after = $reset ? [
            'consumption' => $meterReading->consumption, 'amount_due' => $meterReading->amount_due, 'unusual' => false,
        ] : $meterReading->recalculatedBetween($meterReading->previous_reading, $newReading);
        $next = $meterReading->subscription->meterReadings()->where('week_start', '>', $meterReading->week_start)->first();

        return response()->json([
            'reset' => $reset, 'before' => $meterReading->nextPreviousReading(), 'after' => $newReading,
            'weeks' => [$this->previewedWeek($meterReading, $meterReading->previous_reading, $reset ? $meterReading->current_reading : $newReading, $after)],
            'clearsCurrentReading' => $next !== null,
            'removedCurrentAmount' => $next !== null && ! $next->isPending() ? $next->amount_due : '0.00',
        ]);
    }

    public function adjustLastReading(AdjustLastReadingRequest $request, MeterReading $meterReading): RedirectResponse
    {
        $actor = $request->user();
        DB::transaction(function () use ($request, $meterReading, $actor): void {
            $meterReading->subscription()->lockForUpdate()->firstOrFail();
            $reading = MeterReading::query()->lockForUpdate()->findOrFail($meterReading->id);
            $this->authorize('adjustLastReading', $reading);
            if (round($reading->nextPreviousReading(), 2) !== round($request->float('expected_reading'), 2)) {
                throw ValidationException::withMessages(['current_reading' => 'تغيّرت القراءة؛ حدّث الصفحة وراجع القيمة الجديدة قبل التعديل.']);
            }

            $newReading = $request->float('current_reading');
            if (! $request->isMeterChange() && round($newReading, 2) === round($reading->nextPreviousReading(), 2)) {
                return;
            }

            if ($request->isMeterChange()) {
                $reading->recordAdjustment('baseline_reset', ['previousReading' => $reading->nextPreviousReading()], ['previousReading' => $newReading], $actor, $request->validated('reason'));
                $reading->update(['next_previous_reading' => $newReading]);
            } else {
                $wasApproved = $reading->correct($newReading, $reading->notes, $actor);
                $reading->update(['next_previous_reading' => null]);
                if ($wasApproved) {
                    $this->approveCorrectedLastReading($reading, $actor, $request->boolean('confirm_unusual'));
                }
            }

            $next = $reading->subscription->meterReadings()->where('week_start', '>', $reading->week_start)->lockForUpdate()->first();
            $next?->requireCurrentReadingAgain($newReading, $actor);
        });

        $actor->notify(new ActionCompleted('meter-reading-updated', $meterReading->subscription->displayName()));

        return back()->with('status', 'meter-reading-updated');
    }

    public function updatePricing(UpdateReadingPricingRequest $request, MeterReading $meterReading): RedirectResponse
    {
        $actor = $request->user();
        $reopened = DB::transaction(function () use ($request, $meterReading, $actor): bool {
            $meterReading->subscription()->lockForUpdate()->firstOrFail();
            $reading = MeterReading::query()->lockForUpdate()->findOrFail($meterReading->id);
            foreach (['unit_price' => 'updateUnitPrice', 'minimum_payment' => 'updateMinimumPayment'] as $field => $ability) {
                if ($request->has($field)) {
                    $this->authorize($ability, $reading);
                }
            }
            $before = $reading->only(['unit_price', 'minimum_payment']);
            $reading->fill($request->validated());
            if (! $reading->isDirty(['unit_price', 'minimum_payment'])) {
                return false;
            }
            $reopened = $reading->correct($reading->current_reading, $reading->notes, $actor);
            $reading->recordAdjustment('pricing', $before, $reading->only(['unit_price', 'minimum_payment']), $actor);

            return $reopened;
        });
        if ($reopened) {
            Notification::send($meterReading->approvers()->reject(fn (User $approver) => $approver->is($actor)), new ReadingNeedsReapproval($meterReading->subscription->displayName(), $actor->name));
        }

        return back()->with('status', $reopened ? 'meter-reading-reopened' : 'meter-reading-updated');
    }

    /**
     * What editing a last reading to `current_reading` would do, shown
     * before the edit is confirmed: for its week and, once entered, the week
     * after it that starts from it, the readings, consumption and amount due
     * before and after, whether the week is approved (and so approved again
     * at once on confirming) and whether the edit makes it unusual. The week
     * after it is worked out once its reading is typed again
     * (`next_current_reading`); until then its `after` is null.
     */
    public function previewLastReading(PreviewLastReadingRequest $request, MeterReading $meterReading): JsonResponse
    {
        $newReading = $request->float('current_reading');
        $nextReading = $request->nextReading();
        $nextCurrentReading = $request->nextCurrentReading();
        $corrected = $meterReading->recalculatedBetween($meterReading->previous_reading, $newReading);
        $weeks = [$this->previewedWeek($meterReading, $meterReading->previous_reading, $newReading, $corrected)];

        if ($nextReading !== null) {
            $weeks[] = $this->previewedWeek($nextReading, $newReading, $nextCurrentReading, $nextCurrentReading === null ? null : $nextReading->recalculatedBetween(
                $newReading,
                $nextCurrentReading,
                [$meterReading->id => $corrected['consumption']],
            ));
        }

        return response()->json(['weeks' => $weeks]);
    }

    /**
     * One week of the last reading's preview.
     *
     * @param  array{consumption: float, amount_due: string, unusual: bool}|null  $after  null until its reading is typed again
     * @return array{id: int, weekStart: string, weekEnd: ?string, isApproved: bool, unusual: bool, before: array<string, float|string>, after: array<string, float|string>|null}
     */
    private function previewedWeek(MeterReading $reading, float $previousReading, ?float $currentReading, ?array $after): array
    {
        return [
            'id' => $reading->id,
            'weekStart' => $reading->week_start->toDateString(),
            'weekEnd' => $reading->week_end?->toDateString(),
            'isApproved' => ! $reading->isPending(),
            'unusual' => $after['unusual'] ?? false,
            'before' => [
                'previousReading' => $reading->previous_reading,
                'currentReading' => $reading->requires_reentry ? null : $reading->current_reading,
                'consumption' => (float) $reading->consumption,
                'amountDue' => $reading->amount_due,
            ],
            'after' => $after === null ? null : [
                'previousReading' => $previousReading,
                'currentReading' => $currentReading,
                'consumption' => $after['consumption'],
                'amountDue' => $after['amount_due'],
            ],
        ];
    }
}
