<?php

namespace App\Http\Controllers;

use App\Enums\PermissionKey;
use App\Http\Concerns\DeletesRecords;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\ProvidesFormOptions;
use App\Http\Concerns\ReturnsToDashboard;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Notifications\ActionCompleted;
use App\Support\BranchDomainContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BranchController extends Controller
{
    use DeletesRecords, FiltersDataTable, ProvidesFormOptions, ReturnsToDashboard;

    private const SORTABLE = ['name', 'location', 'phone', 'is_active', 'created_at'];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Branch::class);

        $actor = $request->user();
        $query = Branch::query()->with(['governorate', 'area']);
        $this->applyDataTableFilters($query, $request, ['name', 'location', 'phone'], self::SORTABLE, 'name');
        $this->applyDataTableFilterSelects($query, $request, ['is_active', 'governorate_id', 'area_id']);

        $branches = $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (Branch $branch) => [
                ...$branch->toArray(),
                'canUpdate' => $actor->can('update', $branch),
                'canDelete' => $actor->can('delete', $branch),
                'url' => BranchDomainContext::branchUrl($branch),
            ]);

        return Inertia::render('Branches/Index', [
            'branches' => $branches,
            'canCreate' => $actor->can('create', Branch::class),
            'canManageDomains' => $actor->hasPermission(PermissionKey::ManageBranchDomains),
            'domainRoutingEnabled' => (bool) config('powercollect.branch_domains_enabled'),
            'filters' => $this->dataTableState($request, 'name'),
            'filterOptions' => $this->filterOptions(),
            ...$this->branchFormOptions(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('Branches/Create', $this->branchFormOptions());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());
        $request->user()->notify(new ActionCompleted('branch-created', $branch->name));

        return $this->redirectAfterSave($request, 'branches.index', 'branch-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Branch $branch): InertiaResponse
    {
        $this->authorize('update', $branch);

        return Inertia::render('Branches/Edit', [
            'branch' => $branch,
            ...$this->branchFormOptions(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());
        $request->user()->notify(new ActionCompleted('branch-updated', $branch->name));

        return redirect()->route('branches.index')->with('status', 'branch-updated');
    }

    /**
     * Delete the branch, once nothing uses it any more.
     */
    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        return $this->deleteRecord($request, $branch, 'branch-deleted', $branch->name);
    }

    /**
     * The Filter menu's dropdown groups for the index page.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(): array
    {
        return [
            $this->activeStatusFilterGroup(),
            $this->filterGroup('governorate_id', 'المحافظة', $this->modelOptions(Governorate::orderBy('name')->get())),
            $this->areaFilterGroup(Area::orderBy('name')->get()),
        ];
    }
}
