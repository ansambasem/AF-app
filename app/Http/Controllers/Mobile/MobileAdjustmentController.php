<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\ChargeType;
use App\Enums\DiscountMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileAdjustmentRequest;
use App\Models\SubscriptionTransaction;
use App\Support\BranchClosingWorkflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class MobileAdjustmentController extends Controller
{
    public function store(StoreMobileAdjustmentRequest $request, BranchClosingWorkflow $workflow): JsonResponse
    {
        $subscription = $request->route('subscription');
        try {
            $transaction = $workflow->locked($subscription->branch_id, function () use ($request, $subscription): SubscriptionTransaction {
                if ($existing = $request->existingTransaction()) {
                    abort_unless($request->ownsOperation($existing), 403);

                    return $existing;
                }

                $validator = Validator::make([], []);
                $validator->after(function (ValidationValidator $validator) use ($request): void {
                    $request->validateBalance($validator);
                });
                $validator->validate();
                $data = $request->validated();
                $transaction = match ($data['action']) {
                    'charge' => SubscriptionTransaction::recordCharge($subscription, $request->user(), ChargeType::from($data['type']), $data['amount'], $data['notes'] ?? null),
                    'discount' => SubscriptionTransaction::recordDiscount($subscription, $request->user(), DiscountMethod::Shekel, $data['value'], $data['notes'] ?? null),
                    'clearing' => SubscriptionTransaction::recordClearing($subscription, $request->user(), $data['amount'], $data['notes'], $data['manual_voucher_number'] ?? null),
                };
                $transaction->update(['mobile_operation_id' => $data['mobile_operation_id']]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $transaction = $request->existingTransaction();
            if (! $transaction) {
                throw $exception;
            }
            abort_unless($request->ownsOperation($transaction), 403);
        }

        return response()->json([
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'voucher_number' => $transaction->displayVoucherNumber(),
            'balance_after' => number_format($subscription->balance(), 2, '.', ''),
        ], 201);
    }
}
