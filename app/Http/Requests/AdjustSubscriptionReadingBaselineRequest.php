<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdjustSubscriptionReadingBaselineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('adjustInitialReading', $this->route('subscription'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'expected_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'adjustment_type' => ['required', 'in:correction,meter_change'],
            'reason' => ['required_if:adjustment_type,meter_change', 'nullable', 'string', 'max:1000'],
        ];
    }
}
