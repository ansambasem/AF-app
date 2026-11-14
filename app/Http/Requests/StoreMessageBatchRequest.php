<?php

namespace App\Http\Requests;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\SubscriptionStatus;
use App\Models\MessageBatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', MessageBatch::class);
    }

    /**
     * The message, how it goes out, and who gets it: the subscriptions picked
     * from the preview, still checked against the same criteria (see
     * MessageComposer::recipients()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(MessageKind::class)->only(MessageKind::composable())],
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'body' => ['required', 'string', 'max:1000'],
            'week_start' => [Rule::requiredIf($this->input('kind') === MessageKind::WeeklyReading->value), 'nullable', 'date_format:Y-m-d'],
            'approved_only' => ['boolean'],
            'min_balance' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'meter_box_name' => ['nullable', 'string', 'max:255'],
            'meter_box_id' => ['nullable', 'integer'],
            'circuit_breaker_id' => ['nullable', 'regex:/^(none|\d+)$/'],
            'subscription_ids' => ['required', 'array', 'min:1'],
            'subscription_ids.*' => ['integer'],
            'stay_on_page' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'اكتب نص الرسالة.',
            'subscription_ids.required' => 'اختر مستلمًا واحدًا على الأقل.',
            'subscription_ids.min' => 'اختر مستلمًا واحدًا على الأقل.',
            'week_start.required' => 'اختر أسبوع القراءة.',
        ];
    }
}
