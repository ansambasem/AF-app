<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use App\Models\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePermissionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', Permission::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['present', 'array'],
            'permissions.*.*' => ['integer', 'exists:permissions,id'],
            // The right to hand a permission on is the Super Admin's alone to give.
            'grantable' => ['sometimes', Rule::prohibitedIf(! $this->user()->isSuperAdmin()), 'array'],
            'grantable.*' => ['present', 'array'],
            'grantable.*.*' => ['integer', 'exists:permissions,id'],
            'audit_settings' => ['sometimes', Rule::prohibitedIf(! $this->user()->isSuperAdmin()), 'array'],
            'audit_settings.*' => ['array:scope,branch_ids,actions'],
            'audit_settings.*.scope' => ['required', Rule::in(['all', 'selected'])],
            'audit_settings.*.branch_ids' => ['present', 'array'],
            'audit_settings.*.branch_ids.*' => ['integer', 'exists:branches,id'],
            'audit_settings.*.actions' => ['present', 'array'],
            'audit_settings.*.actions.*' => ['string', Rule::enum(AuditAction::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('audit_settings', []) as $id => $settings) {
                if (! array_key_exists($id, $this->input('permissions', []))) {
                    $validator->errors()->add('audit_settings', 'احفظ إعدادات التدقيق مع صلاحيات الموظف نفسه.');
                }
                if ($settings['scope'] === 'selected' && $settings['branch_ids'] === []) {
                    $validator->errors()->add('audit_settings', 'اختر فرعًا واحدًا على الأقل للتدقيق.');
                }
                if (in_array(AuditAction::ApprovePeriods->value, $settings['actions'], true)
                    && ! in_array(AuditAction::ApproveBoxes->value, $settings['actions'], true)) {
                    $validator->errors()->add('audit_settings', 'اعتماد الفترة يتطلب صلاحية اعتماد الصناديق.');
                }
            }
        }];
    }
}
