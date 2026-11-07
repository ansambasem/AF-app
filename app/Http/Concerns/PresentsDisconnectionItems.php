<?php

namespace App\Http\Concerns;

use App\Enums\SubscriptionStatus;
use App\Models\DisconnectionListItem;

/**
 * A disconnection list item as the list's page and its action previews show
 * it. The subscription comes with its balance (`balance`) and meter box.
 */
trait PresentsDisconnectionItems
{
    /**
     * @return array<string, mixed>
     */
    protected function disconnectionItemRow(DisconnectionListItem $item, bool $withPhone): array
    {
        $subscription = $item->subscription;
        $balance = round((float) $subscription->getAttribute('balance'), 2);
        $timezone = config('app.business_timezone');

        return [
            'id' => $item->id,
            'subscriptionId' => $subscription->id,
            'name' => $subscription->displayName(),
            'accountNumber' => $subscription->account_number,
            'phone' => $withPhone ? $subscription->contactPhone() : null,
            'meterBoxNumber' => $subscription->meterBox?->box_number,
            'meterBoxName' => $subscription->meterBox?->displayName(),
            'subAreaName' => $subscription->meterBox?->subArea?->name,
            'reason' => $item->reason->value,
            'reasonLabel' => __($item->reason->label()),
            'note' => $item->note,
            'scheduledFor' => $item->scheduled_for->toDateString(),
            'status' => $item->status->value,
            'statusLabel' => __($item->status->label()),
            'isOverdue' => $item->isOverdue(),
            'serviceStatus' => $subscription->status->value,
            'serviceStatusLabel' => __($subscription->status->label()),
            'serviceIsActive' => $subscription->status === SubscriptionStatus::Active,
            'balance' => number_format($balance, 2, '.', ''),
            'balanceWhenAdded' => $item->balance_when_added,
            // Owed when put on the list and has paid it all since: still on the list, shown as paid.
            'isPaid' => $balance <= 0 && (float) $item->balance_when_added > 0,
            'balanceReduced' => $balance < (float) $item->balance_when_added,
            'fee' => $item->isScheduled() ? DisconnectionListItem::usualFee() : $item->fee_amount,
            'extensionsCount' => $item->extensions_count,
            'warningsCount' => $item->warnings_count,
            'lastWarnedAt' => $item->last_warned_at?->timezone($timezone)->format('Y-m-d H:i'),
            'addedBy' => $item->addedBy?->name,
            'addedAt' => $item->created_at->timezone($timezone)->format('Y-m-d H:i'),
            'removedAt' => $item->removed_at?->timezone($timezone)->format('Y-m-d H:i'),
            'removedBy' => $item->removedBy?->name,
            'removalReason' => $item->removal_reason,
            'disconnectedAt' => $item->disconnected_at?->timezone($timezone)->format('Y-m-d H:i'),
            'disconnectedBy' => $item->disconnectedBy?->name,
        ];
    }

    /**
     * What an item's row needs loaded.
     *
     * @return array<int|string, mixed>
     */
    protected function disconnectionItemRelations(): array
    {
        return [
            'subscription' => fn ($query) => $query->withSum('transactions as balance', 'amount')->with('meterBox.subArea'),
            'addedBy',
            'removedBy',
            'disconnectedBy',
        ];
    }
}
