import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the governorate's own when editing,
 * otherwise blank.
 */
export function governorateFormData(governorate) {
    return { name: governorate?.name ?? '' };
}

export default function GovernorateForm({ data, setData, errors }) {
    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="map" className="h-6 w-6 text-white/70" />}
                title={data.name.trim() || 'محافظة جديدة'}
                filled={countFilled(data, ['name'])}
                total={1}
            />

            <FormSection icon="map" title="بيانات المحافظة" columns={1}>
                <FormField id="name" label="الاسم" required error={errors.name}>
                    <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>
            </FormSection>
        </div>
    );
}
