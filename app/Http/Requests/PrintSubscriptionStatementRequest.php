<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintSubscriptionStatementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->is_active && $this->user()->can('viewAny', Subscription::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subscription' => ['nullable', 'integer', 'min:1'],
            'view' => ['sometimes', Rule::in(['compact', 'full'])],
            'filter' => ['sometimes', 'array:search,type,method,dateFrom,dateTo,sort'],
            'filter.search' => ['nullable', 'string', 'max:500'],
            'filter.type' => ['nullable', 'string', 'max:100'],
            'filter.method' => ['nullable', 'string', 'max:100'],
            'filter.dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'filter.dateTo' => ['nullable', 'date_format:Y-m-d'],
            'filter.sort' => ['nullable', Rule::in(['bank_asc', 'bank_desc', 'date_asc', 'date_desc'])],
            'history' => ['sometimes', 'array', 'max:1000'],
            'history.*' => ['integer', 'min:1'],
        ];
    }
}
