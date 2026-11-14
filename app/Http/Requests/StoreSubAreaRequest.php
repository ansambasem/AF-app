<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\SubArea;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

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
     * The area's name, unique within its branch (any other branch may use
     * it), and the branch it is added to: any branch for a Super Admin,
     * otherwise the branch the user works in.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => $this->user()->isSuperAdmin()
                ? ['required', 'integer', Rule::exists('branches', 'id')]
                : ['required', 'integer', Rule::in([$this->user()->workBranchId()])],
            'name' => ['required', 'string', 'max:255', $this->uniqueInBranch(Branch::query()->whereKey($this->integer('branch_id'))->value('area_id'))],
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

    /** The name may not repeat among the areas of the branch whose area container is `$container`. */
    protected function uniqueInBranch(?int $container): Unique
    {
        return Rule::unique('sub_areas', 'name')->where(fn ($query) => $container === null ? $query->whereRaw('1 = 0') : $query->where('area_id', $container));
    }
}
