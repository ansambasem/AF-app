<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One field set for many subscriptions: the ticked ones (`ids`), or every
 * subscription matching the list's search and filters (`all`, sent with the
 * list's own `search` and `filter`).
 */
class ApplySubscriptionBulkChangeRequest extends FormRequest
{
    /** The most subscriptions one bulk change may reach. */
    public const MAX_SUBSCRIPTIONS = 5000;

    public function authorize(): bool
    {
        return $this->user()->can('bulkUpdate', [Subscription::class, (string) $this->input('field')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isMinimum = $this->input('field') === 'minimum_charge';

        return [
            'field' => ['required', Rule::in(SubscriptionBulkChange::FIELDS)],
            'mode' => [Rule::requiredIf($isMinimum), Rule::in(['amount', 'circuit_breaker'])],
            'value' => $isMinimum
                ? [Rule::requiredIf($this->input('mode') === 'amount'), 'nullable', 'numeric', 'min:0', 'max:100000']
                : ($this->input('field') === 'meter_box_id'
                    ? ['required', 'integer', 'min:1']
                    : ['required', Rule::enum(SubscriptionStatus::class)]),
            'all' => ['boolean'],
            'expected_count' => ['required_if:field,meter_box_id', 'integer', 'min:1', 'max:'.self::MAX_SUBSCRIPTIONS],
            'ids' => ['exclude_if:all,true', 'required', 'array', 'min:1', 'max:'.self::MAX_SUBSCRIPTIONS],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'اختر مشتركًا واحدًا على الأقل.',
            'ids.max' => 'يمكن تعديل '.self::MAX_SUBSCRIPTIONS.' مشترك على الأكثر في المرة الواحدة.',
            'value.required' => 'أدخل القيمة الجديدة.',
        ];
    }
}
