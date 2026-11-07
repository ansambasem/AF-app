import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
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
        governorate_id: branch?.governorate_id ?? '',
        area_id: branch?.area_id ?? '',
    };
}

export default function BranchForm({ data, setData, errors, governorates, areas }) {
    const areasInGovernorate = data.governorate_id ? areas.filter((area) => String(area.governorate_id) === String(data.governorate_id)) : [];
    const governorate = governorates.find((option) => String(option.id) === String(data.governorate_id));
    const area = areasInGovernorate.find((option) => String(option.id) === String(data.area_id));

    function onGovernorateChange(value) {
        setData((prev) => ({ ...prev, governorate_id: value, area_id: '' }));
    }

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="building" className="h-6 w-6 text-white/70" />}
                dotClass={data.is_active ? 'bg-emerald-500' : 'bg-gray-400'}
                title={data.name.trim() || 'فرع جديد'}
                subtitle={data.phone.trim() || null}
                subtitleDir="ltr"
                chips={[governorate?.name, area?.name, data.is_active ? null : 'متوقف'].filter(Boolean)}
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

            <FormSection icon="pin" title="الموقع" columns={2}>
                <FormSelect
                    id="governorate_id"
                    label="المحافظة"
                    value={data.governorate_id}
                    onChange={onGovernorateChange}
                    options={namedOptions(governorates)}
                    placeholder="— بلا محافظة —"
                    emptyMessage="لا توجد محافظات بعد"
                    error={errors.governorate_id}
                />

                <FormSelect
                    id="area_id"
                    label="المنطقة"
                    value={data.area_id}
                    onChange={(value) => setData('area_id', value)}
                    options={namedOptions(areasInGovernorate)}
                    placeholder="— بلا منطقة —"
                    blockedMessage={data.governorate_id ? null : 'اختر محافظة أولاً'}
                    emptyMessage="لا توجد مناطق في هذه المحافظة بعد"
                    error={errors.area_id}
                />
            </FormSection>
        </div>
    );
}
