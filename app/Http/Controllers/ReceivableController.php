<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\FiltersDataTable;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\DailySeries;
use App\Support\DebtAging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The debts report (أعمار الديون): every subscription the user may see who
 * owes money, with how old their debt is (see DebtAging), the totals per
 * age and the oldest debts first to chase. Read-only; a row opens the
 * subscription's statement over the page.
 */
class ReceivableController extends Controller
{
    use BuildsSubscriptionStatement, FiltersDataTable;

    private const DEFAULT_PER_PAGE = 25;

    /**
     * The columns the table may be sorted by: the balance, a bucket's
     * amount, the age of the oldest debt, the last payment, or the name.
     */
    private const SORTS = ['balance', 'current', 'days_60', 'days_90', 'older', 'oldest_days', 'last_payment_days', 'name'];

    /**
     * The "age" filter: debtors owing something older than this many days.
     */
    private const AGE_FILTERS = ['30' => 'دين أقدم من 30 يومًا', '60' => 'دين أقدم من 60 يومًا', '90' => 'دين أقدم من 90 يومًا'];

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewDebtAging', SubscriptionTransaction::class);

        $actor = $request->user();
        $debtors = $this->withAgeFilter((new DebtAging(DailySeries::today()))->debtors($this->filteredSubscriptions($request, $actor)), $request);
        $perPage = $this->dataTablePerPage($request, self::DEFAULT_PER_PAGE);
        $page = max(1, (int) $request->input('page', 1));
        $sorted = $this->sortDebtors($debtors, $request);

        $rows = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->map(fn (array $debtor): array => $this->row($debtor))->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Receivables/Index', [
            'debtors' => $rows,
            'summary' => $this->summary($debtors),
            'buckets' => collect(DebtAging::BUCKET_LABELS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'today' => DailySeries::today()->toDateString(),
            'scopeLabel' => $this->scopeLabel($request, $actor),
            // The Super Admin reads, large above the report, which branch it holds.
            'branchScope' => fn () => BranchScope::shownTo($actor) ? BranchScope::forFilter($actor, $this->filterValue($request, 'branch_id')) : null,
            'filters' => $this->dataTableState($request, 'balance', 'desc', self::DEFAULT_PER_PAGE),
            'filterOptions' => $this->filterOptions($actor),
            // A debtor's statement, opened over the report.
            'statement' => fn () => $this->requestedStatement($request, $actor),
        ]);
    }

    /**
     * The subscriptions the user may see — in their own branch (any branch
     * for the Super Admin) — narrowed by the search box, the branch and
     * the status.
     *
     * @return Builder<Subscription>
     */
    private function filteredSubscriptions(Request $request, User $actor): Builder
    {
        $search = $this->searchTerm($request);
        $branchId = $actor->isSuperAdmin() ? $this->filterValue($request, 'branch_id') : null;
        $status = SubscriptionStatus::tryFrom((string) $this->filterValue($request, 'status'));

        return Subscription::query()
            ->visibleTo($actor)
            ->with('branch')
            ->when($branchId, fn (Builder $query) => $query->where('subscriptions.branch_id', $branchId))
            ->when($status, fn (Builder $query) => $query->where('subscriptions.status', $status->value))
            ->when($search !== '', fn (Builder $query) => $query->matchingSearch($search));
    }

    /**
     * Only the debtors owing something older than the "age" filter's days,
     * when it is set.
     *
     * @param  Collection<int, array<string, mixed>>  $debtors
     * @return Collection<int, array<string, mixed>>
     */
    private function withAgeFilter(Collection $debtors, Request $request): Collection
    {
        $days = $this->filterValue($request, 'age');

        if (! array_key_exists((string) $days, self::AGE_FILTERS)) {
            return $debtors;
        }

        $olderBuckets = collect(DebtAging::BUCKETS)->filter(fn (?int $maxDays): bool => $maxDays === null || $maxDays > (int) $days)->keys();

        return $debtors
            ->filter(fn (array $debtor): bool => $olderBuckets->contains(fn (string $bucket): bool => $debtor['buckets'][$bucket] > 0))
            ->values();
    }

    /**
     * Largest balance first unless a column header asks otherwise; ties
     * keep the account order.
     *
     * @param  Collection<int, array<string, mixed>>  $debtors
     * @return Collection<int, array<string, mixed>>
     */
    private function sortDebtors(Collection $debtors, Request $request): Collection
    {
        $sort = (string) $request->string('sort');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'balance';
        $direction = $request->input('sort') !== null ? $this->sortDirection($request) : 'desc';

        $value = fn (array $debtor): int|string|null => match ($sort) {
            'balance' => $debtor['balance'],
            'oldest_days' => $debtor['oldestDays'],
            // Never paid sorts as the longest wait.
            'last_payment_days' => $debtor['lastPaymentDays'] ?? PHP_INT_MAX,
            'name' => $debtor['subscription']->displayName(),
            default => $debtor['buckets'][$sort],
        };

        return $debtors
            ->sort(function (array $first, array $second) use ($value, $direction): int {
                $order = $value($first) <=> $value($second);

                return ($direction === 'desc' ? -$order : $order) ?: $first['subscription']->id <=> $second['subscription']->id;
            })
            ->values();
    }

    /**
     * The report's figures, over every debtor listed: what they owe in
     * all, how many they are, the average and largest debt, and each age
     * bucket's amount, debtor count and share of the total. Amounts are in
     * shekels.
     *
     * @param  Collection<int, array<string, mixed>>  $debtors
     * @return array{total: float, count: int, average: float, largest: float, buckets: array<string, array{amount: float, count: int, share: int}>}
     */
    private function summary(Collection $debtors): array
    {
        $total = $debtors->sum('balance');
        $count = $debtors->count();

        return [
            'total' => self::shekels($total),
            'count' => $count,
            'average' => $count > 0 ? self::shekels(intdiv($total, $count)) : 0.0,
            'largest' => self::shekels((int) $debtors->max('balance')),
            'buckets' => collect(DebtAging::BUCKETS)->map(function (?int $maxDays, string $bucket) use ($debtors, $total): array {
                $amount = $debtors->sum(fn (array $debtor): int => $debtor['buckets'][$bucket]);

                return [
                    'amount' => self::shekels($amount),
                    'count' => $debtors->filter(fn (array $debtor): bool => $debtor['buckets'][$bucket] > 0)->count(),
                    'share' => $total > 0 ? (int) round($amount / $total * 100) : 0,
                ];
            })->all(),
        ];
    }

    /**
     * @param  array{subscription: Subscription, balance: int, buckets: array<string, int>, oldestDate: ?string, oldestDays: int, lastPaymentDate: ?string, lastPaymentDays: ?int}  $debtor
     * @return array<string, mixed>
     */
    private function row(array $debtor): array
    {
        $subscription = $debtor['subscription'];

        return [
            'id' => $subscription->id,
            'name' => $subscription->displayName(),
            'accountNumber' => $subscription->account_number,
            'phone' => $subscription->contactPhone(),
            'status' => $subscription->status->value,
            'statusLabel' => __($subscription->status->label()),
            'branchName' => $subscription->branch->name,
            'balance' => self::shekels($debtor['balance']),
            'buckets' => array_map(fn (int $cents): float => self::shekels($cents), $debtor['buckets']),
            'oldestDate' => $debtor['oldestDate'],
            'oldestDays' => $debtor['oldestDays'],
            'lastPaymentDate' => $debtor['lastPaymentDate'],
            'lastPaymentDays' => $debtor['lastPaymentDays'],
        ];
    }

    /**
     * What the report covers, shown above its title: every branch, the
     * branch the Super Admin filtered on, or the user's own branch.
     */
    private function scopeLabel(Request $request, User $actor): string
    {
        if (! $actor->isSuperAdmin()) {
            return $actor->workBranch()?->name ?? '—';
        }

        $branchId = $this->filterValue($request, 'branch_id');

        return ($branchId ? Branch::query()->find($branchId)?->name : null) ?? 'كل الفروع';
    }

    /**
     * The filters: the branch (the Super Admin's only — everyone else's
     * report is their own branch), how old the debt is, and the
     * subscription's status.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = $actor->isSuperAdmin() ? [$this->branchFilterGroup()] : [];

        $groups[] = $this->filterGroup('age', 'عمر الدين', collect(self::AGE_FILTERS)->map(fn (string $label, string $days): array => ['value' => $days, 'label' => $label])->values());
        $groups[] = $this->filterGroup('status', 'حالة المشترك', SubscriptionStatus::options());

        return $groups;
    }

    /**
     * One `?filter[key]=` value, or null when it is missing, empty or not
     * plain text.
     */
    private function filterValue(Request $request, string $key): ?string
    {
        $value = $request->input("filter.{$key}");

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function shekels(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
