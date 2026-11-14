import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import CircuitBreakerForm, { circuitBreakerFormData } from './CircuitBreakerForm';

export default function Edit({ circuitBreaker }) {
    const form = useResourceForm('/circuit-breakers', circuitBreaker, circuitBreakerFormData(circuitBreaker));

    return (
        <FormPage layout={SettingsLayout} title="تعديل القاطع" form={form} cancelHref="/circuit-breakers" widthClass="max-w-4xl">
            <CircuitBreakerForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormPage>
    );
}
