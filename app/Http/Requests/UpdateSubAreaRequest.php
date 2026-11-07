<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreSubAreaRequest; only the authorization differs.
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
}
