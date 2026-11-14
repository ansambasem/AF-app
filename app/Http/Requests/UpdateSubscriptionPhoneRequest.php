<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('subscription'));
    }

    /**
     * The same mobile number rule as the subscription form.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/\A05[69][0-9]{7}\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم الجوال.',
            'phone.regex' => 'رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056.',
        ];
    }

    /**
     * Arabic-Indic digits, spaces and dashes typed in the number are tidied first.
     */
    protected function prepareForValidation(): void
    {
        $phone = strtr((string) $this->input('phone'), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $this->merge(['phone' => preg_replace('/[\s-]+/', '', $phone)]);
    }
}
