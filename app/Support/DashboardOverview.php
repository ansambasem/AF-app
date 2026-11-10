<?php

namespace App\Support;

use App\Enums\MeterReadingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The dashboard's figures for the branches someone looks after: a branch's
 * staff see their own branch, the Super Admin the whole company or the
 * branch they pick. The dashboard shows the open billing period's accounts
 * and what waits on someone (readings to approve or enter, open boxes);
 * each part is worked out only for someone who may
 * open the page it summarizes, by the same rules and sums as that page.
 */
class DashboardOverview
{
    /**
     * @param  Collection<int, Branch>  $branches
     */
    public function __construct(private User $actor, private Collection $branches) {}

    /**
     * @return array<int, int>
     */
    private function branchIds(): array
    {
        return $this->branches->modelKeys();
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
     * The open billing period of each branch, in figures: the nine totals of
     * the remaining amounts report, the subscriptions active, disconnected,
     * owing and ahead, how much of what was due was paid, each branch's
     * collections, and the five who owe most. Read from the same rows as the
     * report, so the two always agree. Null for someone who may not read
     * the accounts, or with no branch working by periods.
     *
     * @return array<string, mixed>|null
     */
    public function periodAccounts(): ?array
    {
        if ((! FinancialReportAccess::view($this->actor) && ! $this->actor->can('viewAny', SubscriptionTransaction::class)) || $this->branches->isEmpty()) {
            return null;
        }

        $periods = app(BillingPeriods::class);
        $statement = app(PeriodStatement::class);
        $groups = $this->branches->map(function (Branch $branch) use ($periods, $statement): ?array {
            $period = $periods->open($branch->id);

            return $period ? ['branch' => $branch, 'period' => $period, 'rows' => $statement->rows($period)->map(fn (array $row): array => [...$row, 'branchName' => $branch->name])] : null;
        })->filter()->values();
        if ($groups->isEmpty()) {
            return null;
        }

        $rows = $groups->flatMap(fn (array $group) => $group['rows']->values());
        $totals = PeriodStatement::summarize($rows);
        $subscriptions = Subscription::query()->whereIn('branch_id', $groups->pluck('branch.id'))->whereNull('archived_at')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END) as disconnected")->first();

        return [
            'periods' => $groups->map(fn (array $group): array => ['branch' => $group['branch']->name, 'label' => $group['period']->label()])->values()->all(),
            'totals' => PeriodStatement::present($totals),
            'counts' => [
                'active' => (int) ($subscriptions->active ?? 0),
                'disconnected' => (int) ($subscriptions->disconnected ?? 0),
                'owing' => $totals['owing'],
                'ahead' => $totals['ahead'],
            ],
            'rates' => ['total' => $totals['totalRate'], 'invoice' => $totals['invoiceRate'], 'accumulated' => $totals['accumulatedRate']],
            'byBranch' => $groups->count() > 1 ? $groups->map(function (array $group): array {
                $branchTotals = PeriodStatement::summarize($group['rows']);

                return ['id' => $group['branch']->id, 'name' => $group['branch']->name, 'due' => Closing::money($branchTotals['collectible']), 'payments' => Closing::money($branchTotals['payments']), 'totalRate' => $branchTotals['totalRate']];
            })->sortByDesc(fn (array $branch): float => (float) $branch['payments'])->values()->all() : null,
            'top' => $rows->sortByDesc('remaining')->filter(fn (array $row): bool => $row['remaining'] > 0)->take(5)
                ->map(fn (array $row): array => ['subscriptionId' => $row['subscriptionId'], 'name' => $row['name'], 'accountNumber' => $row['accountNumber'], 'branchName' => $row['branchName'], 'remaining' => Closing::money($row['remaining'])])->values()->all(),
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
}
