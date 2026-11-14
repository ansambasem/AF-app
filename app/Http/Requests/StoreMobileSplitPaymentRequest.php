<?php

namespace App\Http\Requests;

use App\Models\SubscriptionTransaction;

class StoreMobileSplitPaymentRequest extends StoreSplitPaymentRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $payment = $this->existingPayment();

        return $payment ? $payment->recorded_by === $this->user()->id && $payment->split_payment_id !== null : parent::authorize();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mobile_operation_id' => ['required', 'uuid'],
            'collector_confirmed' => ['required', 'accepted'],
            ...($this->existingPayment() ? [] : parent::rules()),
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->existingPayment() ? [] : parent::after();
    }

    public function existingPayment(): ?SubscriptionTransaction
    {
        $id = $this->input('mobile_operation_id');

        return is_string($id) && $id !== ''
            ? SubscriptionTransaction::query()->where('mobile_operation_id', $id)->where('type', SubscriptionTransaction::TYPE_PAYMENT)->first()
            : null;
    }
}
