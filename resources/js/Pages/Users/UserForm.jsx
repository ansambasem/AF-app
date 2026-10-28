import { useState } from 'react';
import ChoiceChips from '@/Components/ChoiceChips';
import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import PasswordInput from '@/Components/PasswordInput';
import Switch from '@/Components/Switch';
import TextInput from '@/Components/TextInput';
import { initials } from '@/lib/format';
import UserTypeSelect from './UserTypeSelect';

function DeviceResetCheckbox({ id, checked, onChange }) {
    return <label htmlFor={id} className="flex min-h-[2.75rem] cursor-pointer items-center gap-3 rounded-control border border-gray-200 px-3 py-2 text-sm leading-6">
        <input id={id} type="checkbox" checked={checked} onChange={onChange} className="h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600" />
        <span>إنهاء كل جلسات هذا المستخدم عند الحفظ</span>
    </label>;
}

/**
 * The form's starting values: the user's own when editing (with the
 * password left blank, meaning "unchanged"), otherwise blank for
 * a regular staff account with permissions assigned separately.
 */
export function userFormData(user) {
    return {
        name: user?.name ?? '',
        user_type_id: user?.user_type_id ?? '',
        username: user?.username ?? '',
        password: '',
        password_confirmation: '',
        branch_id: user?.branch_id ?? '',
        company_wide: user?.isSuperAdmin ?? false,
        is_active: user?.is_active ?? true,
        max_devices: user?.max_devices ?? 1,
        reset_devices: false,
    };
}

export default function UserForm({ data, setData, errors, isEdit, branches, canChooseBranch, canCreateUserType = false, canUpdatePassword = false, userTypeOptions = [] }) {
    const [createdTypes, setCreatedTypes] = useState([]);
    const availableTypes = [...new Map([...userTypeOptions, ...createdTypes].map((type) => [String(type.value), type])).values()];
    const name = data.name.trim();
    const userType = availableTypes.find((option) => String(option.value) === String(data.user_type_id));
    const branch = branches.find((option) => String(option.id) === String(data.branch_id));
    // A new account needs its password; an existing one keeps theirs unless a new one is typed.
    const requiredFields = ['name', 'username', ...(isEdit ? [] : ['password'])];

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={name ? initials(name) : <Icon name="user" className="h-6 w-6 text-white/70" />}
                dotClass={data.is_active ? 'bg-emerald-500' : 'bg-gray-400'}
                title={name || 'مستخدم جديد'}
                subtitle={data.username.trim() ? `@${data.username.trim()}` : null}
                subtitleDir="auto"
                chips={[
                    data.company_wide ? 'مدير عام' : userType?.label,
                    data.company_wide ? 'كل الفروع' : branch?.name,
                    data.is_active ? null : 'موقوف',
                ].filter(Boolean)}
                filled={countFilled(data, requiredFields)}
                total={requiredFields.length}
            />

            <FormSection icon="user" title="بيانات الحساب" columns={2}>
                <FormField id="name" label="الاسم" required error={errors.name}>
                    <TextInput className="block h-11 w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>

                <FormField id="username" label="اسم المستخدم" required error={errors.username}>
                    <TextInput
                        dir="auto"
                        required
                        pattern="[\p{L}\p{M}\p{N}_. \-]+"
                        data-feedback
                        title="يمكن استخدام الحروف العربية والإنجليزية والأرقام والمسافات والرموز . _ -"
                        autoComplete="off"
                        className="block h-11 w-full"
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                    />
                </FormField>

                {(!isEdit || canUpdatePassword) && (
                    <>
                        <FormField
                            id="password"
                            label={isEdit ? 'كلمة مرور جديدة' : 'كلمة المرور'}
                            required={!isEdit}
                            error={errors.password}
                            hint={isEdit ? 'اتركها فارغة للاحتفاظ بالحالية. عند تغييرها: 8 خانات على الأقل، ويمكن استخدام حروف فقط أو أرقام فقط.' : '8 خانات على الأقل، ويمكن استخدام حروف فقط أو أرقام فقط.'}
                        >
                            <PasswordInput className="block h-11 w-full" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                        </FormField>

                        <FormField id="password_confirmation" label="تأكيد كلمة المرور">
                            <PasswordInput className="block h-11 w-full" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                        </FormField>
                    </>
                )}
            </FormSection>

            {(!isEdit || canUpdatePassword) && <FormSection icon="lock" title="أجهزة تسجيل الدخول" columns={2}>
                <FormField id="max_devices" label="الحد الأقصى للأجهزة" required error={errors.max_devices} hint="جهاز واحد افتراضيًا. يشترك الموقع والتطبيق في هذا الحد؛ عند الدخول من جهاز جديد وبلوغ الحد يُسجّل خروج الجهاز الأقدم تلقائيًا.">
                    <TextInput type="number" min="1" max="20" step="1" dir="ltr" className="block h-11 w-full" value={data.max_devices} onChange={(event) => setData('max_devices', event.target.value)} />
                </FormField>
                {isEdit && <FormField id="reset_devices" label="إنهاء جلسات الأجهزة" error={errors.reset_devices} hint="استخدمه عند فقدان جهاز. بعد الحفظ ستحتاج جميع الأجهزة، بما فيها جهاز هذا المستخدم الحالي، إلى تسجيل الدخول مجددًا.">
                    <DeviceResetCheckbox checked={data.reset_devices} onChange={(event) => setData('reset_devices', event.target.checked)} />
                </FormField>}
            </FormSection>}

            <FormSection icon="badge" title="النوع والفرع والحالة" columns={2}>
                <UserTypeSelect value={data.user_type_id} options={availableTypes} canCreate={canCreateUserType} error={errors.user_type_id}
                    onChange={(value) => setData('user_type_id', value)}
                    onCreated={(type) => { setCreatedTypes((types) => [...types, type]); setData('user_type_id', String(type.value)); }} />

                {canChooseBranch && (
                    <FormField
                        id="company_wide"
                        label="نطاق العمل"
                        hint="«كل الفروع» يجعله مديرًا عامًا مثلك: كل الصلاحيات في كل الفروع، ويدير الشركة كلها. «فرع واحد» موظف في فرعه بالصلاحيات التي تمنحها له."
                        error={errors.company_wide}
                        span="sm:col-span-2"
                    >
                        <ChoiceChips
                            id="company_wide"
                            label="نطاق العمل"
                            value={data.company_wide ? 'company' : 'branch'}
                            onChange={(value) => setData('company_wide', value === 'company')}
                            options={[
                                { value: 'branch', label: 'فرع واحد', icon: 'pin' },
                                { value: 'company', label: 'كل الفروع · مدير عام', icon: 'shield' },
                            ]}
                        />
                    </FormField>
                )}

                {canChooseBranch && !data.company_wide && (
                    <FormSelect
                        id="branch_id"
                        label="الفرع"
                        value={data.branch_id}
                        onChange={(value) => setData('branch_id', value)}
                        options={namedOptions(branches)}
                        placeholder="— اختر فرعًا —"
                        emptyMessage="لا توجد فروع بعد — أنشئ فرعًا أولاً"
                        error={errors.branch_id}
                    />
                )}

                <div className="sm:col-span-2">
                    <Switch checked={data.is_active} onChange={(checked) => setData('is_active', checked)} label="حساب نشط" ariaLabel="حساب نشط" hint="الحساب الموقوف لا يستطيع تسجيل الدخول." />
                    <InputError message={errors.is_active} className="mt-1" />
                </div>
            </FormSection>
        </div>
    );
}
