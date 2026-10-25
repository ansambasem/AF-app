<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileSplitPaymentRequest;
use App\Models\Branch;
use App\Models\SplitPayment;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MobileSplitPaymentController extends Controller
{
    public function store(StoreMobileSplitPaymentRequest $request): JsonResponse
    {
        try {
            $split = DB::transaction(function () use ($request): SplitPayment {
                $existing = $request->existingPayment();
                if ($existing !== null) {
                    return $existing->splitPayment;
                }

                $parts = $request->parts();
                $branchIds = collect($parts)->pluck('subscription.branch_id')->unique()->sort()->values();
                Branch::query()->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get();
                $existing = $request->existingPayment();
                if ($existing !== null) {
                    abort_unless($existing->recorded_by === $request->user()->id && $existing->split_payment_id !== null, 403);

                    return $existing->splitPayment;
                }

                $split = SplitPayment::record($request->user(), $request->safe()->except(['parts', 'mobile_operation_id', 'collector_confirmed']), $parts);
                $split->payments()->firstOrFail()->forceFill(['mobile_operation_id' => $request->validated('mobile_operation_id')])->save();

                return $split;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $payment = $request->existingPayment();
            if ($payment === null) {
                throw $exception;
            }
            abort_unless($payment->recorded_by === $request->user()->id && $payment->split_payment_id !== null, 403);
            $split = $payment->splitPayment;
        }

        return response()->json([
            'id' => $split->id,
            'status' => 'recorded',
            'total_amount' => $split->total_amount,
            'reference_number' => $split->reference_number,
            'bank_received_on' => $split->bank_received_on?->toDateString(),
            'parts' => $split->payments()->with('subscription')->get()->map(fn (SubscriptionTransaction $payment): array => [
                'id' => $payment->id,
                'subscription_id' => $payment->subscription_id,
                'subscription' => $payment->subscription->displayName(),
                'amount' => $payment->currency_amount,
                'balance_after' => number_format($payment->subscription->balance(), 2, '.', ''),
                'voucher_number' => $payment->printedVoucherNumber(),
            ]),
        ], 201);
    }
}
