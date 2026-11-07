import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import TariffForm, { tariffFormData } from './TariffForm';

export default function Create({ categoryOptions }) {
    const form = useResourceForm('/tariffs', null, tariffFormData(null, categoryOptions));

    return (
        <FormPage layout={SettingsLayout} title="إنشاء تعرفة" form={form} cancelHref="/tariffs" widthClass="max-w-4xl">
            <TariffForm data={form.data} setData={form.setData} errors={form.errors} categoryOptions={categoryOptions} />
        </FormPage>
    );
}
