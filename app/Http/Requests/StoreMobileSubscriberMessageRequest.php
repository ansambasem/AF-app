<?php

namespace App\Http\Requests;

use App\Models\MessageBatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMobileSubscriberMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', MessageBatch::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mobile_operation_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile_operation_id.required' => 'معرّف الرسالة مطلوب. أعد فتح صفحة الإرسال.',
            'mobile_operation_id.uuid' => 'معرّف الرسالة غير صالح. أعد فتح صفحة الإرسال.',
            'body.required' => 'اكتب نص الرسالة أولاً.',
            'body.string' => 'نص الرسالة غير صالح.',
            'body.max' => 'الرسالة يجب ألا تتجاوز 1000 حرف.',
        ];
    }
}
