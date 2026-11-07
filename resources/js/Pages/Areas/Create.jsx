import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import AreaForm, { areaFormData } from './AreaForm';

export default function Create({ governorates }) {
    const form = useResourceForm('/areas', null, areaFormData(null));

    return (
        <FormPage title="إنشاء منطقة" form={form} cancelHref="/governorates" widthClass="max-w-4xl">
            <AreaForm data={form.data} setData={form.setData} errors={form.errors} governorates={governorates} />
        </FormPage>
    );
}
