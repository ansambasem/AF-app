import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the sub-area's own when editing, otherwise
 * blank — optionally already placed in `defaultAreaId`.
 */
export function subAreaFormData(subArea, defaultAreaId = '') {
    return subArea ? { name: subArea.name, area_id: subArea.area_id ?? '' } : { name: '', area_id: defaultAreaId };
}

/**
 * `allowNoArea` offers "— no area —"; only a super admin may leave a
 * sub-area outside any area, everyone else places it in their branch's.
 */
export default function SubAreaForm({ data, setData, errors, areas, allowNoArea = true }) {
    const area = areas.find((option) => String(option.id) === String(data.area_id));

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="pin" className="h-6 w-6 text-white/70" />}
                title={data.name.trim() || 'منطقة 2 جديدة'}
                chips={area ? [area.name] : []}
                filled={countFilled(data, ['name'])}
                total={1}
            />

            <FormSection icon="pin" title="بيانات منطقة 2" columns={2}>
                <FormField id="name" label="الاسم" required error={errors.name}>
                    <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>

                <FormSelect
                    id="area_id"
                    label="المنطقة"
                    value={data.area_id}
                    onChange={(value) => setData('area_id', value)}
                    options={namedOptions(areas)}
                    placeholder={allowNoArea ? '— بلا منطقة —' : '— اختر منطقة —'}
                    emptyMessage="لا توجد مناطق بعد"
                    error={errors.area_id}
                />
            </FormSection>
        </div>
    );
}
