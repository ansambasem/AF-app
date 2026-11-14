<?php

namespace App\Http\Requests;

use App\Enums\TariffCategory;
use App\Models\Tariff;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Tariff::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * UpdateTariffRequest reuses these rules; there, `->ignore()` lets the
     * record being edited keep its own unique value (on create there is no
     * route model, so nothing is ignored).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(TariffCategory::class), Rule::unique('tariffs', 'category')->ignore($this->route('tariff'))],
            'rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
