<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class RescheduleDisconnectionItemsRequest extends DisconnectionItemsRequest
{
    protected function ability(): string
    {
        return 'manage';
    }

    /**
     * The new planned date (today at the earliest) and, if given, why.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'scheduled_for' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function today(): string
    {
        return now(config('app.business_timezone'))->toDateString();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'scheduled_for.required' => 'اختر موعد القطع الجديد.',
            'scheduled_for.after_or_equal' => 'موعد القطع لا يكون قبل اليوم.',
        ];
    }
}
