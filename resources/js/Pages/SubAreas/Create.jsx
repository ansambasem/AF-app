import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function Create({ areas, allowNoArea }) {
    // Someone who must pick an area and has only their branch's starts with it chosen.
    const form = useResourceForm('/sub-areas', null, subAreaFormData(null, !allowNoArea && areas.length === 1 ? areas[0].id : ''));

    return (
        <FormPage title="إنشاء منطقة 2" form={form} cancelHref="/governorates" widthClass="max-w-4xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} areas={areas} allowNoArea={allowNoArea} />
        </FormPage>
    );
}
