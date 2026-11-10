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
     * The area's name, unique within its branch, and the one branch it
     * belongs to: any branch for a Super Admin, otherwise the branch the
     * user works in. UpdateSubAreaRequest reuses these rules; there the
     * area being edited keeps its own name.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('sub_areas', 'name')->where('branch_id', $this->input('branch_id'))->ignore($this->route('sub_area'))],
            'branch_id' => $this->user()->isSuperAdmin()
                ? ['required', 'integer', Rule::exists('branches', 'id')]
                : ['required', 'integer', Rule::in([$this->user()->workBranchId()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد في هذا الفرع منطقة بهذا الاسم.',
            'branch_id.required' => 'اختر الفرع الذي تتبعه المنطقة.',
            'branch_id.in' => 'يمكنك إضافة مناطق إلى فرعك فقط.',
        ];
    }
}
