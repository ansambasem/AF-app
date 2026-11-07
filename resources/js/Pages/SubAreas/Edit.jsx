import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function Edit({ subArea, areas, allowNoArea }) {
    const form = useResourceForm('/sub-areas', subArea, subAreaFormData(subArea));

    return (
        <FormPage title="تعديل منطقة 2" form={form} cancelHref="/governorates" widthClass="max-w-4xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} areas={areas} allowNoArea={allowNoArea} />
        </FormPage>
    );
}
