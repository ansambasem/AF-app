import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function Edit({ subArea, branches }) {
    const form = useResourceForm('/sub-areas', subArea, subAreaFormData(subArea));

    return (
        <FormPage title="تعديل المنطقة" form={form} cancelHref={`/branch-areas?selected=${subArea.branch_id ?? ''}`} widthClass="max-w-4xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} branches={branches} canChooseBranch={false} />
        </FormPage>
    );
}
