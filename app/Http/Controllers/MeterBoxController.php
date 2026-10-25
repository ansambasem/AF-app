<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Concerns\DeletesRecords;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Requests\StoreMeterBoxRequest;
use App\Http\Requests\UpdateMeterBoxRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\MessageBatch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MeterBoxController extends Controller
{
    use DeletesRecords, FiltersDataTable;

    private const SORTABLE = ['name', 'box_number', 'created_at', 'subscriptions_count', 'debt'];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', MeterBox::class);

        $actor = auth()->user();

        $canViewSubscriptions = $actor->can('viewAny', Subscription::class);
        $balances = Subscription::query()->visibleTo($actor)
            ->select(['subscriptions.id', 'meter_box_id', 'status'])
            ->withSum('transactions as outstanding_balance', 'amount');
        $totals = DB::query()->fromSub($balances, 'subscription_balances')
            ->select('meter_box_id')
            ->selectRaw('COUNT(*) AS subscriptions_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_subscriptions_count', [SubscriptionStatus::Active->value])
            ->selectRaw('SUM(CASE WHEN outstanding_balance > 0 THEN outstanding_balance ELSE 0 END) AS debt')
            ->groupBy('meter_box_id');

        $query = MeterBox::query()->visibleTo($actor)
            ->leftJoinSub($totals, 'subscription_totals', 'meter_boxes.id', '=', 'subscription_totals.meter_box_id')
            ->select('meter_boxes.*')
            ->selectRaw('COALESCE(subscription_totals.subscriptions_count, 0) AS subscriptions_count')
            ->selectRaw('COALESCE(subscription_totals.active_subscriptions_count, 0) AS active_subscriptions_count')
            ->selectRaw('COALESCE(subscription_totals.debt, 0) AS debt')
            ->with('branch.governorate', 'branch.area', 'subArea');
        $query->matchingLabel($this->searchTerm($request));
        $sortable = $canViewSubscriptions ? self::SORTABLE : array_diff(self::SORTABLE, ['debt']);
        $this->applyDataTableFilters($query, $request, [], $sortable, 'box_number');
        $this->applyDataTableFilterSelects($query, $request, ['branch_id', 'sub_area_id']);

        $summary = DB::query()->fromSub((clone $query)->reorder(), 'matching_boxes')
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(subscriptions_count), 0) AS subscriptions')
            ->selectRaw('COALESCE(SUM(active_subscriptions_count), 0) AS active')
            ->selectRaw('COALESCE(SUM(debt), 0) AS debt')
            ->selectRaw('COALESCE(SUM(CASE WHEN subscriptions_count = 0 THEN 1 ELSE 0 END), 0) AS empty_boxes')
            ->first();

        $meterBoxes = $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (MeterBox $meterBox) => [
                ...$this->editableFields($meterBox),
                'branchName' => $meterBox->branch->name,
                'governorateName' => $meterBox->branch->governorate?->name,
                'areaName' => $meterBox->branch->area?->name,
                'subAreaName' => $meterBox->subArea?->name,
                'subscriptionsCount' => (int) $meterBox->subscriptions_count,
                'activeSubscriptionsCount' => (int) $meterBox->active_subscriptions_count,
                'debt' => $canViewSubscriptions ? number_format((float) $meterBox->debt, 2, '.', '') : null,
                'canUpdate' => $actor->can('update', $meterBox),
                'canDelete' => $actor->can('delete', $meterBox),
            ]);

        return Inertia::render('MeterBoxes/Index', [
            'meterBoxes' => $meterBoxes,
            'canCreate' => $actor->can('create', MeterBox::class),
            'canViewSubscriptions' => $canViewSubscriptions,
            'canRecordReadings' => $actor->can('createOnReadingDay', MeterReading::class),
            'canSendMessages' => $actor->can('create', MessageBatch::class),
            'summary' => [
                'total' => (int) $summary->total,
                'subscriptions' => (int) $summary->subscriptions,
                'active' => (int) $summary->active,
                'empty' => (int) $summary->empty_boxes,
                'debt' => $canViewSubscriptions ? number_format((float) $summary->debt, 2, '.', '') : null,
            ],
            'filters' => $this->dataTableState($request, 'box_number'),
            'filterOptions' => $this->filterOptions($actor),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', MeterBox::class);

        return Inertia::render('MeterBoxes/Create', $this->formOptions());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMeterBoxRequest $request): RedirectResponse
    {
        $actor = auth()->user();
        $data = $request->validated();

        if (! $actor->isSuperAdmin()) {
            $data['branch_id'] = $actor->workBranchId();
        }

        $meterBox = MeterBox::create($data);
        $request->user()->notify(new ActionCompleted('meter-box-created', $meterBox->displayName()));

        return redirect()->route('meter-boxes.index')->with('status', 'meter-box-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MeterBox $meterBox): InertiaResponse
    {
        $this->authorize('update', $meterBox);

        return Inertia::render('MeterBoxes/Edit', [
            'meterBox' => $this->editableFields($meterBox),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMeterBoxRequest $request, MeterBox $meterBox): RedirectResponse
    {
        $meterBox->update($request->validated());
        $request->user()->notify(new ActionCompleted('meter-box-updated', $meterBox->displayName()));

        return redirect()->route('meter-boxes.index')->with('status', 'meter-box-updated');
    }

    /**
     * Delete the meter box, once nothing uses it any more.
     */
    public function destroy(Request $request, MeterBox $meterBox): RedirectResponse
    {
        return $this->deleteRecord($request, $meterBox, 'meter-box-deleted', $meterBox->displayName());
    }

    /**
     * A meter box's editable fields — used both for the dedicated edit
     * page and for the edit modal's initial form data on the index page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(MeterBox $meterBox): array
    {
        return [
            'id' => $meterBox->id,
            'name' => $meterBox->name,
            'name_suffix' => $meterBox->name_suffix,
            'display_name' => $meterBox->displayName(),
            'box_number' => $meterBox->box_number,
            'branch_id' => $meterBox->branch_id,
            'sub_area_id' => $meterBox->sub_area_id,
            'location' => $meterBox->location,
        ];
    }

    /**
     * The branch options for the create/edit forms, and whether the actor
     * may choose the branch themselves. A Super Admin also gets every
     * governorate and area, purely to narrow down the branch picker with
     * a cascading select — a meter box's area/governorate always come
     * from whichever branch it belongs to, never stored on the meter box
     * itself. Everyone else has their branch forced server-side, so they
     * don't need any of this — except their own branch's area, so the
     * sub-area picker can still be scoped to it.
     *
     * @return array{branches: Collection, canChooseBranch: bool, governorates: Collection, areas: Collection, subAreas: Collection, currentBranchAreaId: ?int}
     */
    private function formOptions(): array
    {
        $actor = auth()->user();
        $canChooseBranch = $actor->isSuperAdmin();

        return [
            'branches' => $canChooseBranch ? Branch::with(['governorate', 'area'])->orderBy('name')->get() : collect(),
            'canChooseBranch' => $canChooseBranch,
            'governorates' => $canChooseBranch ? Governorate::orderBy('name')->get() : collect(),
            'areas' => $canChooseBranch ? Area::orderBy('name')->get() : collect(),
            'subAreas' => SubArea::orderBy('name')->get(),
            'currentBranchAreaId' => $canChooseBranch ? null : $actor->workBranch()?->area_id,
        ];
    }

    /**
     * The Filter menu's dropdown groups for the index page. The branch
     * filter only makes sense for a Super Admin — everyone else's list is
     * already scoped to their own single branch.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = [
            $this->subAreaFilterGroup(SubArea::visibleTo($actor)->orderBy('name')->get()),
        ];

        if ($actor->isSuperAdmin()) {
            $groups[] = $this->branchFilterGroup();
        }

        return $groups;
    }
}
