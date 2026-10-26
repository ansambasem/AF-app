<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPersonalDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('subscription'));
    }

    /**
     * The same rules as the subscription form's personal details; the
     * identity number stays unique to this person.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'regex:/^\d{9}$/', 'not_regex:/^0+$/', Rule::unique('subscriber_profiles', 'national_id')->ignore($this->route('subscription')->subscriber_profile_id)],
            'phone' => ['nullable', 'string', 'regex:/\A05[69][0-9]{7}\z/'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'national_id.regex' => 'رقم الهوية يجب أن يتكون من 9 أرقام.',
            'national_id.not_regex' => 'رقم الهوية غير صالح.',
            'national_id.unique' => 'رقم الهوية مسجل لشخص آخر.',
            'phone.regex' => 'رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056.',
        ];
    }

    /**
     * Arabic-Indic digits, spaces and dashes typed in the numbers are tidied first.
     */
    protected function prepareForValidation(): void
    {
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

        // Left empty, they stay empty (null), so records without them never collide on the unique identity number.
        $plain = fn (string $field): ?string => preg_replace('/[\s-]+/', '', strtr((string) $this->input($field), $digits)) ?: null;

        $this->merge(['national_id' => $plain('national_id'), 'phone' => $plain('phone')]);
    }
}
