<?php

namespace App\Http\Requests;

use App\Enums\MessageKind;
use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $template = $this->route('message_template');

        return $template instanceof MessageTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', MessageTemplate::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(MessageKind::class)],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسمًا للقالب.',
            'body.required' => 'اكتب نص الرسالة.',
        ];
    }
}
