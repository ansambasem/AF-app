<?php

namespace App\Http\Requests;

use App\Support\BankTransferAuditing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBankTransferAuditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && BankTransferAuditing::allowed($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'revision' => ['nullable', 'string', 'size:64'],
            'parts' => ['sometimes', 'array', 'min:1', 'max:'.StoreSplitPaymentRequest::MAX_PARTS],
            'parts.*' => ['array:transaction_id,bank_amount'],
            'parts.*.transaction_id' => ['required', 'integer', 'distinct'],
            'parts.*.bank_amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
        ];
    }
}
