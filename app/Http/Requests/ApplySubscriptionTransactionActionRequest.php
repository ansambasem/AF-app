<?php

namespace App\Http\Requests;

use App\Enums\CorrectionReason;
use App\Enums\TransactionAction;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplySubscriptionTransactionActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Subscription $subscription */
        $subscription = $this->route('subscription');

        return $this->user()->can('view', $subscription);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(TransactionAction::class)],
            // A refund always returns the whole payment; a wrong amount is put right with a new payment.
            'amount' => ['required_if:action,edit', 'prohibited_if:action,refund', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:1000000'],
            // Editing a payment's details follows the amendment form's rules; no other action takes bank details.
            ...($this->input('action') === TransactionAction::EditMetadata->value
                ? AmendSubscriptionTransactionRequest::bankRules($this->transaction()->payment_method?->throughBank() ?? false)
                : ['bank_name' => ['nullable', 'string', 'max:255'], 'sender_bank_name' => ['nullable', 'string', 'max:255'], 'sender_name' => ['nullable', 'string', 'max:255']]),
            'reference_number' => $this->input('action') === TransactionAction::EditMetadata->value && $this->transaction()->payment_method?->throughBank()
                ? ['sometimes', 'required', 'string', 'max:100'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amendment_reason' => ['required_if:action,edit,edit_metadata', 'nullable', 'string', 'max:1000'],
            'correction_reason' => ['nullable', Rule::enum(CorrectionReason::class)],
            'correction_notes' => ['required_if:action,delete,delete_reversal,delete_tree', 'nullable', 'string', 'max:1000'],
            // Cancelling a weekly reading's bill: send the reading back for review (to correct and bill again) instead of waiving it.
            'reopen_reading' => ['sometimes', 'boolean'],
            'subscription_id' => ['prohibited'],
            'type' => ['prohibited'],
            'status' => ['prohibited'],
            'reference_transaction_id' => ['prohibited'],
            'balance_after' => ['prohibited'],
            'created_at' => ['prohibited'],
        ];
    }

    public function action(): TransactionAction
    {
        return TransactionAction::from($this->validated('action'));
    }

    public function transaction(): SubscriptionTransaction
    {
        return $this->route('transaction');
    }
}
