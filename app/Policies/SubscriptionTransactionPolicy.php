<?php

namespace App\Policies;

use App\Enums\AuditAction;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\SubscriptionTransaction;
use App\Models\User;

/**
 * Access to the subscriptions' account lines as a whole — the financial log
 * and the branch performance pages built on them — and to correcting or
 * deleting one. Lines are written by the subscription, payment and reading
 * flows, each under its own permission.
 */
class SubscriptionTransactionPolicy
{
    /**
     * The financial log takes the "View Collections" permission (the
     * Super Admin holds every permission).
     */
    public function viewAny(User $user): bool
    {
        return $user->canAuditAction(AuditAction::ViewLedger) || ($user->hasPermission(PermissionKey::ViewCollections));
    }

    /**
     * The branch performance pages take their own "View Branch
     * Performance" permission.
     */
    public function viewBranchPerformance(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ViewBranchPerformance);
    }

    /**
     * One branch's figures: any branch for the Super Admin, otherwise only
     * the user's own.
     */
    public function viewForBranch(User $user, Branch $branch): bool
    {
        return $this->viewBranchPerformance($user) && ($user->isSuperAdmin() || $branch->id === $user->workBranchId());
    }

    /**
     * The debt aging report (أعمار الديون) takes its own permission.
     */
    public function viewDebtAging(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ViewDebtAging);
    }

    /**
     * The audit log (سجل التدقيق) — who changed or deleted which line —
     * takes its own permission.
     */
    public function viewTransactionAudit(User $user): bool
    {
        return $user->canAuditAction(AuditAction::ViewHistory) || ($user->hasPermission(PermissionKey::ViewTransactionAudit));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Correcting a line takes the "Edit Transactions" permission, for a
     * subscription of the user's own branch (any branch for the Super Admin),
     * and only while the line can still be corrected: never once a closing
     * has been sent for review or approved with it in.
     */
    public function update(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return $user->hasPermission(PermissionKey::CorrectTransactions) && $this->mayChange($user, $subscriptionTransaction);
    }

    /** Amend payment details without changing its financial meaning. */
    public function amend(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return $user->hasPermission(PermissionKey::AmendTransactionDetails)
            && $subscriptionTransaction->isAmendable()
            && $this->inBranchOf($user, $subscriptionTransaction);
    }

    /**
     * Cancellation and refunding require their own permissions. Permanent
     * deletion never implies either, including through the legacy endpoint.
     * A payment in a closed box or submitted or approved closing is immutable.
     */
    public function delete(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        $canDelete = $user->hasPermission($subscriptionTransaction->isPayment()
            ? PermissionKey::RefundPayments
            : PermissionKey::DeleteTransactions);

        return $canDelete
            && $subscriptionTransaction->isCancellable()
            && ! $subscriptionTransaction->isInClosedDay()
            && $this->inBranchOf($user, $subscriptionTransaction);
    }

    private function mayChange(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return $subscriptionTransaction->isCorrectable()
            && ! $subscriptionTransaction->isInClosedDay()
            && $this->inBranchOf($user, $subscriptionTransaction);
    }

    private function inBranchOf(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return $user->isSuperAdmin() || $subscriptionTransaction->subscription->branch_id === $user->workBranchId();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }

    /**
     * Removing a line from the statement, with a retained audit record, takes its own
     * "Permanently Delete Transactions" permission, in the user's own
     * branch. Only the last line of the statement, and not one a closing
     * has counted.
     */
    public function forceDelete(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return $user->hasPermission(PermissionKey::ForceDeleteTransactions)
            && $subscriptionTransaction->isErasable()
            && $this->inBranchOf($user, $subscriptionTransaction);
    }
}
