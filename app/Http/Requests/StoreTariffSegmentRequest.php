<?php

namespace App\Http\Requests;

use App\Models\TariffSegment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffSegmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TariffSegment::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A segment's name is unique.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tariff_segments', 'name'),
            ],
        ];
    }
}
