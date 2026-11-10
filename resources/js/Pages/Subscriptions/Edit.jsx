import FormPage from '@/Components/FormPage';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubscriptionForm, { subscriptionFormData } from './SubscriptionForm';

export default function Edit({
    subscription,
    branches,
    tariffs,
    segments,
    circuitBreakers,
    subAreas,
    canChooseBranch,
    currentBranchId,
    canEditMinimumCharge,
    canEditKilowattPrice,
}) {
    const form = useResourceForm('/subscriptions', subscription, subscriptionFormData(subscription));

    return (
        <FormPage title="تعديل المشترك" form={form} cancelHref="/subscriptions" widthClass="max-w-6xl">
            <SubscriptionForm
                data={form.data}
                setData={form.setData}
                errors={form.errors}
                clearErrors={form.clearErrors}
                branches={branches}
                meterBox={subscription.meter_box ?? null}
                tariffs={tariffs}
                segments={segments}
                circuitBreakers={circuitBreakers}
                subAreas={subAreas}
                canChooseBranch={canChooseBranch}
                currentBranchId={currentBranchId}
                canEditMinimumCharge={canEditMinimumCharge}
                canEditKilowattPrice={canEditKilowattPrice}
                subscriptionCount={subscription.subscriptionCount}
                isEdit
            />
        </FormPage>
    );
}
