<?php

namespace App\Support;

use App\Enums\DisconnectionItemStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CircuitBreaker;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The dashboard's figures for the branches someone looks after: a branch's
 * staff see their own branch, the Super Admin the whole company or the
 * branches they narrow it to. Each part is worked out only for someone who
 * may open the page it summarizes, by the same rules and the same sums as
 * that page (the reports, the reading sheet, debt aging, disconnection
 * lists, the financial boxes), so the dashboard never shows a figure its
 * reader could not find there.
 */
class DashboardOverview
{
    /**
     * The periods the figures may cover, in days ending today.
     */
    public const PERIODS = [7, 30, 90];

    /**
     * Payments listed per page of the latest payments.
     */
    private const PAYMENTS_PER_PAGE = 8;

    /**
     * Circuit breakers shown in the subscriptions-by-breaker chart.
     */
    private const BREAKERS_SHOWN = 6;

    private CarbonImmutable $today;

    private CarbonImmutable $from;

    /**
     * @param  Collection<int, Branch>  $branches
     */
    public function __construct(private User $actor, private Collection $branches, private int $days)
    {
        $this->today = ClosingPeriods::furthestToday($branches);
        $this->from = $this->today->subDays($days - 1);
    }

    /**
     * @return array<int, int>
     */
    private function branchIds(): array
    {
        return $this->branches->modelKeys();
    }

    /**
     * What was collected in the period and the one before it, cash and
     * transfers apart, today's so far, one figure per day for the chart,
     * and where it came from: each branch when there are several, each
     * collector's share of today in a single branch.
     *
     * @return array<string, mixed>|null
     */
    public function collections(): ?array
    {
        if (! $this->actor->can('viewAny', SubscriptionTransaction::class) || $this->branches->isEmpty()) {
            return null;
        }

        $current = new BranchReport($this->branches, $this->from, $this->today);
        $previous = new BranchReport($this->branches, $this->from->subDays($this->days), $this->from->subDay());
        $today = (new BranchReport($this->branches, $this->today, $this->today))->collections();
        $now = $current->collections();
        $before = $previous->collections();
        $previousDays = array_column($previous->days(), 'payments');

        return [
            'total' => (float) $now['total'],
            'count' => $now['count'],
            'cash' => (float) $now['cash'],
            'transfers' => (float) $now['nonCash'],
            'previousTotal' => (float) $before['total'],
            'change' => $this->change((float) $now['total'], (float) $before['total']),
            'today' => ['total' => (float) $today['total'], 'count' => $today['count']],
            'series' => array_map(fn (array $day, int $index): array => [
                'date' => $day['day'],
                'value' => (float) $day['payments'],
                'previous' => (float) ($previousDays[$index] ?? 0),
            ], $current->days(), array_keys($current->days())),
            'byBranch' => $this->branches->count() > 1 ? $this->collectionsByBranch((float) $now['total']) : null,
            'byCollector' => $this->branches->count() === 1
                ? collect($now['collectors'])->take(8)->map(fn (array $collector): array => [
                    'name' => $collector['name'],
                    'total' => (float) $collector['total'],
                    'count' => $collector['count'],
                    'share' => (float) $now['total'] > 0 ? (int) round((float) $collector['total'] / (float) $now['total'] * 100) : 0,
                ])->all()
                : null,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, governorate: ?string, total: float, share: int}>
     */
    private function collectionsByBranch(float $total): array
    {
        return $this->branches
            ->map(function (Branch $branch) use ($total): array {
                $collected = (float) (new BranchReport(collect([$branch]), $this->from, $this->today))->collections()['total'];

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'governorate' => $branch->governorate?->name,
                    'total' => $collected,
                    'share' => $total > 0 ? (int) round($collected / $total * 100) : 0,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * The current subscriptions (not archived): how many are active,
     * suspended or disconnected, how many were registered in the period,
     * and the active ones by circuit breaker with the amperes they hold.
     *
     * @return array<string, mixed>|null
     */
    public function subscriptions(): ?array
    {
        if (! $this->actor->can('viewAny', Subscription::class)) {
            return null;
        }

        $current = fn (): Builder => Subscription::query()->whereIn('branch_id', $this->branchIds())->whereNull('archived_at');
        $byStatus = $current()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = (int) ($byStatus[SubscriptionStatus::Active->value] ?? 0);
        $total = (int) $byStatus->sum();
        $breakers = $current()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('circuit_breaker_id')
            ->toBase()
            ->selectRaw('circuit_breaker_id, count(*) as total')
            ->groupBy('circuit_breaker_id')
            ->pluck('total', 'circuit_breaker_id');
        $amperes = CircuitBreaker::query()->whereKey($breakers->keys())->pluck('ampere', 'id');
        $withBreaker = (int) $breakers->sum();

        return [
            'total' => $total,
            'active' => $active,
            'suspended' => (int) ($byStatus[SubscriptionStatus::Suspended->value] ?? 0),
            'disconnected' => (int) ($byStatus[SubscriptionStatus::Disconnected->value] ?? 0),
            'newInPeriod' => $current()->where('created_at', '>=', ClosingPeriods::date($this->from)->utc())->count(),
            'contractedAmperes' => (int) $breakers->sum(fn (int $count, int $breakerId): int => $count * (int) ($amperes[$breakerId] ?? 0)),
            'byBreaker' => $breakers
                ->map(fn (int $count, int $breakerId): array => [
                    'ampere' => (int) ($amperes[$breakerId] ?? 0),
                    'count' => $count,
                    'share' => $withBreaker > 0 ? (int) round($count / $withBreaker * 100) : 0,
                ])
                ->sortByDesc('count')
                ->take(self::BREAKERS_SHOWN)
                ->sortBy('ampere')
                ->values()
                ->all(),
        ];
    }

    /**
     * The meter boxes, and how many have no active subscription.
     *
     * @return array{total: int, empty: int}|null
     */
    public function meterBoxes(): ?array
    {
        if (! $this->actor->can('viewAny', MeterBox::class)) {
            return null;
        }

        $boxes = fn (): Builder => MeterBox::query()->whereIn('branch_id', $this->branchIds());

        return [
            'total' => $boxes()->count(),
            'empty' => $boxes()->whereDoesntHave('subscriptions', fn (Builder $subscriptions) => $subscriptions->where('status', SubscriptionStatus::Active))->count(),
        ];
    }

    /**
     * Each branch's latest reading week, as its reading sheet counts it:
     * the active subscriptions, and how many of them have a reading that
     * is approved, waiting for approval, or not entered yet.
     *
     * @return array{week: ?string, total: int, approved: int, pending: int, missing: int, canApprove: bool}|null
     */
    public function readings(): ?array
    {
        if (! $this->actor->can('viewAny', MeterReading::class) || $this->branches->isEmpty()) {
            return null;
        }

        $totals = ['total' => 0, 'approved' => 0, 'pending' => 0];
        $weeks = [];

        foreach ($this->branches as $branch) {
            $week = MeterReading::latestEndedWeekStart($branch)->toDateString();
            $weeks[] = $week;
            $active = Subscription::query()->where('branch_id', $branch->id)->whereNull('archived_at')->where('status', SubscriptionStatus::Active);
            $statuses = MeterReading::query()
                ->whereIn('subscription_id', (clone $active)->select('id'))
                ->whereDate('week_start', $week)
                ->toBase()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $totals['total'] += $active->count();
            $totals['approved'] += (int) ($statuses[MeterReadingStatus::Approved->value] ?? 0);
            $totals['pending'] += (int) ($statuses[MeterReadingStatus::Pending->value] ?? 0);
        }

        return [
            'week' => count(array_unique($weeks)) === 1 ? $weeks[0] : null,
            ...$totals,
            'missing' => max(0, $totals['total'] - $totals['approved'] - $totals['pending']),
            'canApprove' => $this->actor->can('approveAny', MeterReading::class),
        ];
    }

    /**
     * What the subscriptions owe, aged as the debt aging report ages it:
     * the total, the debtors, the part older than 90 days, and the five
     * who owe the most.
     *
     * @return array{total: float, count: int, older: float, olderShare: int, top: array<int, array{subscriptionId: int, name: string, accountNumber: ?string, balance: float, oldestDays: int, lastPaymentDays: ?int}>}|null
     */
    public function debts(): ?array
    {
        if (! $this->actor->can('viewDebtAging', SubscriptionTransaction::class)) {
            return null;
        }

        $debtors = (new DebtAging(DailySeries::today()))->debtors(Subscription::query()->whereIn('subscriptions.branch_id', $this->branchIds()));
        $total = (int) $debtors->sum('balance');
        $older = (int) $debtors->sum(fn (array $debtor): int => $debtor['buckets']['older']);

        return [
            'total' => $total / 100,
            'count' => $debtors->count(),
            'older' => $older / 100,
            'olderShare' => $total > 0 ? (int) round($older / $total * 100) : 0,
            'top' => $debtors->sortByDesc('balance')->take(5)->map(fn (array $debtor): array => [
                'subscriptionId' => $debtor['subscription']->id,
                'name' => $debtor['subscription']->displayName(),
                'accountNumber' => $debtor['subscription']->account_number,
                'balance' => $debtor['balance'] / 100,
                'oldestDays' => $debtor['oldestDays'],
                'lastPaymentDays' => $debtor['lastPaymentDays'],
            ])->values()->all(),
        ];
    }

    /**
     * The disconnection lists: subscriptions past their planned date with
     * nothing done yet, and those planned for the week ahead.
     *
     * @return array{overdue: int, upcoming: int, openLists: int}|null
     */
    public function disconnections(): ?array
    {
        if (! $this->actor->can('viewAny', DisconnectionList::class)) {
            return null;
        }

        $today = DailySeries::today();
        $scheduled = fn (): Builder => DisconnectionListItem::query()->whereIn('branch_id', $this->branchIds())->where('status', DisconnectionItemStatus::Scheduled);

        return [
            'overdue' => $scheduled()->whereDate('scheduled_for', '<', $today->toDateString())->count(),
            'upcoming' => $scheduled()->whereDate('scheduled_for', '>=', $today->toDateString())->whereDate('scheduled_for', '<=', $today->addDays(7)->toDateString())->count(),
            'openLists' => DisconnectionList::query()->whereIn('branch_id', $this->branchIds())->whereNull('closed_at')->count(),
        ];
    }

    /**
     * The open financial boxes holding entries, for whoever closes or
     * reviews the branch's boxes.
     *
     * @return array{open: int}|null
     */
    public function boxes(): ?array
    {
        if (! BranchClosingAccess::view($this->actor)) {
            return null;
        }

        return [
            'open' => CashBox::query()
                ->whereIn('branch_id', $this->branchIds())
                ->whereIn('status', ['open', 'pending'])
                ->whereHas('payments', fn (Builder $entries) => $entries->visibleInBox())
                ->count(),
        ];
    }

    /**
     * The payments recorded in the period, newest first, narrowed to cash
     * or transfers and searched by subscription, with the sum of those not
     * cancelled.
     *
     * @return array{rows: LengthAwarePaginator<int, array<string, mixed>>, sum: float}|null
     */
    public function latestPayments(?string $method, string $search, int $page): ?array
    {
        if (! $this->actor->can('viewAny', SubscriptionTransaction::class)) {
            return null;
        }

        $payments = SubscriptionTransaction::query()
            ->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->whereNull('reverses_id')
            ->where('created_at', '>=', ClosingPeriods::date($this->from)->utc())
            ->whereHas('subscription', fn (Builder $subscription) => $subscription
                ->whereIn('branch_id', $this->branchIds())
                ->when($search !== '', fn (Builder $query) => $query->matchingSearch($search)))
            ->when($method === 'cash', fn (Builder $query) => $query->where('payment_method', PaymentMethod::Cash))
            ->when($method === 'transfer', fn (Builder $query) => $query->where('payment_method', '!=', PaymentMethod::Cash))
            ->with(['subscription.branch', 'recordedBy'])
            ->latest()
            ->latest('id');
        $sum = (float) (clone $payments)->reorder()->whereNull('cancelled_at')->toBase()->selectRaw('coalesce(sum(abs(amount)), 0) as total')->value('total');
        $rows = $payments->paginate(self::PAYMENTS_PER_PAGE, ['*'], 'page', $page)
            ->withPath(route('dashboard'))
            ->withQueryString()
            ->through(fn (SubscriptionTransaction $payment): array => [
                'id' => $payment->id,
                'subscriptionId' => $payment->subscription_id,
                'subscriber' => $payment->subscription->displayName(),
                'accountNumber' => $payment->subscription->account_number,
                'voucherNumber' => $payment->displayVoucherNumber(),
                'branchName' => $payment->subscription->branch?->name,
                'recordedBy' => $payment->recordedBy?->name,
                'isCash' => $payment->payment_method === PaymentMethod::Cash,
                'bankName' => $payment->bank_name,
                'amount' => abs((float) $payment->amount),
                'recordedAt' => $payment->created_at->toIso8601String(),
                'cancelled' => $payment->cancelled_at !== null,
            ]);

        return ['rows' => $rows, 'sum' => round($sum, 2)];
    }

    /**
     * The percentage change from `$before` to `$now`, or null when there
     * was nothing before to compare with.
     */
    private function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }
}
