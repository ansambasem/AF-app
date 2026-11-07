<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Models\CurrencyRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', CurrencyRate::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rate' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:10000', ...($this->route('currencyRate')->currency === Currency::Shekel ? ['in:1'] : [])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['rate' => 'سعر الصرف'];
    }
}
