import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the area's own when editing, otherwise
 * blank — optionally already placed in `defaultGovernorateId`.
 */
export function areaFormData(area, defaultGovernorateId = '') {
    return area ? { name: area.name, governorate_id: area.governorate_id ?? '' } : { name: '', governorate_id: defaultGovernorateId };
}

export default function AreaForm({ data, setData, errors, governorates }) {
    const governorate = governorates.find((option) => String(option.id) === String(data.governorate_id));

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="map" className="h-6 w-6 text-white/70" />}
                title={data.name.trim() || 'منطقة جديدة'}
                chips={governorate ? [governorate.name] : []}
                filled={countFilled(data, ['name'])}
                total={1}
            />

            <FormSection icon="map" title="بيانات المنطقة" columns={2}>
                <FormField id="name" label="الاسم" required error={errors.name}>
                    <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>

                <FormSelect
                    id="governorate_id"
                    label="المحافظة"
                    value={data.governorate_id}
                    onChange={(value) => setData('governorate_id', value)}
                    options={namedOptions(governorates)}
                    placeholder="— بلا محافظة —"
                    emptyMessage="لا توجد محافظات بعد"
                    error={errors.governorate_id}
                />
            </FormSection>
        </div>
    );
}
