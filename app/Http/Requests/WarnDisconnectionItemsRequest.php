<?php

namespace App\Http\Requests;

use App\Enums\MessageChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class WarnDisconnectionItemsRequest extends DisconnectionItemsRequest
{
    protected function ability(): string
    {
        return 'warn';
    }

    /**
     * The warning's wording (with `{placeholders}`), how it is sent, and the
     * id of this send, so the same send arriving twice goes out once.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'body' => ['required', 'string', 'max:1000'],
            'operation_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'body.required' => 'اكتب نص الإنذار.',
        ];
    }
}
