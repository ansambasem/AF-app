import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function Create({ branches, canChooseBranch, currentBranchId }) {
    const form = useResourceForm('/sub-areas', null, subAreaFormData(null, currentBranchId ?? ''));

    return (
        <FormPage title="إنشاء منطقة" form={form} cancelHref="/branch-areas" widthClass="max-w-4xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} branches={branches} canChooseBranch={canChooseBranch} />
        </FormPage>
    );
}
