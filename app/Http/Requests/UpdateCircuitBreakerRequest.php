<?php

namespace App\Http\Requests;

/**
 * Same rules as StoreCircuitBreakerRequest; only the authorization differs.
 */
class UpdateCircuitBreakerRequest extends StoreCircuitBreakerRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('circuit_breaker'));
    }
}
