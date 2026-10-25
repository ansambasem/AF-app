<?php

namespace App\Http\Concerns;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the subscription create/edit forms need — their dropdown options and
 * a subscription's editable fields — shared by the subscriptions pages and
 * the dashboard's "new subscriber" and "edit" windows, so both are backed by
 * the same shape.
 */
trait ProvidesSubscriptionForm
{
    /**
     * Whether the actor may set a subscription's minimum charge by hand
     * instead of taking the circuit breaker's.
     */
    protected function canEditMinimumCharge(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::UpdateSubscriptionMinimumCharge);
    }

    /**
     * The full set of a subscription's editable fields — used both for the
     * dedicated edit page and for the edit modal's initial form data on
     * the index page, so both stay backed by the same shape.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionEditableFields(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'account_number' => $subscription->account_number,
            'subscriber_number' => $subscription->profile?->subscriber_number,
            'full_name' => $subscription->full_name,
            'subscription_name' => $subscription->subscription_name,
            'display_name' => $subscription->displayName(),
            'national_id' => $subscription->national_id,
            'phone' => $subscription->phone,
            'subscription_phone' => $subscription->subscription_phone,
            'contact_phone' => $subscription->contactPhone(),
            'address' => $subscription->address,
            'meter_box_id' => $subscription->meter_box_id,
            // The box the subscription is on, for the form's drop-down to show it before any search.
            'meter_box' => $subscription->meterBox ? [
                'value' => (string) $subscription->meterBox->id,
                'label' => $subscription->meterBox->label(),
                'sub_area_id' => $subscription->meterBox->sub_area_id,
            ] : null,
            'tariff_id' => $subscription->tariff_id,
            'tariff_segment_id' => $subscription->tariff_segment_id,
            'branch_id' => $subscription->branch_id,
            'status' => $subscription->status->value,
            'accounting_type' => $subscription->accounting_type->value,
            'circuit_breaker_id' => $subscription->circuit_breaker_id,
            'minimum_charge' => $subscription->minimum_charge,
            'kilowatt_price' => $subscription->kilowatt_price,
            'initial_reading' => $subscription->initial_reading,
            'subscription_fee' => $subscription->subscription_fee,
            // Whether the fee is already on the account: if not, the edit form can still charge it.
            // Whether the subscription has ever been active: then they can be disconnected but not put back to waiting.
            'has_been_active' => $subscription->activated_at !== null,
            'subscription_fee_charged' => (bool) ($subscription->has_subscription_fee ?? $subscription->hasSubscriptionFeeCharge()),
            'subscription_date' => $subscription->subscription_date?->format('Y-m-d'),
            'reconnected_at' => $subscription->reconnected_at?->format('Y-m-d'),
            'notes' => $subscription->notes,
        ];
    }

    /**
     * The branch/tariff options for the create/edit forms, and whether the
     * actor may choose the branch themselves. The meter boxes are not sent:
     * the form finds them as the user types (MeterBoxOptionController),
     * narrowed by branch and sub-area ("منطقة 2"), so the page does not
     * grow with the number of boxes.
     *
     * @return array{branches: Collection, tariffs: Collection, segments: Collection, subAreas: Collection, circuitBreakers: Collection, canChooseBranch: bool, currentBranchAreaId: ?int, currentBranchAreaName: ?string, canEditMinimumCharge: bool, canEditKilowattPrice: bool}
     */
    protected function subscriptionFormOptions(): array
    {
        $actor = auth()->user();
        $canChooseBranch = $actor->isSuperAdmin();

        $branches = fn () => $canChooseBranch ? Branch::with('area')->orderBy('name')->get() : collect();

        $tariffs = fn () => Tariff::orderBy('category')->get()->map(fn (Tariff $tariff) => [
            'id' => $tariff->id,
            'categoryLabel' => __($tariff->category->label()),
            'rate' => $tariff->rate,
        ]);

        return [
            'branches' => $branches,
            'tariffs' => $tariffs,
            // Any subscription can be given any customer segment, whatever their tariff.
            'segments' => fn () => TariffSegment::orderBy('name')->get(['id', 'name']),
            'subAreas' => fn () => SubArea::orderBy('name')->get(),
            'circuitBreakers' => fn () => CircuitBreaker::orderBy('ampere')->get(),
            'canChooseBranch' => $canChooseBranch,
            'currentBranchAreaId' => $canChooseBranch ? null : $actor->workBranch()?->area_id,
            'currentBranchAreaName' => $canChooseBranch ? null : $actor->workBranch()?->area?->name,
            'canEditMinimumCharge' => $this->canEditMinimumCharge($actor),
            'canEditKilowattPrice' => $actor->hasPermission(PermissionKey::UpdateSubscriptionKilowattPrice),
        ];
    }
}
