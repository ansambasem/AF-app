<?php

namespace App\Http\Requests;

use App\Enums\ChargeType;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMobileAdjustmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->route()->setParameter('subscription', Subscription::query()
            ->visibleTo($this->user())->findOrFail($this->route('subscription')));
    }

    public function authorize(): bool
    {
        if ($existing = $this->existingTransaction()) {
            return $this->ownsOperation($existing);
        }

        return match ($this->input('action')) {
            'charge' => $this->user()->can('addCharge', $this->route('subscription')),
            'discount' => $this->user()->can('addDiscount', $this->route('subscription')),
            'clearing' => $this->user()->can('addClearing', $this->route('subscription')),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mobile_operation_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['charge', 'discount', 'clearing'])],
            ...($this->existingTransaction() ? [] : match ($this->input('action')) {
                'charge' => [...StoreSubscriptionChargeRequest::chargeRules($this->input('type')), 'override_minimum_amount' => ['sometimes', 'boolean']],
                'discount' => StoreSubscriptionDiscountRequest::discountRules(),
                'clearing' => StoreSubscriptionClearingRequest::clearingRules(),
                default => [],
            }),
        ];
    }

    /** Recheck mutable balances while the controller holds the branch lock. */
    public function validateBalance(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || $this->existingTransaction()) {
            return;
        }

        $subscription = $this->route('subscription');
        if ($this->input('action') === 'discount') {
            StoreSubscriptionDiscountRequest::checkAgainstBalance($validator, $subscription, $subscription->balance(), $this->input('method'), $this->input('value'));
        } elseif ($this->input('action') === 'clearing') {
            StoreSubscriptionClearingRequest::checkAgainstBalance($validator, $subscription->balance(), $this->input('amount'), $this->boolean('confirm_over_balance'));
        } elseif ($this->input('type') === ChargeType::MinimumCharge->value) {
            $minimum = Closing::cents($subscription->weeklyMinimumPayment());
            if ($minimum <= 0) {
                $validator->errors()->add('amount', 'لا يوجد حد أدنى موجب محدد لهذا المشترك.');
            } elseif (! $this->boolean('override_minimum_amount') && Closing::cents($this->input('amount')) !== $minimum) {
                $validator->errors()->add('amount', 'يجب أن يساوي المبلغ الحد الأدنى الحالي للمشترك. حدّث الصفحة وحاول مجددًا.');
            }
        }
    }

    public function existingTransaction(): ?SubscriptionTransaction
    {
        $operation = $this->input('mobile_operation_id');

        return is_string($operation) && $operation !== ''
            ? SubscriptionTransaction::query()->where('mobile_operation_id', $operation)->first()
            : null;
    }

    public function ownsOperation(SubscriptionTransaction $transaction): bool
    {
        $matchesAction = match ($this->input('action')) {
            'charge' => ChargeType::tryFrom($transaction->type) !== null,
            'discount' => $transaction->isDiscount(),
            'clearing' => $transaction->isClearing(),
            default => false,
        };

        return $transaction->recorded_by === $this->user()->id
            && $transaction->subscription_id === $this->route('subscription')->id && $matchesAction;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...StoreSubscriptionChargeRequest::chargeMessages(), ...StoreSubscriptionDiscountRequest::discountMessages(), ...StoreSubscriptionClearingRequest::clearingMessages()];
    }
}
