import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubscriptionForm, { subscriptionFormData } from './SubscriptionForm';

export default function SubscriptionModal({
    show,
    onClose,
    subscription,
    sourceSubscription = null,
    branches,
    tariffs,
    segments,
    circuitBreakers,
    subAreas,
    canChooseBranch,
    currentBranchAreaId,
    currentBranchAreaName,
    canEditMinimumCharge,
    canEditKilowattPrice,
    visitOptions,
}) {
    const form = useResourceForm('/subscriptions', subscription, subscriptionFormData(subscription, sourceSubscription));

    return (
        <FormModal
            show={show}
            onClose={onClose}
            form={form}
            title={form.isEdit ? 'تعديل المشترك' : sourceSubscription ? 'إضافة اشتراك' : 'إنشاء مشترك'}
            icon="user"
            maxWidth="5xl"
            visitOptions={visitOptions}
            bodyClassName="bg-gray-50"
        >
            <SubscriptionForm
                data={form.data}
                setData={form.setData}
                errors={form.errors}
                clearErrors={form.clearErrors}
                branches={branches}
                meterBox={subscription?.meter_box ?? null}
                tariffs={tariffs}
                segments={segments}
                circuitBreakers={circuitBreakers}
                subAreas={subAreas}
                canChooseBranch={canChooseBranch}
                currentBranchAreaId={currentBranchAreaId}
                currentBranchAreaName={currentBranchAreaName}
                canEditMinimumCharge={canEditMinimumCharge}
                canEditKilowattPrice={canEditKilowattPrice}
                sharedPersonalDetails={Boolean(sourceSubscription)}
                isEdit={form.isEdit}
                subscriptionCount={subscription?.subscriptionCount ?? 1}
            />
        </FormModal>
    );
}
