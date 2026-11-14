import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import BranchForm, { branchFormData } from './BranchForm';

export default function Edit({ branch }) {
    const form = useResourceForm('/branches', branch, branchFormData(branch));

    return (
        <FormPage layout={SettingsLayout} title="تعديل الفرع" form={form} cancelHref="/branches" widthClass="max-w-4xl">
            <BranchForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormPage>
    );
}
