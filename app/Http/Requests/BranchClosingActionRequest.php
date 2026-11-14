<?php

namespace App\Http\Requests;

use App\Enums\CashBoxType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchClosingActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_active ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->route('action');
        $rules = ['branch_id' => ['required', 'integer', 'exists:branches,id']];
        if ($action === 'receive-type') {
            $rules += ['type' => ['required', Rule::enum(CashBoxType::class)], 'box_ids' => ['required', 'array', 'min:1'], 'box_ids.*' => ['integer', 'distinct']];
        }
        if ($action === 'electronic') {
            $rules += ['status' => ['required', 'in:confirmed,missing,rejected']];
        }

        return $rules;
    }
}
