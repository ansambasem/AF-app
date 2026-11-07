<?php

namespace App\Http\Requests;

use App\Models\SubArea;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubAreaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', SubArea::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * UpdateSubAreaRequest reuses these rules; there, `->ignore()` lets the
     * record being edited keep its own unique value (on create there is no
     * route model, so nothing is ignored).
     *
     * A Super Admin may place a sub-area in any area (or none); anyone else
     * only in their own branch's area.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('sub_areas', 'name')->ignore($this->route('sub_area'))],
            'area_id' => $this->user()->isSuperAdmin()
                ? ['nullable', Rule::exists('areas', 'id')]
                : ['required', Rule::in([$this->user()->branchAreaId()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'area_id.required' => 'يمكنك إضافة منطقة 2 داخل منطقة فرعك فقط.',
            'area_id.in' => 'يمكنك إضافة منطقة 2 داخل منطقة فرعك فقط.',
        ];
    }
}
