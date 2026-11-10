import { useState } from 'react';
import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';
import MeterBoxSubAreaSelect from './MeterBoxSubAreaSelect';
import { areaAfterBranchChange, areasOfBranch } from '@/lib/branchAreas';

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

export default function MeterBoxForm({ data, setData, errors, branches, canChooseBranch, canCreateSubArea, subAreas, currentBranchId }) {
    const [createdSubAreas, setCreatedSubAreas] = useState([]);

    // The branch comes first, then one of its areas (optional): the branch the Super Admin picks, or the actor's own.
    const branchId = canChooseBranch ? data.branch_id : currentBranchId;
    const availableSubAreas = [...new Map([...subAreas, ...createdSubAreas].map((item) => [String(item.id), item])).values()];
    const subAreasInArea = areasOfBranch(availableSubAreas, branchId);

    const displayName = [data.name.trim(), data.name_suffix.trim()].filter(Boolean).join(' ');
    const subArea = subAreasInArea.find((option) => String(option.id) === String(data.sub_area_id));
    const requiredFields = ['name', 'box_number', ...(canChooseBranch ? ['branch_id'] : [])];

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
                    <FormSelect
                        id="branch_id"
                        label="الفرع"
                        required
                        value={data.branch_id}
                        onChange={(value) => setData((current) => ({ ...current, branch_id: value, sub_area_id: areaAfterBranchChange(availableSubAreas, current.sub_area_id, value) }))}
                        options={namedOptions(branches)}
                        placeholder="— اختر فرعًا —"
                        emptyMessage="لا توجد فروع بعد"
                        error={errors.branch_id}
                    />
                ) : (
                    <p className="text-sm text-gray-500 sm:col-span-2">سينتمي هذا الطبلون إلى فرعك.</p>
                )}

                <MeterBoxSubAreaSelect
                    key={String(branchId ?? '')}
                    id="sub_area_id"
                    value={data.sub_area_id}
                    onChange={(value) => setData('sub_area_id', value)}
                    onCreated={(subArea) => {
                        setCreatedSubAreas((items) => [...items, { ...subArea, isNew: true }]);
                        setData('sub_area_id', String(subArea.id));
                    }}
                    subAreas={subAreasInArea}
                    branchId={branchId}
                    canCreate={canCreateSubArea}
                    blockedMessage={branchId ? null : 'اختر الفرع أولاً'}
                    error={errors.sub_area_id}
                />
            </FormSection>
        </div>
    );
}
