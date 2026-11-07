import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import CircuitBreakerForm, { circuitBreakerFormData } from './CircuitBreakerForm';

export default function CircuitBreakerModal({ show, onClose, circuitBreaker }) {
    const form = useResourceForm('/circuit-breakers', circuitBreaker, circuitBreakerFormData(circuitBreaker));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل القاطع' : 'إنشاء قاطع'} icon="currency" maxWidth="3xl">
            <CircuitBreakerForm data={form.data} setData={form.setData} errors={form.errors} />
        </FormModal>
    );
}
