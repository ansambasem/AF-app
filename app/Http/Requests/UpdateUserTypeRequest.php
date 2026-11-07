<?php

namespace App\Http\Requests;

class UpdateUserTypeRequest extends StoreUserTypeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user_type'));
    }
}
