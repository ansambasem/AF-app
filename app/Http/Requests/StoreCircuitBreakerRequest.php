<?php

namespace App\Http\Requests;

use App\Models\CircuitBreaker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCircuitBreakerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CircuitBreaker::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * UpdateCircuitBreakerRequest reuses these rules; there, `->ignore()` lets the
     * record being edited keep its own unique value (on create there is no
     * route model, so nothing is ignored).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ampere' => ['required', 'integer', 'min:2', Rule::unique('circuit_breakers', 'ampere')->ignore($this->route('circuit_breaker'))],
            'minimum_payment' => ['required', 'numeric', 'min:0'],
        ];
    }
}
