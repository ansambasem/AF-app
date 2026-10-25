<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSplitPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('splitPayment'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...AmendSubscriptionTransactionRequest::bankRules(true),
            'sender_name' => ['required', 'string', 'max:255'],
            'reference_number' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amendment_reason' => ['required', 'string', 'max:1000'],
            'total_amount' => ['prohibited'],
            'revision' => ['required', 'string', 'size:64'],
            'parts' => ['prohibited'], 'amount' => ['prohibited'], 'currency' => ['prohibited'], 'exchange_rate' => ['prohibited'],
            'recorded_by' => ['prohibited'], 'created_at' => ['prohibited'], 'payment_method' => ['prohibited'], 'branch_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreSubscriptionPaymentRequest::paymentMessages();
    }
}
