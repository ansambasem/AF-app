<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use App\Support\BankTransferAuditing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePeriodAuditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && BankTransferAuditing::allowed($this->user(), AuditAction::ApprovePeriods)
            && BankTransferAuditing::allowed($this->user(), AuditAction::ApproveBoxes);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'week' => ['required', 'date_format:Y-m-d'],
            'box_ids' => ['required', 'array', 'min:1'],
            'box_ids.*' => ['required', 'integer', 'distinct'],
            'snapshot' => ['required', 'string', 'size:64'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
