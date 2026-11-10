<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Renaming an area: the name only, still unique within its branch. An area
 * stays with the branch it belongs to; it is never moved by editing it.
 */
class UpdateSubAreaRequest extends StoreSubAreaRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('sub_area'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $area = $this->route('sub_area');

        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueInBranch($area->area_id)->ignore($area)],
        ];
    }
}
