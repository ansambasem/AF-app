<?php

namespace App\Http\Requests;

use App\Models\MeterBox;
use App\Models\SubArea;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeterBoxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', MeterBox::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * UpdateMeterBoxRequest reuses these rules; there, `->ignore()` lets the
     * record being edited keep its own unique value (on create there is no
     * route model, so nothing is ignored).
     *
     * Only a Super Admin may choose the branch — the controller forces it
     * to the actor's own branch for everyone else, so branch_id isn't
     * validated for them at all.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_suffix' => [
                'nullable', 'string', 'max:50',
                Rule::unique('meter_boxes', 'name_suffix')
                    ->where('name', is_string($this->input('name')) ? $this->input('name') : null)
                    ->ignore($this->route('meter_box')),
            ],
            'box_number' => ['required', 'string', 'max:255', Rule::unique('meter_boxes', 'box_number')->ignore($this->route('meter_box'))],
            'sub_area_id' => ['required', 'integer', Rule::exists('sub_areas', 'id'), $this->areaOfTheBoxBranch()],
            'location' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->user()->isSuperAdmin()) {
            $rules['branch_id'] = ['required', Rule::exists('branches', 'id')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_suffix.unique' => 'هذه اللاحقة مستخدمة مع اسم الطبلون نفسه.',
            'sub_area_id.required' => 'اختر المنطقة التي يتبعها الطبلون.',
        ];
    }

    /**
     * The box's area must be one of its own branch's: the request's
     * `branch_id` for a Super Admin, the branch the actor works in for
     * everyone else (on update also the box's own, since the policy lets
     * them edit only its boxes).
     */
    private function areaOfTheBoxBranch(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branchId = (int) ($this->user()->isSuperAdmin() ? $this->input('branch_id') : $this->user()->workBranchId());

            if ((int) SubArea::query()->whereKey($value)->value('branch_id') !== $branchId) {
                $fail('المنطقة المختارة لا تتبع فرع الطبلون.');
            }
        };
    }
}
