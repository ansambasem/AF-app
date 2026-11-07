import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import AreaForm, { areaFormData } from './AreaForm';

export default function Edit({ area, governorates }) {
    const form = useResourceForm('/areas', area, areaFormData(area));

    return (
        <FormPage title="تعديل المنطقة" form={form} cancelHref="/governorates" widthClass="max-w-4xl">
            <AreaForm data={form.data} setData={form.setData} errors={form.errors} governorates={governorates} />
        </FormPage>
    );
}
