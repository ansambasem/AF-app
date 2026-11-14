<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class RemoveDisconnectionItemsRequest extends DisconnectionItemsRequest
{
    protected function ability(): string
    {
        return 'manage';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
