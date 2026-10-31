<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\User;
use Carbon\CarbonInterface;

class MeterReadingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canRecord($user) || $user->hasAnyPermission(PermissionKey::ViewMeterReadings, PermissionKey::ApproveMeterReadings);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MeterReading $meterReading): bool
    {
        return $this->viewAny($user) && ($user->isSuperAdmin() || $meterReading->branch_id === $user->workBranchId());
    }

    /**
     * Determine whether the user can create models — for `$weekStart`,
     * when given, which must be a week still open to them.
     */
    public function create(User $user, ?CarbonInterface $weekStart = null, ?int $branchId = null): bool
    {
        return $this->canRecord($user)
            && $this->entryIsOpenFor($user)
            && ($weekStart === null || $this->weekIsOpenFor($weekStart, $branchId ?? $user->workBranchId()));
    }

    /**
     * A reading stays correctable while its week is still the latest one,
     * and only within the actor's own branch — even while the entry window
     * is closed, which only stops new readings. Correcting an approved one
     * sends it back for approval (MeterReading::correct()).
     */
    public function update(User $user, MeterReading $meterReading): bool
    {
        if ($meterReading->subscription->archived_at !== null || ! $user->hasPermission(PermissionKey::CorrectMeterReadings)) {
            return false;
        }

        if (! MeterReading::weekStartFor($meterReading->week_start, $meterReading->branch_id)->equalTo(MeterReading::latestEndedWeekStart($meterReading->branch_id))) {
            return false;
        }

        return $user->isSuperAdmin() || $meterReading->branch_id === $user->workBranchId();
    }

    /**
     * The reading the latest week starts from — the one of the week before
     * it, shown as the sheet's last reading — stays editable with its own
     * permission, in the actor's own branch (any branch for the Super Admin).
     * The week limit applies to everyone. The week after it is recalculated
     * from it (MeterReadingController::update()).
     */
    public function updateAsLastReading(User $user, MeterReading $meterReading): bool
    {
        if ($meterReading->subscription->archived_at !== null || ! $user->hasPermission(PermissionKey::CorrectLastMeterReadings)) {
            return false;
        }

        return ($user->isSuperAdmin() || $meterReading->branch_id === $user->workBranchId())
            && MeterReading::weekStartFor($meterReading->week_start, $meterReading->branch_id)->equalTo($this->lastWeekStart($meterReading->branch_id));
    }

    public function adjustLastReading(User $user, MeterReading $meterReading): bool
    {
        if ($meterReading->requires_reentry || ! $this->updateAsLastReading($user, $meterReading)) {
            return false;
        }

        return $meterReading->week_start->lessThan(MeterReading::latestEndedWeekStart($meterReading->branch_id))
            && ! $meterReading->hasLaterReadingBefore(MeterReading::latestEndedWeekStart($meterReading->branch_id))
            && $meterReading->laterReadingsCount() <= 1;
    }

    public function updateUnitPrice(User $user, MeterReading $meterReading): bool
    {
        return $user->hasPermission(PermissionKey::UpdateSubscriptionKilowattPrice) && $this->pricingIsEditable($user, $meterReading);
    }

    public function updateMinimumPayment(User $user, MeterReading $meterReading): bool
    {
        return $user->hasPermission(PermissionKey::UpdateSubscriptionMinimumCharge) && $this->pricingIsEditable($user, $meterReading);
    }

    private function pricingIsEditable(User $user, MeterReading $meterReading): bool
    {
        return $meterReading->subscription->archived_at === null && ! $meterReading->requires_reentry
            && ($user->isSuperAdmin() || $user->workBranchId() === $meterReading->branch_id)
            && $meterReading->week_start->equalTo(MeterReading::latestEndedWeekStart($meterReading->branch_id))
            && $meterReading->laterReadingsCount() === 0;
    }

    /**
     * Whether the user may open the weeks before the latest one; without
     * it the sheet shows the latest week only.
     */
    public function browseWeeks(User $user): bool
    {
        return $user->hasPermission(PermissionKey::BrowseMeterReadingWeeks);
    }

    /**
     * Whether the user may approve readings at all (ticked by default for
     * Branch Admins and Accountants).
     */
    public function approveAny(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ApproveMeterReadings);
    }

    /**
     * Approving charges the reading to the subscription, so it takes its own
     * permission; only pending readings in the actor's own branch (any
     * branch for the Super Admin) can be approved.
     */
    public function approve(User $user, MeterReading $meterReading): bool
    {
        return $this->approveAny($user)
            && ! $meterReading->requires_reentry
            && $meterReading->isPending()
            && ($user->isSuperAdmin() || $meterReading->branch_id === $user->workBranchId());
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MeterReading $meterReading): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MeterReading $meterReading): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MeterReading $meterReading): bool
    {
        return false;
    }

    /**
     * The Super Admin may record new readings at any time; everyone else
     * only while their branch's reading entry window is open.
     */
    private function entryIsOpenFor(User $user): bool
    {
        return $user->isSuperAdmin() || ReadingEntrySetting::forBranch($user->workBranchId())->isOpen();
    }

    /**
     * New readings belong to the branch's latest week for every role.
     */
    private function weekIsOpenFor(CarbonInterface $weekStart, ?int $branchId): bool
    {
        return MeterReading::weekStartFor($weekStart, $branchId)->equalTo(MeterReading::latestEndedWeekStart($branchId));
    }

    /**
     * The first day of the week before the branch's latest ended week.
     */
    private function lastWeekStart(?int $branchId): CarbonInterface
    {
        return MeterReading::weekStartFor(MeterReading::latestEndedWeekStart($branchId)->subDay(), $branchId);
    }

    /**
     * Recording readings needs the permission (ticked by default for
     * Branch Admins and Data Entry).
     */
    private function canRecord(User $user): bool
    {
        return $user->hasPermission(PermissionKey::RecordMeterReadings);
    }
}
