<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditTransferSearch
{
    /** @return Builder<CashBoxPayment> */
    private function entries(int $branchId, string $week): Builder
    {
        $from = ClosingPeriods::date($week);

        return CashBoxPayment::query()
            ->whereHas('box', fn (Builder $box) => $box->where('branch_id', $branchId)->where('status', 'received')->where('type', 'bank_transfer')
                ->inClosingWeek($from))
            ->whereHas('transaction', fn (Builder $payment) => $payment->where('type', SubscriptionTransaction::TYPE_PAYMENT)->where('status', SubscriptionTransaction::STATUS_ACTIVE)->whereNull('cancelled_at'));
    }

    /** @return array<string, mixed> */
    public function results(Request $request, User $actor, int $branchId, string $week): array
    {
        abort_unless($actor->is_active && ($actor->isSuperAdmin() || $actor->hasFinancialAuditAccess()), 403);
        abort_unless($actor->auditIncludesBranch($branchId), 404);
        $search = trim((string) $request->query('transfer_search', ''));
        $state = (string) $request->query('transfer_state', '');
        $dates = $request->validate([
            'transfer_date_basis' => ['sometimes', 'in:recorded,paid,bank'],
            'transfer_sort' => ['sometimes', 'in:date_desc,date_asc,bank_desc,bank_asc'],
            'transfer_from' => ['nullable', 'required_with:transfer_to', 'date_format:Y-m-d'],
            'transfer_to' => ['nullable', 'required_with:transfer_from', 'date_format:Y-m-d', 'after_or_equal:transfer_from'],
        ]);
        $basis = $dates['transfer_date_basis'] ?? 'recorded';
        $sort = $dates['transfer_sort'] ?? 'date_desc';
        $direction = str_ends_with($sort, '_asc') ? 'asc' : 'desc';
        $query = $this->entries($branchId, $week);
        if (! empty($dates['transfer_from']) && ! empty($dates['transfer_to'])) {
            $from = CarbonImmutable::parse($dates['transfer_from'], config('app.business_timezone'))->startOfDay()->utc();
            $until = CarbonImmutable::parse($dates['transfer_to'], config('app.business_timezone'))->addDay()->startOfDay()->utc();
            $query->whereHas('transaction', function (Builder $payment) use ($from, $until, $dates, $basis): void {
                if ($basis === 'recorded') {
                    $payment->where('created_at', '>=', $from)->where('created_at', '<', $until);
                } elseif ($basis === 'bank') {
                    $payment->whereBetween('bank_received_on', [$dates['transfer_from'], $dates['transfer_to']]);
                } else {
                    $payment->where(fn (Builder $actual) => $actual->where('paid_on', '>=', $dates['transfer_from'])->where('paid_on', '<', $until->setTimezone(config('app.business_timezone'))->toDateString())
                        ->orWhere(fn (Builder $normal) => $normal->whereNull('paid_on')->where('created_at', '>=', $from)->where('created_at', '<', $until)));
                }
            });
        }
        if ($search !== '') {
            $number = strtr($search, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            $references = array_filter([SubscriptionTransaction::normalizeReference($number), SubscriptionTransaction::normalizeReference($search)]);
            $query->whereHas('transaction', function (Builder $payment) use ($search, $number, $references): void {
                $payment->where(function (Builder $matching) use ($search, $number, $references): void {
                    $matching->whereIn('manual_voucher_number', [$search, $number])->orWhereIn('reference_number', [$search, $number]);
                    if ($references !== []) {
                        $matching->orWhereIn('active_reference', $references);
                    }
                    if (ctype_digit($number) && strlen(ltrim($number, '0')) <= 18) {
                        $matching->orWhere('voucher_number', (int) $number);
                    }
                });
            });
        }
        match ($state) {
            'differences' => $query->whereHas('audit', fn (Builder $audit) => $audit->where('difference', '!=', 0)),
            'unreviewed' => $query->whereDoesntHave('audit'),
            'draft' => $query->whereHas('audit', fn (Builder $audit) => $audit->whereNull('posted_at')->whereNull('adjustment_transaction_id')),
            'missing', 'short', 'over', 'matched' => $query->whereHas('audit', fn (Builder $audit) => $audit->where('outcome', $state)),
            default => null,
        };
        if ($search === '' && $state === '' && empty($dates['transfer_from']) && $sort === 'date_desc') {
            $query->whereRaw('1 = 0');
        }
        if (str_starts_with($sort, 'bank_')) {
            $query->join('subscription_transactions as bank_sort', 'bank_sort.id', '=', 'cash_box_payments.subscription_transaction_id')
                ->select('cash_box_payments.*')
                ->orderByRaw('CASE WHEN bank_sort.bank_received_on IS NULL THEN 1 ELSE 0 END')
                ->orderBy('bank_sort.bank_received_on', $direction);
        }
        $results = $query->with(['transaction.subscription:id,full_name,subscription_name', 'transaction.recordedBy:id,name', 'box.user:id,name', 'box.audit', 'audit.auditor:id,name'])
            ->orderBy('cash_box_payments.occurred_at', $direction)->orderBy('cash_box_payments.id', $direction)->paginate(20, pageName: 'transfer_page')->withQueryString()
            ->through(function (CashBoxPayment $entry) use ($actor): array {
                $payment = $entry->transaction;

                return [
                    'id' => $entry->id,
                    'transaction' => [...$payment->only(['id', 'amount', 'exchange_rate']), 'subscription' => $payment->subscription->only(['full_name', 'subscription_name'])],
                    'ledger' => $payment->ledgerDetails(),
                    'audit' => $entry->audit,
                    'box' => ['number' => $entry->box->number, 'collector' => $entry->box->user?->name],
                    'canReview' => BankTransferAuditing::allowed($actor, AuditAction::ReviewTransfers) && $entry->box->audit === null && ! $entry->audit?->isPosted(),
                    'finalized' => $entry->box->audit !== null || ($entry->audit?->isPosted() ?? false),
                    'audit_url' => route('payments.bank-audits.store', $entry),
                ];
            });
        $totals = $this->entries($branchId, $week)->whereDoesntHave('box.audit')
            ->leftJoin('bank_transfer_audits as review', 'review.cash_box_payment_id', '=', 'cash_box_payments.id')
            ->toBase()->selectRaw('COUNT(*) as transfers, SUM(CASE WHEN review.id IS NULL THEN 1 ELSE 0 END) as automatic_matches, SUM(CASE WHEN review.difference != 0 THEN 1 ELSE 0 END) as exceptions, SUM(CASE WHEN review.difference > 0 THEN review.difference ELSE 0 END) as debit, SUM(CASE WHEN review.difference < 0 THEN -review.difference ELSE 0 END) as credit')->first();

        return [
            'search' => $search, 'state' => $state, 'results' => $results,
            'dateBasis' => $basis, 'from' => $dates['transfer_from'] ?? '', 'to' => $dates['transfer_to'] ?? '',
            'sort' => $sort,
            'summary' => ['transfers' => (int) $totals->transfers, 'automaticMatches' => (int) $totals->automatic_matches, 'exceptions' => (int) $totals->exceptions,
                'debit' => Closing::money(Closing::cents($totals->debit ?? 0)), 'credit' => Closing::money(Closing::cents($totals->credit ?? 0))],
        ];
    }
}
