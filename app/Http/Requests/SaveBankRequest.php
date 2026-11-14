<?php

namespace App\Http\Requests;

use App\Models\Bank;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBankRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Bank::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('banks', 'name')->ignore($this->route('bank'))],
            'is_receiver' => ['required', 'boolean'],
            'is_sender' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('is_receiver') && ! $this->boolean('is_sender')) {
                $validator->errors()->add('is_receiver', 'اختر البنك للاستلام أو الإرسال أو كليهما.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'اسم البنك', 'logo' => 'شعار البنك'];
    }
}
