import FormField from '@/Components/Form/FormField';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import FormSection from '@/Components/Form/FormSection';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

/**
 * The form's starting values: the circuit breaker's own when editing,
 * otherwise blank.
 */
export function circuitBreakerFormData(circuitBreaker) {
    return circuitBreaker ? { ampere: circuitBreaker.ampere, minimum_payment: circuitBreaker.minimum_payment } : { ampere: '', minimum_payment: '' };
}

export default function CircuitBreakerForm({ data, setData, errors }) {
    const ampere = String(data.ampere).trim();
    const minimum = String(data.minimum_payment).trim();

    return (
        <div className="space-y-4">
            <FormPreview
                avatar={<Icon name="bolt" className="h-6 w-6 text-white/70" />}
                title={ampere === '' ? 'قاطع جديد' : `قاطع ${ampere} أمبير`}
                subtitle={minimum === '' ? null : `الحد الأدنى للدفع ${minimum} شيكل`}
                filled={countFilled(data, ['ampere', 'minimum_payment'])}
                total={2}
            />

            <FormSection icon="bolt" title="بيانات القاطع" columns={2}>
                <FormField id="ampere" label="الأمبير" required error={errors.ampere}>
                    <TextInput type="number" min={2} step={1} required className="block w-full" value={data.ampere} onChange={(event) => setData('ampere', event.target.value)} />
                </FormField>

                <FormField id="minimum_payment" label="الحد الأدنى للدفع (شيكل)" required error={errors.minimum_payment}>
                    <TextInput
                        type="number"
                        min={0}
                        step="0.01"
                        required
                        className="block w-full"
                        value={data.minimum_payment}
                        onChange={(event) => setData('minimum_payment', event.target.value)}
                    />
                </FormField>
            </FormSection>
        </div>
    );
}
