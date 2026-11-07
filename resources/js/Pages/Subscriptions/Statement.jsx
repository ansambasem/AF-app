import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AccountStatement from './AccountStatement';
import { StandingDiscountBadge } from './AccountSummary';
import StopStandingDiscountButton from './StopStandingDiscountButton';
import { StatementActions, StatementForms } from './StatementForms';
import SubscriptionSwitcher from './SubscriptionSwitcher';
import ArchiveNotice from './ArchiveNotice';
import { BackLink } from '@/Components/PageHeader';

/**
 * A subscription's account statement: every charge (عليه), payment and
 * discount (له), oldest first, with the balance after each line.
 */
export default function Statement(statement) {
    const { subscription, subscriptions, entries, summary, canRecordPayment, canAddCharge, canAddDiscount, canAddClearing, paymentMethods, transactionTypes } = statement;
    // The form open over the statement: 'payment', 'charge' or 'discount', or a line to correct or delete.
    const [openForm, setOpenForm] = useState(null);

    return (
        <AuthenticatedLayout
            header={
                <>
                    <div className="min-w-0">
                        <div className="mb-1 text-sm font-semibold text-gray-500">
                            <BackLink href="/subscriptions">المشتركون</BackLink>
                        </div>
                        <h2 className="text-3xl font-bold text-gray-900">كشف حساب المشترك</h2>
                        <p className="mt-1 text-sm text-gray-500">
                            {subscription.fullName} · حساب <span dir="ltr">{subscription.accountNumber}</span> · {subscription.tariffCategoryLabel}
                            {subscription.tariffSegmentName && ` (${subscription.tariffSegmentName})`}
                            {subscription.meterBoxNumber && ` · طبلون ${subscription.meterBoxNumber}`} · {subscription.branchName}
                        </p>
                        {subscription.standingDiscount && (
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <StandingDiscountBadge discount={subscription.standingDiscount} />
                                {canAddDiscount && (
                                    <StopStandingDiscountButton
                                        subscriptionId={subscription.id}
                                        subscriptionName={subscription.fullName}
                                        discountLabel={subscription.standingDiscount.segment ? `${subscription.standingDiscount.terms} · ${subscription.standingDiscount.segment}` : subscription.standingDiscount.terms}
                                    />
                                )}
                            </div>
                        )}
                    </div>
                    <StatementActions
                        canRecordPayment={canRecordPayment}
                        canRecordLatePayment={statement.canRecordLatePayment}
                        canRecordSplitPayment={statement.canRecordSplitPayment}
                        canAddCharge={canAddCharge}
                        canAddDiscount={canAddDiscount}
                        canAddClearing={canAddClearing}
                        onOpen={setOpenForm}
                    />
                </>
            }
        >
            <Head title={`كشف حساب ${subscription.fullName}`} />
            <ArchiveNotice subscription={subscription} />

            <SubscriptionSwitcher
                subscriberNumber={subscription.subscriberNumber}
                subscriptions={subscriptions}
                currentId={subscription.id}
                onSelect={(subscription) => router.visit(`/subscriptions/${subscription.id}/statement`)}
            />

            <AccountStatement
                key={subscription.id}
                subscription={subscription}
                entries={entries}
                summary={summary}
                paymentMethods={paymentMethods}
                transactionTypes={transactionTypes}
                onAction={(action, entry) => setOpenForm({ action, entry })}
                focusLineId={statement.focusLineId}
            />

            <StatementForms statement={statement} openForm={openForm} onClose={() => setOpenForm(null)} onOpen={setOpenForm} />
        </AuthenticatedLayout>
    );
}
