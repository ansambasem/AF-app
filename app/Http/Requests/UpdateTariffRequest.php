<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreTariffRequest; only the authorization differs.
 */
class UpdateTariffRequest extends StoreTariffRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tariff'));
    }
}
