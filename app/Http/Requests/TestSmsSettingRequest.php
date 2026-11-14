<?php

namespace App\Http\Requests;

use App\Models\SmsSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * A test message sent with the settings as they are on the page, saved or
 * not, to a phone of the tester's choosing.
 */
class TestSmsSettingRequest extends UpdateSmsSettingRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'enabled' => ['sometimes', 'boolean'],
            'url' => ['required', 'url:http,https', 'max:500'],
            'test_phone' => ['required', 'string', 'regex:/^\+?[0-9٠-٩\s\-]{7,20}$/u'],
            'test_message' => ['required', 'string', 'max:300'],
        ];
    }

    /**
     * The gateway settings to test: the page's, with the saved password and
     * token where none was typed.
     *
     * @return array<string, mixed>
     */
    public function gatewayConfig(SmsSetting $current): array
    {
        return (new SmsSetting(Arr::except($this->settings($current), ['test_phone', 'test_message'])))->gatewayConfig();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'url.required' => 'أدخل رابط الإرسال لتجربة البوابة.',
            'test_phone.required' => 'أدخل رقم الجوال الذي تصله رسالة التجربة.',
            'test_phone.regex' => 'رقم الجوال غير صحيح؛ مثال: 0599123456.',
            'test_message.required' => 'أدخل نص رسالة التجربة.',
        ];
    }
}
