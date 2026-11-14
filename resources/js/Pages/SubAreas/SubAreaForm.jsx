import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSelect, { namedOptions } from '@/Components/Form/FormSelect';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/** The form's starting values: the area's own when editing, otherwise blank in `defaultBranchId`. */
export function subAreaFormData(subArea, defaultBranchId = '') {
    return subArea ? { name: subArea.name, branch_id: subArea.branch_id ?? '' } : { name: '', branch_id: defaultBranchId };
}

/**
 * An area of a branch: its name, and the one branch it belongs to. Only the
 * Super Admin picks the branch (`canChooseBranch`); anyone else's area is
 * their own branch's.
 */
export default function SubAreaForm({ data, setData, errors, branches = [], canChooseBranch = false }) {
    const branch = branches.find((option) => String(option.id) === String(data.branch_id));

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="pin" className="h-6 w-6 text-white/70" />}
                title={data.name.trim() || 'منطقة جديدة'}
                chips={branch ? [branch.name] : []}
                filled={countFilled(data, ['name', 'branch_id'])}
                total={2}
            />

            <FormSection icon="pin" title="بيانات المنطقة" columns={2}>
                <FormField id="name" label="اسم المنطقة" required error={errors.name}>
                    <TextInput className="block w-full" value={data.name} autoFocus onChange={(e) => setData('name', e.target.value)} />
                </FormField>

                {canChooseBranch ? (
                    <FormSelect
                        id="branch_id"
                        label="الفرع"
                        required
                        value={data.branch_id}
                        onChange={(value) => setData('branch_id', value)}
                        options={namedOptions(branches)}
                        placeholder="— اختر الفرع —"
                        emptyMessage="لا توجد فروع بعد"
                        error={errors.branch_id}
                    />
                ) : (
                    <p className="self-end text-sm text-gray-500">تتبع هذه المنطقة فرعك.{errors.branch_id && <span className="block text-brand-600">{errors.branch_id}</span>}</p>
                )}
            </FormSection>
        </div>
    );
}
