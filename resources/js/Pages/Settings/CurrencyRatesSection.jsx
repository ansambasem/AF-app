import { useForm } from '@inertiajs/react';
import FormField from '@/Components/Form/FormField';
import FormSection from '@/Components/Form/FormSection';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import Icon from '@/Components/Icon';
import { formatMoney } from '@/lib/format';
import StatusPill from '@/Components/DataTable/StatusPill';

function CurrencyEditor({ currency }) {
    const form = useForm({ rate: currency.rate ?? '' });

    function save(event) {
        event.preventDefault();
        form.put(currency.updateUrl, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    }

    return (
        <form onSubmit={save} aria-labelledby={`currency-${currency.code}`} className="flex min-w-0 flex-col gap-5 rounded-row border border-gray-200 bg-surface p-5">
            <div className="flex items-center gap-3">
                <bdi className="flex h-12 w-16 shrink-0 items-center justify-center rounded-control bg-gray-50 font-display text-sm font-semibold text-gray-700">{currency.code}</bdi>
                <div className="min-w-0">
                    <h3 id={`currency-${currency.code}`} className="font-bold text-gray-900">{currency.label}</h3>
                </div>
            </div>
            <FormField id={`rate-${currency.code}`} label="سعر الصرف بالشيكل" required error={form.errors.rate}>
                <TextInput name="rate" type="number" min="0.0001" max="10000" step="0.0001" required dir="ltr" className="w-full font-display tabular-nums" value={form.data.rate} onChange={(event) => form.setData('rate', event.target.value)} />
            </FormField>
            <div className="mt-auto flex flex-col items-start gap-4 border-t border-gray-100 pt-4">
                {Number(form.data.rate) > 0 ? (
                    <p className="text-xs leading-relaxed text-gray-500">
                        10 {currency.label} = <bdi className="font-display font-semibold text-gray-700">{formatMoney(Number(form.data.rate) * 10)}</bdi> شيكل
                    </p>
                ) : (
                    <StatusPill tone="amber" label="غير مفعّلة للدفع" />
                )}
                <PrimaryButton type="submit" className="self-end" disabled={form.processing || !form.isDirty}>
                    <Icon name="check" className="h-4 w-4" />
                    {form.processing ? 'جارٍ الحفظ…' : 'حفظ السعر'}
                </PrimaryButton>
            </div>
        </form>
    );
}

export default function CurrencyRatesSection({ currencies }) {
    const baseCurrency = currencies.find((currency) => currency.code === 'ILS');

    return (
        <FormSection icon="currency" title="العملات وأسعار الصرف" info="تُطبّق الأسعار تلقائيًا عند تسجيل الدفعات الجديدة. تبقى الدفعات السابقة بسعر الصرف المسجّل وقت الدفع." columns={1}>
            {baseCurrency && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-gray-100 bg-gray-50 px-4 py-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="font-semibold text-gray-900">{baseCurrency.label} <bdi className="font-display text-sm">{baseCurrency.code}</bdi></span>
                        <span className="text-xs text-gray-500">عملة الحساب الأساسية</span>
                    </div>
                    <span className="inline-flex items-center gap-2 text-xs font-medium text-gray-600">
                        <Icon name="lock" className="h-4 w-4" />
                        السعر ثابت: 1 شيكل
                    </span>
                </div>
            )}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {currencies.filter((currency) => currency.code !== 'ILS').map((currency) => (
                    <CurrencyEditor key={`${currency.id}-${currency.rate}`} currency={currency} />
                ))}
            </div>
        </FormSection>
    );
}
