<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserDeviceAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'))
            && (! $this->filled('password') || $this->user()->can('updatePassword', $this->route('user')));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Editing a job title or account details never changes permission grants.
     * Only a Super Admin may change an employee's administrative branch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actor = $this->user();
        $target = $this->route('user');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'user_type_id' => ['nullable', 'integer', Rule::exists('user_types', 'id')],
            'username' => ['required', 'string', 'max:255', 'regex:/\A[\p{L}\p{M}\p{N}_. -]+\z/u', Rule::unique('users', 'username')->ignore($target->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
            'role' => ['prohibited'],
            'max_devices' => $actor->can('updatePassword', $target) ? ['sometimes', 'required', 'integer', 'between:1,20'] : ['exclude'],
            'reset_devices' => $actor->can('updatePassword', $target) ? ['sometimes', 'boolean'] : ['exclude'],
        ];

        // Only a Super Admin moves an account to another branch, or between one branch and every branch.
        if ($actor->isSuperAdmin()) {
            $rules['company_wide'] = ['sometimes', 'boolean'];
            if (! $this->companyWide()) {
                $rules['branch_id'] = ['required', Rule::exists('branches', 'id')];
            }
        }

        return $rules;
    }

    /**
     * Whether the account is to work over every branch as a Super Admin:
     * as asked by a Super Admin, otherwise as it is now.
     */
    public function companyWide(): bool
    {
        return $this->user()->isSuperAdmin() && $this->has('company_wide')
            ? $this->boolean('company_wide')
            : $this->route('user')->isSuperAdmin();
    }

    /**
     * Nobody stops their own account or changes their own reach: whoever
     * does so could be shut out with no one left to undo it. Nor is the
     * last working Super Admin stopped or sent to one branch.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');
                if ($this->user()->can('updatePassword', $target) && $this->filled('max_devices') && $this->integer('max_devices') < $target->max_devices
                    && ! $this->boolean('reset_devices') && app(UserDeviceAccess::class)->activeCount($target) > $this->integer('max_devices')) {
                    $validator->errors()->add('max_devices', 'عدد الأجهزة المتصلة أكبر من الحد الجديد. اختر إنهاء جلسات الأجهزة لتطبيق الحد الجديد.');
                }
                $stopping = $this->has('is_active') && ! $this->boolean('is_active');

                if ($stopping && $target->is($this->user())) {
                    $validator->errors()->add('is_active', 'لا يمكنك إيقاف حسابك بنفسك.');
                }

                if ($target->isSuperAdmin() && ! $this->companyWide() && $target->is($this->user())) {
                    $validator->errors()->add('company_wide', 'لا يمكنك نقل حسابك إلى فرع بنفسك.');
                }

                $leavesNoSuperAdmin = $target->isSuperAdmin() && $target->is_active && ($stopping || ! $this->companyWide())
                    && ! User::query()->where('role', UserRole::SuperAdmin)->where('is_active', true)->whereKeyNot($target->id)->exists();
                if ($leavesNoSuperAdmin) {
                    $validator->errors()->add('company_wide', 'هذا آخر مدير عام نشط، فأبقِه على كل الفروع ونشطًا.');
                }
            },
        ];
    }
}
