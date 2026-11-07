import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import GovernorateForm, { governorateFormData } from './GovernorateForm';

export default function Edit({ governorate }) {
    const form = useResourceForm('/governorates', governorate, governorateFormData(governorate));

    return (
        <FormPage layout={SettingsLayout} title="تعديل المحافظة" form={form} cancelHref="/governorates">
            <GovernorateForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormPage>
    );
}
