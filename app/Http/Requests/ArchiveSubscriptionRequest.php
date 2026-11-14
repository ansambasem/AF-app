<?php

namespace App\Http\Requests;

use App\Support\DailySeries;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('subscription'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_ended_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DailySeries::today()->toDateString()],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return ['service_ended_on' => 'تاريخ إنهاء الخدمة', 'reason' => 'سبب الأرشفة', 'confirmed' => 'تأكيد إنهاء الخدمة ومراجعة الرصيد'];
    }
}
