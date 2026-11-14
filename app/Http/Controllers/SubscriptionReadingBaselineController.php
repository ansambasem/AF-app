<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustSubscriptionReadingBaselineRequest;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Notifications\ActionCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionReadingBaselineController extends Controller
{
    public function preview(AdjustSubscriptionReadingBaselineRequest $request, Subscription $subscription): JsonResponse
    {
        return response()->json($this->adjust($request, $subscription, false));
    }

    public function update(AdjustSubscriptionReadingBaselineRequest $request, Subscription $subscription): RedirectResponse
    {
        $preview = $this->adjust($request, $subscription, true);
        $request->user()->notify(new ActionCompleted('meter-reading-updated', $subscription->displayName().": {$preview['before']} → {$preview['after']}"));

        return back()->with('status', 'meter-reading-updated');
    }

    /** @return array{reset: bool, before: float, after: float, weeks: array{}, clearsCurrentReading: bool, removedCurrentAmount: string} */
    private function adjust(AdjustSubscriptionReadingBaselineRequest $request, Subscription $subscription, bool $save): array
    {
        return DB::transaction(function () use ($request, $subscription, $save): array {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $week = $request->date('week_start');
            $this->authorize('adjustInitialReading', [$locked, $week]);
            $readings = $locked->meterReadings()->lockForUpdate()->get();
            if ($readings->contains(fn (MeterReading $reading): bool => ! $reading->week_start->equalTo($week))) {
                throw ValidationException::withMessages(['current_reading' => 'توجد قراءات لأسابيع أخرى؛ لا يمكن تغيير قراءة بداية الاشتراك من هذا المسار.']);
            }
            $current = $readings->first();
            $before = (float) ($current?->previous_reading ?? $locked->initial_reading ?? 0);
            if (round($before, 2) !== round($request->float('expected_reading'), 2)) {
                throw ValidationException::withMessages(['current_reading' => 'تغيّرت القراءة؛ حدّث الصفحة وراجع القيمة الجديدة قبل التعديل.']);
            }
            $after = $request->float('current_reading');
            $changed = round($before, 2) !== round($after, 2);
            $preview = [
                'reset' => $request->input('adjustment_type') === 'meter_change',
                'before' => $before, 'after' => $after, 'weeks' => [],
                'clearsCurrentReading' => $changed && $current !== null,
                'removedCurrentAmount' => $changed && $current !== null && ! $current->isPending() ? $current->amount_due : '0.00',
            ];
            if ($save && $changed) {
                $locked->update(['initial_reading' => $after]);
                if ($current !== null) {
                    $current->recordAdjustment('initial_reading_adjustment', ['previousReading' => $before], ['previousReading' => $after], $request->user(), $request->input('reason'));
                    $current->requireCurrentReadingAgain($after, $request->user());
                }
            }

            return $preview;
        });
    }
}
