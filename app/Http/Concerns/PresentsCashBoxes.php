<?php

namespace App\Http\Concerns;

use App\Enums\CashBoxType;
use App\Models\AuditNote;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What the payments pages and the financial boxes pages show alike: a box
 * as a row, a page of box entries, the collector's badge and links, and the
 * collector's own boxes and waiting audit notes. The using controller has
 * a BranchClosingWorkflow `$workflow` and uses FiltersDataTable.
 */
trait PresentsCashBoxes
{
    /**
     * One page of box entries, newest first, with how many sit in each type
     * of box.
     *
     * @param  Builder<CashBoxPayment>  $query
     * @param  callable(CashBox): string  $boxUrl
     * @return array{entries: LengthAwarePaginator<int, array<string, mixed>>, filters: array{filter: array{type: string}, search: string, per_page: int}, counts: array<string, int>}
     */
    protected function entryList(Request $request, Builder $query, callable $boxUrl): array
    {
        $query->visibleInBox();
        $types = array_column(CashBoxType::cases(), 'value');
        $filter = in_array($request->input('filter.type'), $types, true) ? $request->input('filter.type') : '';
        $search = trim((string) $request->query('search'));
        $perPage = $this->dataTablePerPage($request);
        $ofType = fn (Builder $entries, string $type) => $entries->whereHas('box', fn (Builder $box) => $box->where('type', $type));
        $linksStatement = $request->user()->can('viewAny', Subscription::class);
        $searched = (clone $query)->when($search !== '', fn (Builder $entries) => $entries->where(fn (Builder $matching) => $matching
            ->whereHas('transaction.subscription', fn (Builder $subscription) => $subscription->matchingSearch($search))
            ->orWhereHas('transaction', fn (Builder $transaction) => $transaction->where(fn (Builder $numbers) => $numbers->where('voucher_number', $search)->orWhere('manual_voucher_number', $search)->orWhere('reference_number', 'like', "%{$search}%")))
            ->orWhereHas('box', fn (Builder $box) => $box->where('number', $search))));
        $entries = (clone $searched)
            ->when($filter !== '', fn (Builder $entries) => $ofType($entries, $filter))
            ->with(['box:id,number,type,user_id,branch_id', 'box.user:id,name', 'transaction', 'transaction.recordedBy:id,name', 'transaction.subscription:id,full_name,subscription_name,account_number'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString()
            ->through(fn (CashBoxPayment $entry): array => [
                ...$this->cashBoxEntry($entry, $linksStatement),
                'box' => ['id' => $entry->box->id, 'number' => $entry->box->number, 'type' => $entry->box->type->value, 'url' => $boxUrl($entry->box)],
                'collector' => $entry->box->user?->name,
            ]);

        return [
            'entries' => $entries,
            'filters' => ['filter' => ['type' => $filter], 'search' => $search, 'per_page' => $perPage],
            'counts' => ['all' => (clone $searched)->count(), ...collect($types)->mapWithKeys(fn (string $type): array => [$type => $ofType(clone $searched, $type)->count()])->all()],
        ];
    }

    /**
     * One entry of a box: the payment or clearing, who it was for, where
     * its check stands, the payment's receipt, the transaction as the
     * subscriber's statement shows it, and (for who may open subscribers,
     * `$linksStatement`) the statement opened at that transaction.
     *
     * @return array<string, mixed>
     */
    protected function cashBoxEntry(CashBoxPayment $entry, bool $linksStatement = false): array
    {
        return [
            'id' => $entry->id, 'occurredAt' => $entry->occurred_at, 'currency' => $entry->currency,
            'amount' => $entry->amount, 'status' => $entry->status, 'isLate' => $entry->is_late,
            'transactionStatus' => $entry->transaction->status,
            'subscriber' => $entry->transaction->subscription->displayName(),
            'accountNumber' => $entry->transaction->subscription->account_number,
            'voucherNumber' => $entry->transaction->manual_voucher_number ?? $entry->transaction->voucher_number,
            'bankName' => $entry->transaction->bank_name, 'senderBank' => $entry->transaction->sender_bank_name,
            'referenceNumber' => $entry->transaction->reference_number, 'service' => $entry->transaction->notes,
            'receiptUrl' => $entry->transaction->isPayment() || $entry->transaction->isClearing() ? route('subscriptions.payments.receipt', [$entry->transaction->subscription, $entry->transaction]) : null,
            'ledger' => $entry->transaction->ledgerDetails(),
            'statementUrl' => $linksStatement ? route('subscriptions.statement', [$entry->transaction->subscription_id, 'transaction' => $entry->transaction->id]) : null,
        ];
    }

    /**
     * The audit notes of the actor's branch waiting on the actor's answer.
     *
     * @return Builder<AuditNote>
     */
    protected function waitingAuditNotes(User $actor): Builder
    {
        return AuditNote::query()->where('assigned_to', $actor->id)->where('status', 'with_collector')
            ->whereHas('closing', fn (Builder $query) => $query->where('branch_id', $actor->workBranchId()));
    }

    protected function ownBoxQuery(User $actor): Builder
    {
        return CashBox::query()->where('branch_id', $actor->workBranchId())->where('user_id', $actor->id)
            ->withCount(['payments' => fn ($entries) => $entries->visibleInBox()])->with(['payments.transaction:id,status', 'receivedBy:id,name', 'receivedIn:id,period_start,period_end'])->latest('id');
    }

    /** @return array{payments: string, summary: string, cashBoxes: string} */
    protected function collectionUrls(): array
    {
        return ['payments' => route('payments.index'), 'summary' => route('payments.summary'), 'cashBoxes' => route('payments.cash-boxes')];
    }

    /** Whether the actor's branch keeps collector boxes yet. */
    protected function boxesStarted(User $actor): bool
    {
        return $actor->workBranchId() && ClosingSetting::ownFor($actor->workBranchId())?->workflow_started_at !== null;
    }

    /**
     * The badge on the collector's pages: what waits on the actor on the
     * summary page (a handover left to confirm, audit notes to answer).
     *
     * @return array{pendingCount: int}
     */
    protected function collectionCounts(User $actor): array
    {
        if (! $this->boxesStarted($actor)) {
            return ['pendingCount' => 0];
        }

        return [
            'pendingCount' => $this->ownBoxQuery($actor)->where('type', CashBoxType::Cash)->where('status', 'received')->whereNull('confirmed_at')->count() + $this->waitingAuditNotes($actor)->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function cashBoxRow(CashBox $box): array
    {
        return [
            'id' => $box->id,
            'number' => $box->number,
            'status' => $box->isEditable() ? 'open' : $box->status,
            'type' => $box->type->value,
            'stage' => $box->stage(),
            'totals' => $box->totals(),
            'rejectedCount' => $box->payments->whereIn('status', ['missing', 'rejected'])->filter(fn (CashBoxPayment $entry): bool => $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE)->count(),
            'openedAt' => $box->opened_at,
            'closedAt' => $box->isEditable() ? null : $box->closed_at,
            'receivedAt' => $box->received_at,
            'confirmedAt' => $box->confirmed_at,
            'paymentCount' => $box->payments_count,
            // The branch counts blind, so the count and what it was checked against show only once the box is received.
            ...($box->received_at && $box->type->isCounted() && $box->head_counts ? [
                'expectedCounts' => $box->expected_counts,
                'receivedCounts' => $box->head_counts,
                'differences' => $this->workflow->differences($box->head_counts, $box->expected_counts ?? []),
                'decisions' => $box->decisions ?: null,
            ] : ['expectedCounts' => null, 'receivedCounts' => null, 'differences' => null, 'decisions' => null]),
            'reason' => $box->reason,
            'receivedAmounts' => $this->receivedAmounts($box),
            'closedBy' => $this->closedBy($box),
            'settledIn' => $box->receivedIn ? ['start' => $box->receivedIn->period_start->toDateString(), 'end' => $box->receivedIn->period_end->toDateString()] : null,
            'urls' => [
                'show' => route('payments.cash-boxes.show', $box),
                'confirm' => route('payments.cash-boxes.actions', ['action' => 'confirm', 'record' => $box->id]),
                'export' => route('payments.cash-boxes.show', [$box, 'format' => 'csv']),
            ],
            'branchId' => $box->branch_id,
        ];
    }

    /**
     * What the branch took in from the box, by currency: the cash it counted,
     * or the transfers and clearings it confirmed. Nothing before receipt.
     *
     * @return array<string, string>
     */
    protected function receivedAmounts(CashBox $box): array
    {
        $none = array_fill_keys(BranchClosingWorkflow::CURRENCIES, '0.00');

        if (! $box->received_at) {
            return $none;
        }

        if ($box->type->isCounted()) {
            return collect($none)->map(fn (string $zero, string $currency): string => Closing::money(Closing::cents($box->head_counts[$currency] ?? 0)))->all();
        }

        $confirmed = $box->payments->where('status', 'confirmed')->filter(fn (CashBoxPayment $entry): bool => $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE);

        return collect($none)->map(fn (string $zero, string $currency): string => Closing::money($confirmed->where('currency', $currency)->sum(fn (CashBoxPayment $entry): int => Closing::cents($entry->amount))))->all();
    }

    /**
     * Who closed the box: the branch member who counted or received it, or
     * what closed it on its own (the weekly cut-off, the collector leaving,
     * a payment synced after its week). Null while it is open.
     */
    protected function closedBy(CashBox $box): ?string
    {
        if ($box->isEditable()) {
            return null;
        }

        return $box->receivedBy?->name ?? match ($box->close_reason) {
            CashBox::CLOSE_REASON_PERIOD_END => 'النظام · إغلاق تلقائي',
            'cutoff' => 'تلقائي عند القطع الأسبوعي',
            'departure' => 'تلقائي عند مغادرة المحصّل',
            'late_sync' => 'تلقائي لدفعة متأخرة',
            default => 'الفرع',
        };
    }
}
