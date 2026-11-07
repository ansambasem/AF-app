<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use App\Support\BranchClosingAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashBoxReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->is_active && ($this->user()->can('viewOwnCashBoxes', Subscription::class)
            || BranchClosingAccess::view($this->user()));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'box' => ['nullable', 'integer', 'min:1'],
            'filter' => ['sometimes', Rule::in(['active', 'mismatched', 'returned', 'all'])],
        ];
    }
}
