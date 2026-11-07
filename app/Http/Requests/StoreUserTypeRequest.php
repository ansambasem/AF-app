<?php

namespace App\Http\Requests;

use App\Models\UserType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', UserType::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', Rule::unique('user_types', 'name')->ignore($this->route('user_type'))]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'اسم نوع المستخدم'];
    }
}
