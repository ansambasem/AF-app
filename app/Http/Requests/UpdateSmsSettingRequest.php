<?php

namespace App\Http\Requests;

use App\Models\SmsSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The SMS provider's settings as the dashboard sends them. A password or
 * token left blank keeps the one saved (they are never sent back to the
 * page); `clear_password` / `clear_token` remove it.
 */
class UpdateSmsSettingRequest extends FormRequest
{
    /** A field name in a provider's API: letters, digits and _ - . [ ]. */
    private const FIELD_NAME = 'regex:/^[A-Za-z0-9_\-.\[\]]+$/';

    public function authorize(): bool
    {
        return $this->user()->can('manage', SmsSetting::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'provider_name' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'required_if:enabled,true', 'url:http,https', 'max:500'],
            'method' => ['required', Rule::in(['get', 'post'])],
            'username_param' => ['nullable', 'required_with:username', 'string', 'max:50', self::FIELD_NAME],
            'username' => ['nullable', 'string', 'max:191'],
            'password_param' => ['nullable', 'string', 'max:50', self::FIELD_NAME],
            'password' => ['nullable', 'string', 'max:500'],
            'clear_password' => ['sometimes', 'boolean'],
            'token' => ['nullable', 'string', 'max:2000'],
            'clear_token' => ['sometimes', 'boolean'],
            'to_param' => ['required', 'string', 'max:50', self::FIELD_NAME],
            'message_param' => ['required', 'string', 'max:50', self::FIELD_NAME, 'different:to_param'],
            'sender_param' => ['nullable', 'required_with:sender', 'string', 'max:50', self::FIELD_NAME],
            'sender' => ['nullable', 'string', 'max:20'],
            'phone_format' => ['required', Rule::in(['international', 'local'])],
            'country_code' => ['required', 'digits_between:1,4'],
            'extra_params' => ['nullable', 'string', 'max:500'],
            'success_match' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The settings to save over `$current`: everything sent, with the
     * password and token kept unless a new one was typed or they were
     * cleared.
     *
     * @return array<string, mixed>
     */
    public function settings(SmsSetting $current): array
    {
        $values = $this->safe()->except(['password', 'token', 'clear_password', 'clear_token']);

        foreach (['password', 'token'] as $secret) {
            $values[$secret] = match (true) {
                $this->boolean('clear_'.$secret) => null,
                filled($this->validated($secret)) => $this->validated($secret),
                default => $current->{$secret},
            };
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required_if' => 'أدخل رابط الإرسال قبل تفعيل البوابة.',
            'url.url' => 'رابط الإرسال يجب أن يبدأ بـ https:// أو http://.',
            '*.regex' => 'اسم الحقل يُكتب بحروف إنجليزية وأرقام فقط، كما في وثيقة المزود.',
            'username_param.required_with' => 'أدخل اسم حقل المستخدم كما في وثيقة المزود.',
            'sender_param.required_with' => 'أدخل اسم حقل المرسل كما في وثيقة المزود.',
            'message_param.different' => 'حقل الرسالة يجب أن يختلف عن حقل الرقم.',
            'country_code.digits_between' => 'رمز الدولة أرقام فقط، مثل 970.',
            'sender.max' => 'اسم المرسل لا يزيد عن :max حرفًا.',
        ];
    }
}
