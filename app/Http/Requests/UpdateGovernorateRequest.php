<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreGovernorateRequest; only the authorization differs.
 */
class UpdateGovernorateRequest extends StoreGovernorateRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('governorate'));
    }
}
