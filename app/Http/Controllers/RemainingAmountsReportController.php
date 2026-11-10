<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Models\Subscription;
use App\Support\BillingPeriods;
use App\Support\DailySeries;
use App\Support\FinancialReportAccess;
use App\Support\PeriodPicker;
use App\Support\PeriodStatement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The remaining amounts report (تقرير المبالغ المتبقية): for a billing
 * period, each subscription's reading, invoice, accumulated or advance,
 * discounts, charges, payments and what remains, with their totals, and the
 * same totals for each branch. Each branch's figures are those of its own
 * period that the chosen date falls in (the open one by default), and its
 * totals are the sum of its subscriptions' rows, so the two always agree.
 */
class RemainingAmountsReportController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private BillingPeriods $periods, private PeriodStatement $statement) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        abort_unless(FinancialReportAccess::view($actor), 403);
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', SubscriptionStatus::Active->value, SubscriptionStatus::Disconnected->value])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['remaining', 'name', 'account'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branches = FinancialReportAccess::branches($actor);
        $chosen = filled($filters['branch_id'] ?? null) ? $branches->where('id', (int) $filters['branch_id'])->values() : $branches;
        $date = filled($filters['date'] ?? null) ? CarbonImmutable::parse($filters['date']) : null;
        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'remaining';

        $periods = $chosen->mapWithKeys(fn (Branch $branch): array => [$branch->id => $date === null ? $this->periods->open($branch->id) : $this->periods->containing($branch->id, $date)]);
        $byBranch = $chosen->map(function (Branch $branch) use ($periods, $status, $filters): array {
            $period = $periods[$branch->id];
            $rows = $period ? $this->statement->rows($period, $status === 'all' ? null : $status, $filters['search'] ?? null) : collect();

            return ['branch' => $branch, 'period' => $period, 'rows' => $rows];
        });
        $rows = $byBranch->flatMap(fn (array $group): Collection => $group['rows']->map(fn (array $row): array => [...$row, 'branchName' => $group['branch']->name])->values());
        $rows = (match ($sort) {
            'name' => $rows->sortBy('name', SORT_NATURAL),
            'account' => $rows->sortBy(fn (array $row): string => str_pad((string) $row['accountNumber'], 20, '0', STR_PAD_LEFT)),
            default => $rows->sortByDesc('remaining'),
        })->values();
        $page = (int) ($filters['page'] ?? 1);

        return Inertia::render('Reports/RemainingAmounts', [
            'filters' => ['date' => $filters['date'] ?? '', 'branch_id' => (string) ($filters['branch_id'] ?? ''), 'status' => $status, 'search' => $filters['search'] ?? '', 'sort' => $sort],
            'branches' => $branches->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->values(),
            'periodChoices' => $this->periodChoices($chosen, $filters['date'] ?? '', $periods->filter()->sortByDesc('created_at')->first()),
            'summary' => PeriodStatement::present(PeriodStatement::summarize($rows)),
            'branchRows' => $byBranch->map(fn (array $group): array => [
                'id' => $group['branch']->id,
                'name' => $group['branch']->name,
                'period' => $group['period'] ? ['label' => $group['period']->label(), 'status' => $group['period']->status(), 'statusLabel' => $group['period']->statusLabel()] : null,
                ...PeriodStatement::present(PeriodStatement::summarize($group['rows'])),
            ])->values(),
            'rows' => new LengthAwarePaginator(
                $rows->forPage($page, self::PER_PAGE)->map(fn (array $row): array => [...PeriodStatement::present($row), ...PeriodStatement::rates($row)])->values(),
                $rows->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()],
            ),
            'today' => DailySeries::today()->toDateString(),
        ]);
    }

    /**
     * One subscription's period settled payment by payment: its row, its
     * rates, and the part of each payment that went to the invoice, the
     * accumulated debt or the new advance. The period is its branch's one
     * that the date falls in (the open one without a date).
     */
    public function payments(Request $request, Subscription $subscription): JsonResponse
    {
        $actor = $request->user();
        abort_unless(FinancialReportAccess::view($actor), 403);
        abort_unless(FinancialReportAccess::branches($actor)->contains('id', $subscription->branch_id), 403);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        $period = $date === null ? $this->periods->open($subscription->branch_id) : $this->periods->containing($subscription->branch_id, CarbonImmutable::parse($date));
        $detail = $period ? $this->statement->detail($period, $subscription->id) : null;
        abort_if($detail === null, 404);

        return response()->json([...$detail, 'period' => ['label' => $period->label(), 'statusLabel' => $period->statusLabel()]]);
    }

    /**
     * The periods to pick from, by the year and month they were created:
     * each distinct span the branches had, by its first day (the date that
     * picks every branch's period it falls in). With no date picked, the
     * newest of the branches' open periods is the one shown.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function periodChoices(Collection $branches, string $date, ?BillingPeriod $shown): array
    {
        $periods = $branches->flatMap(fn (Branch $branch) => $this->periods->of($branch->id)->filter(fn (BillingPeriod $period): bool => $period->opened_at !== null))
            ->sortBy(fn (BillingPeriod $period): string => $period->created_at->format('YmdHis'))
            ->unique(fn (BillingPeriod $period): string => $period->starts_on->toDateString().$period->ends_on->toDateString())
            ->values();

        return PeriodPicker::choices($periods, fn (BillingPeriod $period): string => $period->starts_on->toDateString(), $date, $date === '' ? $shown : null);
    }
}
