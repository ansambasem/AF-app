<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreAreaRequest; only the authorization differs.
 */
class UpdateAreaRequest extends StoreAreaRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('area'));
    }
}
