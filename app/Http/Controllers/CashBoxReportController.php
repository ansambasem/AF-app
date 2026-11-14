<?php

namespace App\Http\Controllers;

use App\Enums\CashBoxType;
use App\Http\Requests\CashBoxReportRequest;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\SubscriptionTransaction;
use App\Support\BranchClosingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CashBoxReportController extends Controller
{
    public function __invoke(CashBoxReportRequest $request): Response
    {
        $actor = $request->user();
        $boxes = CashBox::query();
        $actor->scopeAuditBranches($boxes);
        if (! $actor->isSuperAdmin() && ! BranchClosingAccess::audit($actor)) {
            $boxes->where('branch_id', $actor->workBranchId());
            if (! BranchClosingAccess::view($actor)) {
                $boxes->where('user_id', $actor->id);
            }
        }
        if ((BranchClosingAccess::audit($actor) && ! $actor->isSuperAdmin())) {
            $boxes->where(function (Builder $query) use ($actor): void {
                $query->where('status', 'received');
                if ($actor->workBranchId() && ! BranchClosingAccess::closedOnly($actor, $actor->workBranchId())) {
                    $query->orWhere('branch_id', $actor->workBranchId());
                }
            });
        }

        /** Template settings can open a report without specifying a box. */
        $box = $request->filled('box') ? $boxes->findOrFail($request->integer('box')) : $boxes->latest('id')->first();
        $filter = $request->validated('filter', 'active');
        $rows = collect();
        if ($box) {
            $box->load(['user:id,name', 'receivedBy:id,name', 'audit', 'payments.transaction.subscription', 'payments.audit']);
            $rows = $box->payments->sortBy('id')->map(fn (CashBoxPayment $entry): array => $this->entry($entry, $box));
        }
        $counts = [
            'active' => $rows->where('active', true)->count(),
            'mismatched' => $rows->where('mismatched', true)->count(),
            'returned' => $rows->where('active', false)->count(),
            'all' => $rows->count(),
        ];
        $rows = $rows->filter(fn (array $row): bool => match ($filter) {
            'active' => $row['active'], 'mismatched' => $row['mismatched'], 'returned' => ! $row['active'], default => true,
        })->values();
        $totals = $rows->where('active', true)->groupBy('currency')->map(function (Collection $entries): array {
            $audited = $entries->whereNotNull('bankAmount');

            return [
                'amount' => Closing::money($entries->sum(fn (array $row): int => Closing::cents($row['amount']))),
                'bankAmount' => $audited->isEmpty() ? null : Closing::money($audited->sum(fn (array $row): int => Closing::cents($row['bankAmount']))),
                'difference' => $audited->isEmpty() ? null : Closing::money($audited->sum(fn (array $row): int => Closing::cents($row['difference']))),
                'unknown' => $entries->whereNull('bankAmount')->count(),
            ];
        });

        return Inertia::render('Payments/CashBoxReport', [
            'report' => [
                'boxId' => $box?->id, 'number' => $box?->number, 'collector' => $box?->user?->name,
                'type' => $box?->type->value,
                'branch' => $box ? Branch::query()->find($box->branch_id)?->name : null,
                'openedAt' => $box?->opened_at?->toIso8601String(),
                'closedAt' => $box && ! $box->isEditable() ? ($box->received_at ?? $box->closed_at)?->toIso8601String() : null,
                'closedBy' => $box?->receivedBy?->name,
                'auditStatus' => $box?->audit ? 'اكتمل التدقيق' : 'بانتظار اكتمال التدقيق',
                'isBank' => $box?->type === CashBoxType::BankTransfer,
                'filter' => $filter, 'counts' => $counts, 'rows' => $rows, 'totals' => $totals,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function entry(CashBoxPayment $entry, CashBox $box): array
    {
        $transaction = $entry->transaction;
        $active = $transaction?->status === SubscriptionTransaction::STATUS_ACTIVE;
        $audit = $box->type === CashBoxType::BankTransfer ? $entry->audit : null;
        $status = ! $active
            ? ($transaction?->status === SubscriptionTransaction::STATUS_LINKED_CANCELLATION ? 'مستردّة' : 'ملغاة')
            : match ($audit?->outcome) {
                'matched' => 'مطابقة', 'missing' => 'غير موجودة', 'short' => 'نقص', 'over' => 'زيادة',
                default => $box->type === CashBoxType::BankTransfer ? 'لم تُدقّق' : 'سارية',
            };

        return [
            'id' => $entry->id,
            'subscriber' => $transaction?->subscription?->subscription_name ?: $transaction?->subscription?->full_name,
            'date' => $entry->occurred_at?->toIso8601String(), 'reference' => $transaction?->reference_number,
            'paidOn' => $transaction?->paid_on?->toDateString(), 'lateReason' => $transaction?->late_reason,
            'bankReceivedOn' => $transaction?->bank_received_on?->toDateString(),
            'amount' => $entry->amount, 'currency' => $entry->currency,
            'bankAmount' => $audit?->bank_amount,
            'difference' => $audit ? Closing::money(Closing::cents($entry->amount) - Closing::cents($audit->bank_amount)) : null,
            'active' => $active, 'mismatched' => $active && $audit && $audit->outcome !== 'matched',
            'status' => $status, 'auditFinal' => $audit?->isPosted() ?? false,
            'reason' => ! $active ? ($transaction?->cancellation_notes ?: __($transaction?->cancellation_reason?->label() ?? '')) : $audit?->reason,
        ];
    }
}
