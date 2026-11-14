<?php

namespace App\Http\Requests;

use App\Enums\PermissionKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchDomainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission(PermissionKey::ManageBranchDomains);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'required_if:domain_enabled,true', 'string', 'max:253',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                Rule::unique('branches', 'domain')->ignore($this->route('branch')->id),
                Rule::notIn([strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))]),
            ],
            'domain_enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => strtolower(rtrim(trim($this->input('domain')), '.')) ?: null]);
        }
    }

    public function messages(): array
    {
        return ['domain.regex' => 'أدخل اسم الدومين فقط، مثل jenin.company.com، دون https أو مسار.',
            'domain.unique' => 'هذا الدومين مرتبط بفرع آخر.', 'domain.not_in' => 'هذا الدومين مخصص للوحة المركزية.',
            'domain.required_if' => 'أدخل الدومين قبل تفعيل رابط الفرع.'];
    }
}
