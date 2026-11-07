import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import BranchForm, { branchFormData } from './BranchForm';

export default function Create({ governorates, areas }) {
    const form = useResourceForm('/branches', null, branchFormData(null));

    return (
        <FormPage layout={SettingsLayout} title="إنشاء فرع" form={form} cancelHref="/branches" widthClass="max-w-4xl">
            <BranchForm data={form.data} setData={form.setData} errors={form.errors} governorates={governorates} areas={areas} />
        </FormPage>
    );
}
