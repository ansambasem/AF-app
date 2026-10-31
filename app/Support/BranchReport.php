<?php

namespace App\Support;

use App\Enums\Currency;
use App\Enums\MeterReadingStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What moved through one branch, or several, between two business days
 * (by each branch's stored business-day cut-off): what the subscriptions owed at the start, what
 * was charged, paid, discounted and cleared, what was cancelled, and what
 * they owe at the end — which always adds up, since it is the same lines
 * summed both ways. Also where the payments came in (cash drawer, bank,
 * currency, collector), the readings entered.
 *
 * A line counts under its own type on the business day it was recorded,
 * unless it was cancelled before that day ended: then it, like every
 * reversal, counts as a correction on its own day. So a period is always
 * the sum of its days, and a past day's figures never change when a line
 * is cancelled later — the cancellation shows on the day it was made.
 * Amounts are worked out in cents.
 */
class BranchReport
{
    /**
     * The types that are neither a charge nor a payment: given off what a
     * subscription owes.
     */
    private const DISCOUNT_TYPES = [
        SubscriptionTransaction::TYPE_DISCOUNT,
        SubscriptionTransaction::TYPE_READING_DISCOUNT,
        SubscriptionTransaction::TYPE_CLEARING,
    ];

    /**
     * The lines of the period, read once.
     *
     * @var Collection<int, object>|null
     */
    private ?Collection $rows = null;

    /**
     * @param  Collection<int, Branch>  $branches
     */
    public function __construct(
        private Collection $branches,
        private CarbonImmutable $from,
        private CarbonImmutable $to,
    ) {}

    /**
     * The period's account lines of the branches' subscriptions, unordered,
     * narrowed to one kind: payments, charges, discounts (discounts and
     * clearings), corrections (cancelled lines and reversals), or all.
     *
     * @return Builder<SubscriptionTransaction>
     */
    public function lines(string $kind = 'all'): Builder
    {
        return $this->withinPeriod(
            SubscriptionTransaction::query(),
            'subscription_transactions.created_at',
            fn (Builder $query, array $branchIds): Builder => $query->whereHas('subscription', fn (Builder $subscription) => $subscription->whereIn('branch_id', $branchIds)),
        )
            ->when($kind === 'payments', fn (Builder $query) => $query->where('type', SubscriptionTransaction::TYPE_PAYMENT))
            ->when($kind === 'charges', fn (Builder $query) => $query
                ->whereNotIn('type', [...SubscriptionTransaction::CREDIT_TYPES, ...SubscriptionTransaction::REVERSAL_TYPES])
                ->where(fn (Builder $side): Builder => $side->where('type', '!=', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->orWhere('amount', '>=', 0)))
            ->when($kind === 'discounts', fn (Builder $query) => $query->whereIn('type', self::DISCOUNT_TYPES))
            ->when($kind === 'corrections', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNotNull('cancelled_at')->orWhereNotNull('reverses_id')));
    }

    /**
     * The kinds of line the transactions list can be narrowed to.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function kinds(): array
    {
        return [
            ['value' => 'all', 'label' => 'كل الحركات'],
            ['value' => 'payments', 'label' => 'الدفعات'],
            ['value' => 'charges', 'label' => 'التحميلات'],
            ['value' => 'discounts', 'label' => 'الخصومات والمقاصات'],
            ['value' => 'corrections', 'label' => 'الإلغاءات'],
        ];
    }

    /**
     * The balance's flow through the period: what was owed at the start,
     * each kind of charge and credit, the corrections, and what is owed at
     * the end.
     *
     * @return array<string, mixed>
     */
    public function flow(): array
    {
        $labels = SubscriptionTransaction::typeLabels();
        $opening = $this->openingBalance();
        $charges = [];
        $credits = [];
        $corrections = ['count' => 0, 'total' => 0];

        foreach ($this->rows() as $row) {
            $cents = Closing::cents($row->amount);

            if ($this->isCorrection($row)) {
                // Counted once, by the line that takes another back: a cancellation, refund or reversal.
                $corrections['count'] += $row->reverses_id !== null ? 1 : 0;
                $corrections['total'] += $cents;

                continue;
            }

            if (SubscriptionTransaction::isCreditLine($row->type, $row->amount)) {
                $credits[$row->type] = ['count' => ($credits[$row->type]['count'] ?? 0) + 1, 'total' => ($credits[$row->type]['total'] ?? 0) - $cents];
            } else {
                $charges[$row->type] = ['count' => ($charges[$row->type]['count'] ?? 0) + 1, 'total' => ($charges[$row->type]['total'] ?? 0) + $cents];
            }
        }

        $list = fn (array $totals, array $order): array => collect($totals)
            ->sortBy(fn (array $total, string $type): int => array_search($type, $order, true) === false ? PHP_INT_MAX : array_search($type, $order, true))
            ->map(fn (array $total, string $type): array => ['type' => $type, 'label' => $labels[$type] ?? $type, 'count' => $total['count'], 'total' => Closing::money($total['total'])])
            ->values()
            ->all();
        $chargesTotal = array_sum(array_column($charges, 'total'));
        $creditsTotal = array_sum(array_column($credits, 'total'));
        $closing = $opening + $chargesTotal - $creditsTotal + $corrections['total'];

        return [
            'opening' => Closing::money($opening),
            'charges' => $list($charges, array_keys($labels)),
            'chargesTotal' => Closing::money($chargesTotal),
            'credits' => $list($credits, SubscriptionTransaction::CREDIT_TYPES),
            'creditsTotal' => Closing::money($creditsTotal),
            'corrections' => ['count' => $corrections['count'], 'total' => Closing::money($corrections['total'])],
            'closing' => Closing::money($closing),
            'change' => Closing::money($closing - $opening),
        ];
    }

    /**
     * Where the period's standing payments came in: in all, by receiving
     * account (the cash drawer, each bank or wallet), by currency, and by
     * who collected them.
     *
     * @return array<string, mixed>
     */
    public function collections(): array
    {
        $payments = $this->rows()
            ->filter(fn (object $row): bool => $row->type === SubscriptionTransaction::TYPE_PAYMENT && ! $this->isCorrection($row))
            ->map(fn (object $row): object => (object) [...(array) $row, 'cents' => -Closing::cents($row->amount), 'isCash' => $row->payment_method === PaymentMethod::Cash->value]);
        $collectors = User::query()->whereKey($payments->pluck('recorded_by')->filter()->unique()->values())->pluck('name', 'id');
        $group = fn (Collection $rows): array => ['count' => $rows->count(), 'total' => Closing::money($rows->sum('cents'))];

        return [
            'count' => $payments->count(),
            'total' => Closing::money($payments->sum('cents')),
            'cash' => Closing::money($payments->where('isCash', true)->sum('cents')),
            'nonCash' => Closing::money($payments->where('isCash', false)->sum('cents')),
            'accounts' => $payments
                ->groupBy(fn (object $payment): string => $payment->isCash ? 'cash' : ($payment->bank_name ?: $this->methodLabel($payment->payment_method)))
                ->map(fn (Collection $rows, string $account): array => ['key' => $account, 'label' => $account === 'cash' ? 'الصندوق النقدي' : $account, ...$group($rows)])
                ->sortByDesc(fn (array $account): array => [$account['key'] === 'cash', (float) $account['total']])
                ->values()
                ->all(),
            'currencies' => $payments
                ->groupBy(fn (object $payment): string => $payment->currency ?: Currency::Shekel->value)
                ->map(fn (Collection $rows, string $currency): array => [
                    'currency' => $currency,
                    'label' => __(Currency::tryFrom($currency)?->label() ?? $currency),
                    ...$group($rows),
                    'amount' => Closing::money($rows->sum(fn (object $row): int => Closing::cents($row->currency_amount ?? -$row->amount))),
                ])
                ->sortByDesc(fn (array $currency): float => (float) $currency['total'])
                ->values()
                ->all(),
            'collectors' => $payments
                ->groupBy(fn (object $payment): string => (string) $payment->recorded_by)
                ->map(fn (Collection $rows, string $userId): array => [
                    'name' => $collectors[$userId] ?? 'غير معروف',
                    ...$group($rows),
                    'cash' => Closing::money($rows->where('isCash', true)->sum('cents')),
                ])
                ->sortByDesc(fn (array $collector): float => (float) $collector['total'])
                ->values()
                ->all(),
        ];
    }

    /**
     * The weekly readings: how many were entered in the period and the
     * kilowatts they recorded, how many were approved (and billed) in it,
     * and how many still wait for approval now.
     *
     * @return array{entered: int, consumption: float, approved: int, billed: string, pending: int}
     */
    public function readings(): array
    {
        $inBranches = fn (Builder $query, array $branchIds): Builder => $query->whereIn('branch_id', $branchIds);
        $entered = $this->withinPeriod(MeterReading::query(), 'created_at', $inBranches);
        $approved = $this->withinPeriod(MeterReading::query(), 'approved_at', $inBranches);

        return [
            'entered' => (clone $entered)->count(),
            'consumption' => round((float) $entered->sum('consumption'), 2),
            'approved' => (clone $approved)->count(),
            'billed' => Closing::money(Closing::cents((string) $approved->sum('amount_due'))),
            'pending' => MeterReading::query()->whereIn('branch_id', $this->branchIds())->where('status', MeterReadingStatus::Pending)->count(),
        ];
    }

    /**
     * One row per day: what was charged, paid, discounted and corrected,
     * what the subscriptions owed at the end of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function days(): array
    {
        $byDay = $this->rows()->groupBy(fn (object $row): string => $this->periodsOf($row)->dayOf($row->created_at));
        $balance = $this->openingBalance();
        $days = [];

        foreach (ClosingPeriods::days($this->from, $this->to) as $day) {
            $totals = ['charges' => 0, 'payments' => 0, 'discounts' => 0, 'corrections' => 0];

            foreach ($byDay->get($day, collect()) as $row) {
                $cents = Closing::cents($row->amount);
                $key = match (true) {
                    $this->isCorrection($row) => 'corrections',
                    $row->type === SubscriptionTransaction::TYPE_PAYMENT
                        || ($row->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT && SubscriptionTransaction::isCreditLine($row->type, $row->amount)) => 'payments',
                    // Any other credit, such as an opening balance in the subscription's favour, is given off what they owe as a discount is.
                    in_array($row->type, self::DISCOUNT_TYPES, true) || SubscriptionTransaction::isCreditLine($row->type, $row->amount) => 'discounts',
                    default => 'charges',
                };
                $totals[$key] += $key === 'payments' || $key === 'discounts' ? -$cents : $cents;
                $balance += $cents;
            }

            $days[] = [
                'day' => $day,
                ...array_map(fn (int $cents): string => Closing::money($cents), $totals),
                'lines' => $byDay->get($day, collect())->count(),
                'balance' => Closing::money($balance),
            ];
        }

        return $days;
    }

    /**
     * What the branches' subscriptions owed when the period started: every
     * line recorded before it, cancelled or not, since a cancellation's
     * reversal takes its amount back off when it is recorded.
     */
    private function openingBalance(): int
    {
        return array_sum(array_map(
            fn (array $window): int => Closing::cents((string) SubscriptionTransaction::query()
                ->whereHas('subscription', fn (Builder $subscription) => $subscription->whereIn('branch_id', $window['branchIds']))
                ->where('subscription_transactions.created_at', '<', $window['start'])
                ->sum('amount')),
            $this->windows(),
        ));
    }

    /**
     * Whether a line counts as a correction rather than under its type: a
     * reversal, or a line cancelled before the day it was recorded on ended.
     */
    private function isCorrection(object $row): bool
    {
        $periods = $this->periodsOf($row);

        return $row->reverses_id !== null
            || ($row->cancelled_at !== null && CarbonImmutable::parse($row->cancelled_at, 'UTC')->lessThan($periods->dayEnd($periods->dayOf($row->created_at))));
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        return $this->rows ??= $this->lines()->toBase()
            ->select([
                'subscription_transactions.id', 'type', 'amount', 'payment_method', 'bank_name', 'currency', 'currency_amount',
                'recorded_by', 'reverses_id', 'cancelled_at', 'subscription_transactions.created_at',
            ])
            ->selectSub(Subscription::query()->select('branch_id')->whereColumn('subscriptions.id', 'subscription_transactions.subscription_id'), 'branch_id')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function branchIds(): array
    {
        return $this->branches->pluck('id')->all();
    }

    /**
     * The days of the branch a line belongs to.
     */
    private function periodsOf(object $row): ClosingPeriods
    {
        return ClosingPeriods::for((int) $row->branch_id);
    }

    /**
     * The period as UTC moments, for each group of the branches that close
     * their day at the same time: one, unless their cut-offs differ.
     *
     * @return array<int, array{branchIds: array<int, int>, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function windows(): array
    {
        return $this->branches
            ->groupBy(fn (Branch $branch): string => ClosingPeriods::for($branch)->cutoff())
            ->map(function (Collection $group): array {
                [$start, $end] = ClosingPeriods::for($group->first())->utcRange($this->from, $this->to);

                return ['branchIds' => $group->pluck('id')->all(), 'start' => $start, 'end' => $end];
            })
            ->values()
            ->all();
    }

    /**
     * Narrow the query to the rows dated within the period, each branch by
     * its own cut-off: `$inBranches` limits it to the branches of a window.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>, array<int, int>): Builder<TModel>  $inBranches
     * @return Builder<TModel>
     */
    private function withinPeriod(Builder $query, string $column, Closure $inBranches): Builder
    {
        $windows = $this->windows();

        if ($windows === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($windows, $column, $inBranches): void {
            foreach ($windows as $window) {
                $query->orWhere(fn (Builder $inWindow): Builder => $inBranches($inWindow, $window['branchIds'])
                    ->where($column, '>=', $window['start'])
                    ->where($column, '<', $window['end']));
            }
        });
    }

    private function methodLabel(?string $method): string
    {
        $method = $method === null ? null : PaymentMethod::tryFrom($method);

        return $method === null ? 'أخرى' : __($method->label());
    }
}
