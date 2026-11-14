import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import Switch from '@/Components/Switch';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the branch's own when editing, otherwise
 * blank (and active).
 */
export function branchFormData(branch) {
    return {
        name: branch?.name ?? '',
        phone: branch?.phone ?? '',
        is_active: branch?.is_active ?? true,
    };
}

/** A branch's details. Its areas, and their meter boxes, are managed on the branch areas page. */
export default function BranchForm({ data, setData, errors }) {
    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="building" className="h-6 w-6 text-white/70" />}
                dotClass={data.is_active ? 'bg-emerald-500' : 'bg-gray-400'}
                title={data.name.trim() || 'فرع جديد'}
                subtitle={data.phone.trim() || null}
                subtitleDir="ltr"
                chips={[data.is_active ? null : 'متوقف'].filter(Boolean)}
                filled={countFilled(data, ['name'])}
                total={1}
            />

            <FormSection icon="building" title="بيانات الفرع" columns={2}>
                <FormField id="name" label="الاسم" required error={errors.name}>
                    <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>

                <FormField id="phone" label="الهاتف" error={errors.phone}>
                    <TextInput dir="ltr" className="block w-full" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                </FormField>

                <div className="sm:col-span-2">
                    <Switch checked={data.is_active} onChange={(checked) => setData('is_active', checked)} label="فرع نشط" ariaLabel="فرع نشط" hint="الفرع المتوقف لا يظهر بين الفروع الفعالة." />
                    <InputError message={errors.is_active} className="mt-1" />
                </div>
            </FormSection>

        </div>
    );
}
