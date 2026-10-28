import { useState } from 'react';
import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';
import MeterBoxSubAreaSelect from './MeterBoxSubAreaSelect';

/**
 * The form's starting values: the meter box's own when editing,
 * otherwise blank.
 */
export function meterBoxFormData(meterBox) {
    return {
        name: meterBox?.name ?? '',
        name_suffix: meterBox?.name_suffix ?? '',
        box_number: meterBox?.box_number ?? '',
        branch_id: meterBox?.branch_id ?? '',
        sub_area_id: meterBox?.sub_area_id ?? '',
    };
}

export default function MeterBoxForm({ data, setData, errors, branches, canChooseBranch, canCreateSubArea, governorates, areas, subAreas, currentBranchAreaId }) {
    const selectedBranch = canChooseBranch ? branches.find((branch) => String(branch.id) === String(data.branch_id)) : null;

    const [governorateId, setGovernorateId] = useState(selectedBranch?.governorate_id ?? '');
    const [areaId, setAreaId] = useState(selectedBranch?.area_id ?? '');
    const [createdSubAreas, setCreatedSubAreas] = useState([]);

    // The area whose sub-areas ("منطقة 2") are selectable: the cascading
    // selected branch's area for a Super Admin, or the actor's own
    // (fixed) branch's area for everyone else.
    const effectiveAreaId = canChooseBranch ? selectedBranch?.area_id : currentBranchAreaId;

    const areasInGovernorate = governorateId ? areas.filter((area) => String(area.governorate_id) === String(governorateId)) : [];

    const branchesInArea = areaId ? branches.filter((branch) => String(branch.area_id) === String(areaId)) : [];

    const availableSubAreas = [...new Map([...subAreas, ...createdSubAreas].map((item) => [String(item.id), item])).values()];
    const subAreasInArea = effectiveAreaId ? availableSubAreas.filter((subArea) => String(subArea.area_id) === String(effectiveAreaId)) : [];

    const displayName = [data.name.trim(), data.name_suffix.trim()].filter(Boolean).join(' ');
    const subArea = subAreasInArea.find((option) => String(option.id) === String(data.sub_area_id));
    const requiredFields = ['name', 'box_number', ...(canChooseBranch ? ['branch_id'] : [])];

    function onGovernorateChange(value) {
        setGovernorateId(value);
        setAreaId('');
        setData((current) => ({ ...current, branch_id: '', sub_area_id: '' }));
    }

    function onAreaChange(value) {
        setAreaId(value);
        setData((current) => ({ ...current, branch_id: '', sub_area_id: '' }));
    }

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="grid" className="h-6 w-6 text-white/70" />}
                title={displayName || 'طبلون جديد'}
                subtitle={data.box_number.trim() ? `رقم ${data.box_number.trim()}` : null}
                subtitleDir="auto"
                chips={[subArea?.name].filter(Boolean)}
                filled={countFilled(data, requiredFields)}
                total={requiredFields.length}
            />

            <FormSection icon="grid" title="بيانات الطبلون" columns={2}>
                <div className="flex items-start gap-3 sm:col-span-2">
                    <FormField id="name" label="اسم الطبلون" required error={errors.name} span="min-w-0 flex-1">
                        <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                    </FormField>
                    <FormField id="name_suffix" label="لاحقة (اختياري)" error={errors.name_suffix} span="w-28 shrink-0">
                        <TextInput
                            name="name_suffix"
                            maxLength={50}
                            placeholder="2 / 2A"
                            dir="auto"
                            className="block w-full text-center"
                            value={data.name_suffix}
                            onChange={(e) => setData('name_suffix', e.target.value)}
                        />
                    </FormField>
                </div>

                <FormField
                    id="box_number"
                    label="رقم الطبلون"
                    required
                    error={errors.box_number}
                    hint={
                        data.name.trim() ? (
                            <>
                                يظهر باسم: <bdi>{displayName}{data.box_number && ` - (${data.box_number})`}</bdi>
                            </>
                        ) : undefined
                    }
                >
                    <TextInput dir="ltr" className="block w-full" value={data.box_number} onChange={(e) => setData('box_number', e.target.value)} />
                </FormField>
            </FormSection>

            <FormSection
                icon="pin"
                title="الموقع"
               
                columns={2}
            >
                {canChooseBranch ? (
                    <>
                        <FormSelect
                            id="governorate_id"
                            label="المحافظة"
                            value={governorateId}
                            onChange={onGovernorateChange}
                            options={namedOptions(governorates)}
                            placeholder="— اختر محافظة —"
                            emptyMessage="لا توجد محافظات بعد"
                        />
                        <FormSelect
                            id="area_id"
                            label="المنطقة"
                            value={areaId}
                            onChange={onAreaChange}
                            options={namedOptions(areasInGovernorate)}
                            placeholder="— اختر منطقة —"
                            blockedMessage={governorateId ? null : 'اختر محافظة أولاً'}
                            emptyMessage="لا توجد مناطق في هذه المحافظة بعد"
                        />
                        <FormSelect
                            id="branch_id"
                            label="الفرع"
                            required
                            value={data.branch_id}
                            onChange={(value) => setData((current) => ({ ...current, branch_id: value, sub_area_id: '' }))}
                            options={namedOptions(branchesInArea)}
                            placeholder="— اختر فرعًا —"
                            blockedMessage={areaId ? null : 'اختر منطقة أولاً'}
                            emptyMessage="لا توجد فروع في هذه المنطقة بعد"
                            error={errors.branch_id}
                        />
                    </>
                ) : (
                    <p className="text-sm text-gray-500 sm:col-span-2">سينتمي هذا الطبلون إلى فرعك.</p>
                )}

                <MeterBoxSubAreaSelect
                    key={`${data.branch_id}-${effectiveAreaId}`}
                    id="sub_area_id"
                    value={data.sub_area_id}
                    onChange={(value) => setData('sub_area_id', value)}
                    onCreated={(subArea) => {
                        setCreatedSubAreas((items) => [...items, { ...subArea, isNew: true }]);
                        setData('sub_area_id', String(subArea.id));
                    }}
                    subAreas={subAreasInArea}
                    areaId={effectiveAreaId}
                    canCreate={canCreateSubArea}
                    blockedMessage={effectiveAreaId ? null : canChooseBranch && !selectedBranch ? 'اختر الفرع أولاً' : 'الفرع غير مرتبط بمنطقة بعد'}
                    error={errors.sub_area_id}
                />
            </FormSection>
        </div>
    );
}
