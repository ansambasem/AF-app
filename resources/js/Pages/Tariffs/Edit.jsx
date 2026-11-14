import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import TariffForm, { tariffFormData } from './TariffForm';

export default function Edit({ tariff, categoryOptions }) {
    const form = useResourceForm('/tariffs', tariff, tariffFormData(tariff, categoryOptions));

    return (
        <FormPage layout={SettingsLayout} title="تعديل التعرفة" form={form} cancelHref="/tariffs" widthClass="max-w-4xl">
            <TariffForm data={form.data} setData={form.setData} errors={form.errors} categoryOptions={categoryOptions} />
        </FormPage>
    );
}
