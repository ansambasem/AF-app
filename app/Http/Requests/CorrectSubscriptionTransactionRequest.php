<?php

namespace App\Http\Requests;

use App\Enums\CorrectionReason;
use App\Models\CurrencyRate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Correcting a payment, charge, discount or clearing: the right line's
 * fields — the same as recording one of its kind — and why it is
 * corrected.
 */
class CorrectSubscriptionTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->line()->isPayment()) {
            $this->merge(['exchange_rate' => CurrencyRate::forPayment($this->input('currency'))]);
            if (! $this->exists('bank_received_on')) {
                $this->merge(['bank_received_on' => $this->line()->bank_received_on?->toDateString()]);
            }
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->line());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $line = $this->line();

        return [
            ...match (true) {
                $line->isPayment() => StoreSubscriptionPaymentRequest::paymentRules($this->input('payment_method')),
                $line->isDiscount() => StoreSubscriptionDiscountRequest::discountRules(),
                $line->isClearing() => StoreSubscriptionClearingRequest::clearingRules(),
                default => StoreSubscriptionChargeRequest::chargeRules($this->input('type')),
            },
            'correction_reason' => ['required', Rule::enum(CorrectionReason::class)->only(CorrectionReason::forCorrectionOf($line))],
            'correction_notes' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * A corrected discount or clearing is checked against what the
     * subscription would owe without the line it replaces.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // A corrected payment is checked against what the subscription would owe without the one it replaces.
                if ($this->line()->isPayment()) {
                    /** @var Subscription $subscription */
                    $subscription = $this->route('subscription');
                    $owed = max(round($subscription->balance() - (float) $this->line()->amount, 2), 0.0);

                    StoreSubscriptionPaymentRequest::checkOverpayment($validator, StoreSubscriptionPaymentRequest::amountInShekels($this->input('amount'), $this->input('currency'), $this->input('exchange_rate')), $owed, $this->boolean('confirm_overpayment'));

                    return;
                }

                if (! ($this->line()->isDiscount() || $this->line()->isClearing())) {
                    return;
                }

                /** @var Subscription $subscription */
                $subscription = $this->route('subscription');
                $owed = round($subscription->balance() - (float) $this->line()->amount, 2);

                if ($this->line()->isDiscount()) {
                    StoreSubscriptionDiscountRequest::checkAgainstBalance($validator, $subscription, $owed, $this->input('method'), $this->input('value'));
                } else {
                    StoreSubscriptionClearingRequest::checkAgainstBalance($validator, $owed, $this->input('amount'), $this->boolean('confirm_over_balance'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...StoreSubscriptionPaymentRequest::paymentMessages(),
            ...StoreSubscriptionDiscountRequest::discountMessages(),
            ...($this->line()->isClearing() ? StoreSubscriptionClearingRequest::clearingMessages() : StoreSubscriptionChargeRequest::chargeMessages()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'نوع التحميل',
            'method' => 'طريقة الخصم',
            'value' => 'قيمة الخصم',
            'notes' => 'التفاصيل',
            'correction_reason' => 'سبب التصحيح',
            'correction_notes' => 'شرح التصحيح',
        ];
    }

    private function line(): SubscriptionTransaction
    {
        return $this->route('transaction');
    }
}
