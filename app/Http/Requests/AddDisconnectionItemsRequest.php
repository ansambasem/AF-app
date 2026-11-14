<?php

namespace App\Http\Requests;

use App\Enums\DisconnectionReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subscriptions put on a disconnection list: the ticked ones (`ids`), or
 * every one matching the candidates page's search and filters (`all`),
 * with one standard reason, an optional note and the planned date.
 */
class AddDisconnectionItemsRequest extends FormRequest
{
    /** The most subscriptions added at once. */
    public const MAX_SUBSCRIPTIONS = 2000;

    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('disconnectionList'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DisconnectionReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'scheduled_for' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now(config('app.business_timezone'))->toDateString()],
            'all' => ['boolean'],
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
            'reason.required' => 'اختر سبب القطع.',
            'scheduled_for.required' => 'اختر موعد القطع.',
            'scheduled_for.after_or_equal' => 'موعد القطع لا يكون قبل اليوم.',
            'ids.required' => 'اختر مشتركًا واحدًا على الأقل.',
            'ids.max' => 'يمكن إضافة '.self::MAX_SUBSCRIPTIONS.' مشترك على الأكثر في المرة الواحدة؛ ضيّق الفلاتر.',
        ];
    }
}
