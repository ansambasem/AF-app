<?php

namespace App\Http\Controllers;

use App\Models\BillingPeriod;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Support\BillingPeriods;
use App\Support\BranchClosingAccess;
use App\Support\DailySeries;
use App\Support\FinancialReportAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A branch's billing periods: the manager sets up the next one ahead (its
 * first and last day), it opens on its first day closing the one before,
 * and the manager approves each closed one for good.
 */
class BillingPeriodController extends Controller
{
    public function __construct(private BillingPeriods $periods) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $branches = $this->branches($actor);
        abort_if($branches->isEmpty(), 403);
        $branch = $branches->firstWhere('id', $request->integer('branch_id')) ?? $branches->firstWhere('id', $actor->workBranchId()) ?? $branches->first();
        $this->periods->refresh($branch->id);
        $periods = BillingPeriod::query()->where('branch_id', $branch->id)->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->withCount(['meterReadings', 'transactions'])->orderByDesc('starts_on')->get();
        $latest = $periods->first();
        $nextStart = $latest ? CarbonImmutable::parse($latest->ends_on->toDateString())->addDay() : DailySeries::today();

        return Inertia::render('BillingPeriods/Index', [
            'branches' => $branches->map(fn (Branch $item): array => ['id' => $item->id, 'name' => $item->name])->values(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'periods' => $periods->map(fn (BillingPeriod $period): array => [
                'id' => $period->id,
                'startsOn' => $period->starts_on->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
                'label' => $period->label(),
                'status' => $period->status(),
                'statusLabel' => $period->statusLabel(),
                'openedAt' => $period->opened_at ? DailySeries::localDate($period->opened_at).' '.DailySeries::localTime($period->opened_at) : null,
                'closedAt' => $period->closed_at ? DailySeries::localDate($period->closed_at).' '.DailySeries::localTime($period->closed_at) : null,
                'approvedAt' => $period->approved_at ? DailySeries::localDate($period->approved_at) : null,
                'approvedBy' => $period->approvedBy?->name,
                'createdBy' => $period->createdBy?->name,
                'readingsCount' => $period->meter_readings_count,
                'linesCount' => $period->transactions_count,
                'notes' => $period->notes,
            ])->values(),
            'suggestion' => ['startsOn' => $nextStart->toDateString(), 'endsOn' => $nextStart->addDays(6)->toDateString()],
            'canManage' => BranchClosingAccess::manage($actor, $branch->id),
            'today' => DailySeries::today()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['ends_on.after_or_equal' => 'تاريخ نهاية الفترة يجب أن يكون بعد تاريخ بدايتها أو مساويًا له.']);
        $branch = $this->managedBranch($request->user(), (int) $data['branch_id']);
        $period = $this->periods->create($branch, CarbonImmutable::parse($data['starts_on']), CarbonImmutable::parse($data['ends_on']), $request->user(), $data['notes'] ?? null);
        $request->user()->notify(new ActionCompleted('billing-period-created', $branch->name.' — '.$period->label()));

        return redirect()->route('billing-periods.index', ['branch_id' => $branch->id])->with('status', 'billing-period-created');
    }

    public function update(Request $request, BillingPeriod $billingPeriod): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], ['ends_on.after_or_equal' => 'تاريخ نهاية الفترة يجب أن يكون بعد تاريخ بدايتها أو مساويًا له.']);
        $this->managedBranch($request->user(), $billingPeriod->branch_id);
        $this->periods->reschedule($billingPeriod, CarbonImmutable::parse($data['starts_on']), CarbonImmutable::parse($data['ends_on']));

        return redirect()->route('billing-periods.index', ['branch_id' => $billingPeriod->branch_id])->with('status', 'billing-period-updated');
    }

    public function approve(Request $request, BillingPeriod $billingPeriod): RedirectResponse
    {
        $branch = $this->managedBranch($request->user(), $billingPeriod->branch_id);
        $this->periods->approve($billingPeriod, $request->user());
        $request->user()->notify(new ActionCompleted('billing-period-approved', $branch->name.' — '.$billingPeriod->label()));

        return redirect()->route('billing-periods.index', ['branch_id' => $branch->id])->with('status', 'billing-period-approved');
    }

    /**
     * The branches whose periods the user may see: those they manage, and
     * those their financial reports cover.
     *
     * @return Collection<int, Branch>
     */
    private function branches(User $actor): Collection
    {
        if ($actor->isSuperAdmin()) {
            return Branch::query()->orderBy('name')->get(['id', 'name']);
        }
        $branches = FinancialReportAccess::view($actor) ? FinancialReportAccess::branches($actor) : new Collection;
        $own = $actor->workBranchId() && BranchClosingAccess::manage($actor, $actor->workBranchId()) ? Branch::query()->whereKey($actor->workBranchId())->get(['id', 'name']) : new Collection;

        return $own->merge($branches)->unique('id')->sortBy('name')->values();
    }

    private function managedBranch(User $actor, int $branchId): Branch
    {
        abort_unless(BranchClosingAccess::manage($actor, $branchId), 403, 'إدارة الفترات لمدير الفرع.');

        return Branch::query()->findOrFail($branchId);
    }
}
