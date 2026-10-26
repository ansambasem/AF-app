<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;

class SubscriptionPolicy
{
    /**
     * Whether the user's ticked permissions let them work with subscriptions
     * at all, before branch scoping is considered.
     */
    private function hasBaseAccess(User $user): bool
    {
        return $user->canViewAllSubscriberAccounts() || $user->hasAnyPermission(PermissionKey::ViewSubscriptions, PermissionKey::CreateSubscriptions, PermissionKey::UpdateSubscriptions);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasBaseAccess($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Subscription $subscription): bool
    {
        if ($subscription->archived_at !== null && ! $this->viewArchived($user)) {
            return false;
        }

        if ($user->canViewAllSubscriberAccounts()) {
            return $user->auditIncludesBranch($subscription->branch_id);
        }

        return $this->hasBaseAccess($user) && $subscription->branch_id === $user->workBranchId();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::CreateSubscriptions);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Subscription $subscription): bool
    {
        if ($subscription->archived_at !== null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission(PermissionKey::UpdateSubscriptions) && $subscription->branch_id === $user->workBranchId();
    }

    /** The latest sheet's initial baseline uses the previous-reading correction permission. */
    public function adjustInitialReading(User $user, Subscription $subscription, ?CarbonInterface $weekStart = null): bool
    {
        return $subscription->archived_at === null
            && $user->hasPermission(PermissionKey::CorrectLastMeterReadings)
            && ($user->isSuperAdmin() || $subscription->branch_id === $user->workBranchId())
            && ($weekStart === null || $weekStart->equalTo(MeterReading::latestEndedWeekStart($subscription->branch_id)));
    }

    /**
     * Changing one field of many subscriptions at once from the list (see
     * SubscriptionBulkChangeController): the status takes "Edit Subscriptions",
     * the minimum charge also its own permission. Which subscriptions it
     * reaches is limited to the user's branch by the query itself.
     */
    public function bulkUpdate(User $user, string $field): bool
    {
        if (! $user->hasPermission(PermissionKey::BulkUpdateSubscriptions)) {
            return false;
        }

        return match ($field) {
            'status', 'meter_box_id' => $user->hasPermission(PermissionKey::UpdateSubscriptions),
            'minimum_charge' => $user->hasPermission(PermissionKey::UpdateSubscriptions)
                && $user->hasPermission(PermissionKey::UpdateSubscriptionMinimumCharge),
            default => false,
        };
    }

    /**
     * The quick payments page (/payments) is for anyone who may record
     * payments at all — "Add Payments" and at least one way to be paid;
     * which subscriptions they find there is limited to their branch by
     * the query, and each payment by recordPayment().
     */
    public function recordAnyPayment(User $user): bool
    {
        return $user->hasPermission(PermissionKey::RecordCollections) && $user->allowedPaymentMethods() !== [];
    }

    /**
     * A split payment is one bank transfer shared between subscriptions,
     * so it takes the bank transfer permission too.
     */
    public function recordSplitPayment(User $user): bool
    {
        return $this->recordAnyPayment($user) && $user->hasPermission(PermissionKey::RecordTransferPayments);
    }

    public function recordLatePayment(User $user, Subscription $subscription): bool
    {
        return false;
    }

    public function viewOwnCashBoxes(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::RecordCollections, PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings);
    }

    /**
     * Recording a payment takes "Add Payments" and at least one way to be
     * paid (each way is its own permission, checked on the payment itself:
     * StoreSubscriptionPaymentRequest::allowedMethodRule()), for a
     * subscription of the user's own branch (any branch for the Super Admin).
     */
    public function recordPayment(User $user, Subscription $subscription): bool
    {
        return $this->recordAnyPayment($user) && $this->inBranch($user, $subscription)
            && ($subscription->archived_at === null || $this->viewArchived($user));
    }

    /**
     * Whether the user may add any of a charge, a discount or a clearing to
     * the subscription, so the account's "add" menu shows; each one is
     * checked by its own ability below.
     */
    public function adjustBalance(User $user, Subscription $subscription): bool
    {
        return $this->addCharge($user, $subscription) || $this->addDiscount($user, $subscription) || $this->addClearing($user, $subscription);
    }

    /**
     * Adding a charge (a penalty or a fee) by hand takes its own permission,
     * for a subscription of the user's own branch (any branch for the Super Admin).
     */
    public function addCharge(User $user, Subscription $subscription): bool
    {
        return $subscription->archived_at === null && $user->hasPermission(PermissionKey::AddCharges) && $this->inBranch($user, $subscription);
    }

    /**
     * Adding a discount — once, or a standing one on every weekly reading —
     * or stopping a standing one takes its own permission, for a
     * subscription of the user's own branch (any branch for the Super Admin).
     */
    public function addDiscount(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(PermissionKey::AddDiscounts) && $this->inBranch($user, $subscription)
            && ($subscription->archived_at === null || $this->viewArchived($user));
    }

    /**
     * Adding a clearing takes its own permission, for a subscription of the
     * user's own branch (any branch for the Super Admin).
     */
    public function addClearing(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(PermissionKey::AddClearings) && $this->inBranch($user, $subscription)
            && ($subscription->archived_at === null || $this->viewArchived($user));
    }

    private function inBranch(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin() || $subscription->branch_id === $user->workBranchId();
    }

    /**
     * Deleting takes its own permission, for a subscription of the user's
     * own branch (any branch for the Super Admin).
     */
    public function delete(User $user, Subscription $subscription): bool
    {
        return $subscription->archived_at === null && empty($subscription->archive_history) && $user->hasPermission(PermissionKey::DeleteSubscriptions)
            && ($user->isSuperAdmin() || $subscription->branch_id === $user->workBranchId());
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Subscription $subscription): bool
    {
        return $this->viewArchived($user)
            && $user->hasPermission(PermissionKey::RestoreArchivedSubscriptions)
            && $this->inBranch($user, $subscription);
    }

    public function viewArchived(User $user): bool
    {
        return $this->hasBaseAccess($user) && $user->hasPermission(PermissionKey::ViewArchivedSubscriptions);
    }

    public function archive(User $user, Subscription $subscription): bool
    {
        return $this->hasBaseAccess($user)
            && $user->hasPermission(PermissionKey::ArchiveSubscriptions)
            && $this->inBranch($user, $subscription);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
