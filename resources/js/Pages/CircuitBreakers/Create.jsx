import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import CircuitBreakerForm, { circuitBreakerFormData } from './CircuitBreakerForm';

export default function Create() {
    const form = useResourceForm('/circuit-breakers', null, circuitBreakerFormData(null));

    return (
        <FormPage layout={SettingsLayout} title="إنشاء قاطع" form={form} cancelHref="/circuit-breakers" widthClass="max-w-4xl">
            <CircuitBreakerForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormPage>
    );
}
