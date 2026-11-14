<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreMeterBoxRequest; only the authorization differs.
 */
class UpdateMeterBoxRequest extends StoreMeterBoxRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meter_box'));
    }
}
