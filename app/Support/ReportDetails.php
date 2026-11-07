<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ReportDetails
{
    /** @param Collection<int, Branch> $branches */
    public function __construct(private Collection $branches, private CarbonImmutable $from, private CarbonImmutable $to) {}

    /**
     * Historical balances use every signed line; payments settle the oldest
     * charges first. Balances are summed in the database, and lines are
     * loaded only for the subscriptions that owe, the only ones aged.
     *
     * @return array<string, mixed>
     */
    public function debts(): array
    {
        $rows = collect();
        $credit = 0;
        $creditCount = 0;
        foreach ($this->branches as $branch) {
            $periods = ClosingPeriods::for($branch);
            $end = $periods->dayEnd($this->to)->utc();
            Subscription::query()->where('branch_id', $branch->id)->orderBy('id')->chunkById(300, function (Collection $subscriptions) use (&$rows, &$credit, &$creditCount, $branch, $periods, $end): void {
                $balances = SubscriptionTransaction::query()->whereIn('subscription_id', $subscriptions->pluck('id')->all())
                    ->where('created_at', '<', $end)->groupBy('subscription_id')
                    ->selectRaw('subscription_id, SUM(amount) as balance')->pluck('balance', 'subscription_id')
                    ->map(fn (mixed $sum): int => Closing::cents((string) $sum));
                $lines = SubscriptionTransaction::query()->whereIn('subscription_id', $balances->filter(fn (int $balance): bool => $balance > 0)->keys()->all())
                    ->where('created_at', '<', $end)->orderByDesc('created_at')->orderByDesc('id')
                    ->get(['id', 'subscription_id', 'type', 'amount', 'created_at', 'cancelled_at', 'reverses_id'])->groupBy('subscription_id');
                foreach ($subscriptions as $subscription) {
                    $balance = $balances->get($subscription->id, 0);
                    if ($balance <= 0) {
                        $credit += $balance;
                        $creditCount += $balance < 0 ? 1 : 0;

                        continue;
                    }
                    $standing = $lines->get($subscription->id, collect())
                        ->filter(fn (SubscriptionTransaction $line): bool => $line->cancelled_at === null || $line->cancelled_at->greaterThanOrEqualTo($end));
                    $lastPayment = $standing->first(fn (SubscriptionTransaction $line): bool => $line->type === SubscriptionTransaction::TYPE_PAYMENT);
                    $lastPayment = $lastPayment ? $periods->dayOf($lastPayment->created_at) : null;
                    ['buckets' => $buckets, 'oldestDays' => $oldestDays] = DebtAging::split(
                        $balance,
                        $standing->lazy()
                            ->filter(fn (SubscriptionTransaction $line): bool => $line->reverses_id === null && ! in_array($line->type, SubscriptionTransaction::REVERSAL_TYPES, true) && Closing::cents($line->amount) > 0)
                            ->map(fn (SubscriptionTransaction $line): array => ['day' => $periods->dayOf($line->created_at), 'cents' => Closing::cents($line->amount)]),
                        fn (string $day): int => max(0, (int) ClosingPeriods::date($day)->diffInDays($this->to)),
                    );
                    $rows->push([
                        'id' => $subscription->id, 'name' => $subscription->displayName(), 'accountNumber' => $subscription->account_number,
                        'branchName' => $branch->name, 'balance' => Closing::money($balance),
                        'buckets' => array_map(fn (int $amount): string => Closing::money($amount), $buckets),
                        'oldestDays' => $oldestDays, 'lastPaymentDate' => $lastPayment,
                    ]);
                }
            });
        }
        $rows = $rows->sortByDesc(fn (array $row): int => Closing::cents($row['balance']))->values();

        return [
            'rows' => $rows, 'asOf' => $this->to->toDateString(), 'count' => $rows->count(), 'bucketLabels' => DebtAging::BUCKET_LABELS,
            'total' => Closing::money($rows->sum(fn (array $row): int => Closing::cents($row['balance']))),
            'credit' => Closing::money($credit), 'creditCount' => $creditCount,
            'buckets' => collect(DebtAging::BUCKET_LABELS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'total' => Closing::money($rows->sum(fn (array $row): int => Closing::cents($row['buckets'][$key])))])->values(),
        ];
    }

    /**
     * Recorded box entries by currency, without treating a handover as fresh
     * income. Only boxes open at some point in the period are listed: one
     * closed and received before the period began belongs to an earlier one.
     * `byStatus` totals them by where the box stands at the period's end:
     * still open, closed without the branch confirming it, or confirmed.
     *
     * @return array<string, mixed>
     */
    public function boxes(): array
    {
        $rows = collect();
        foreach ($this->branches as $branch) {
            [$start, $end] = ClosingPeriods::for($branch)->utcRange($this->from, $this->to);
            $boxes = CashBox::query()->where('branch_id', $branch->id)->where('opened_at', '<', $end)
                ->where(fn (Builder $query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $start)->orWhere('received_at', '>=', $start))
                ->with(['user:id,name', 'payments' => function (HasMany $query) use ($end): void {
                    $query->where('occurred_at', '<', $end);
                }, 'payments.transaction:id,cancelled_at'])
                ->orderByDesc('opened_at')->orderByDesc('id')->get();
            foreach ($boxes as $box) {
                $payments = $box->payments->filter(fn (CashBoxPayment $entry): bool => $entry->transaction !== null && ($entry->transaction->cancelled_at === null || $entry->transaction->cancelled_at->greaterThanOrEqualTo($end)));
                $closed = $box->closed_at !== null && $box->closed_at->lessThan($end);
                $received = $box->received_at !== null && $box->received_at->lessThan($end);
                foreach ($payments->groupBy('currency') as $currency => $entries) {
                    $rows->push([
                        'id' => $box->id.'-'.$currency, 'boxId' => $box->id, 'number' => $box->number, 'collector' => $box->user?->name,
                        'branchName' => $branch->name, 'type' => __($box->type->label()), 'currency' => $currency, 'count' => $entries->count(),
                        'amount' => Closing::money($entries->sum(fn (CashBoxPayment $entry): int => Closing::cents($entry->amount))),
                        'status' => $received ? 'مستلم' : ($closed ? 'مغلق' : 'مفتوح'), 'openedAt' => DailySeries::localDate($box->opened_at),
                    ]);
                }
            }
        }

        return [
            'asOf' => $this->to->toDateString(),
            'rows' => $rows->values(),
            'byStatus' => collect(['مفتوح', 'مغلق', 'مستلم'])->map(fn (string $status): array => [
                'status' => $status,
                'boxes' => $rows->where('status', $status)->unique('boxId')->count(),
                'currencies' => $rows->where('status', $status)->groupBy('currency')
                    ->map(fn (Collection $entries, string $currency): array => ['currency' => $currency, 'amount' => Closing::money($entries->sum(fn (array $row): int => Closing::cents($row['amount'])))])
                    ->sortByDesc(fn (array $total): bool => $total['currency'] === 'ILS')->values()->all(),
            ])->all(),
        ];
    }
}
