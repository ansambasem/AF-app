<?php

namespace App\Http\Requests;

use App\Enums\CorrectionReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deleting a line of a subscription's account — a payment, charge, discount, clearing, weekly reading or fee — and why.
 */
class DeleteSubscriptionTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('transaction'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'correction_reason' => ['required', Rule::enum(CorrectionReason::class)->only(CorrectionReason::forDeletionOf($this->route('transaction')))],
            'correction_notes' => ['required', 'string', 'max:1000'],
            // Cancelling a weekly reading's bill: send the reading back for review (to correct and bill again) instead of waiving it.
            'reopen_reading' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['correction_reason' => 'سبب الإلغاء', 'correction_notes' => 'شرح الإلغاء'];
    }
}
