<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreBranchRequest; only the authorization differs.
 */
class UpdateBranchRequest extends StoreBranchRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('branch'));
    }
}
