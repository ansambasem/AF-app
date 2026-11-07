import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the tariff's own when editing, otherwise
 * blank with the first category preselected.
 */
export function tariffFormData(tariff, categoryOptions) {
    return tariff ? { category: tariff.category, rate: tariff.rate } : { category: categoryOptions[0]?.value ?? '', rate: '' };
}

export default function TariffForm({ data, setData, errors, categoryOptions }) {
    const category = categoryOptions.find((option) => option.value === data.category);

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="bolt" className="h-6 w-6 text-white/70" />}
                title={category?.label ?? 'تعرفة جديدة'}
                subtitle={String(data.rate).trim() === '' ? null : `${data.rate} شيكل لكل كيلو واط`}
                filled={countFilled(data, ['category', 'rate'])}
                total={2}
            />

            <FormSection icon="bolt" title="سعر الكيلو" columns={2}>
                <FormField id="category" label="الفئة" required error={errors.category}>
                    <select className="block w-full" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                        {categoryOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </FormField>

                <FormField id="rate" label="السعر (شيكل)" required error={errors.rate}>
                    <TextInput type="number" step="0.01" className="block w-full" value={data.rate} onChange={(e) => setData('rate', e.target.value)} />
                </FormField>
            </FormSection>
        </div>
    );
}
