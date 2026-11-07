import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import GovernorateForm, { governorateFormData } from './GovernorateForm';

export default function Create() {
    const form = useResourceForm('/governorates', null, governorateFormData(null));

    return (
        <FormPage layout={SettingsLayout} title="إنشاء محافظة" form={form} cancelHref="/governorates">
            <GovernorateForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormPage>
    );
}
