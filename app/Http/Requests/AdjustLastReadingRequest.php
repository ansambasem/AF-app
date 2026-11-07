<?php

namespace App\Http\Requests;

use App\Models\MeterReading;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdjustLastReadingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('adjustLastReading', $this->route('meter_reading'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', Rule::in(['correction', 'meter_change'])],
            'current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'expected_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'reason' => ['required_if:adjustment_type,meter_change', 'nullable', 'string', 'max:1000'],
            'confirm_unusual' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $reading = $this->route('meter_reading');
            if (round($reading->nextPreviousReading(), 2) !== round($this->float('expected_reading'), 2)) {
                $validator->errors()->add('current_reading', 'تغيّرت القراءة؛ حدّث الصفحة وراجع القيمة الجديدة قبل التعديل.');
            }
            if ($this->isMeterChange()) {
                return;
            }
            if ($this->float('current_reading') < $reading->previous_reading) {
                $validator->errors()->add('current_reading', "تصحيح القراءة لا يمكن أن يقل عن بداية الفترة ({$reading->previous_reading}). عند تغيير العداد اختر «عداد جديد / مستبدل».");
            } elseif (MeterReading::isImpossibleConsumption(MeterReading::consumptionBetween($reading->previous_reading, $this->float('current_reading')))) {
                $validator->errors()->add('current_reading', StoreMeterReadingRequest::impossibleConsumptionMessage($this->float('current_reading') - $reading->previous_reading));
            }
        }];
    }

    public function isMeterChange(): bool
    {
        return $this->input('adjustment_type') === 'meter_change';
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'adjustment_type.required' => 'اختر سبب تعديل القراءة أولًا.',
            'adjustment_type.in' => 'اختر تصحيح خطأ إدخال أو عداد جديد / مستبدل.',
            'reason.required_if' => 'اكتب سبب تغيير العداد، مثل السرقة أو التلف أو الاستبدال.',
        ];
    }
}
