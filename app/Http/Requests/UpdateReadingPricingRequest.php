<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReadingPricingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reading = $this->route('meter_reading');

        return ($this->has('unit_price') || $this->has('minimum_payment'))
            && (! $this->has('unit_price') || $this->user()->can('updateUnitPrice', $reading))
            && (! $this->has('minimum_payment') || $this->user()->can('updateMinimumPayment', $reading));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'minimum_payment' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
        ];
    }
}
