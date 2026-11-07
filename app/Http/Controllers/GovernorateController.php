<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Requests\StoreGovernorateRequest;
use App\Http\Requests\UpdateGovernorateRequest;
use App\Models\Area;
use App\Models\Governorate;
use App\Models\SubArea;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GovernorateController extends Controller
{
    use DeletesRecords, FiltersDataTable;

    private const SORTABLE = ['name', 'created_at'];

    /**
     * Display a listing of the resource, plus the selected governorate's
     * areas (?selected=<id>) so the whole governorate/area hierarchy can
     * be managed from this one page.
     *
     * Someone who may not see the whole map but does work with sub-areas
     * (a Branch Admin, or staff granted a sub-area permission) gets the
     * page scoped to their branch: its governorate and area only, already
     * selected, so they can manage the sub-areas inside it.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $scopedToBranch = ! $user->can('viewAny', Governorate::class);

        if ($scopedToBranch) {
            $this->authorize('viewAny', SubArea::class);
        }

        $selectedGovernorateId = $scopedToBranch ? $user->branch?->governorate_id : $request->integer('selected');
        $selectedAreaId = $scopedToBranch ? $user->branchAreaId() : $request->integer('selectedArea');

        $query = Governorate::query()
            ->withCount('areas')
            ->when($scopedToBranch, fn (Builder $query) => $query->whereKey($selectedGovernorateId));
        $this->applyDataTableFilters($query, $request, ['name'], self::SORTABLE, 'name');

        $governorates = $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (Governorate $governorate) => [
                ...$this->editableFields($governorate),
                'areasCount' => $governorate->areas_count,
                'canUpdate' => $user->can('update', $governorate),
                'canDelete' => $user->can('delete', $governorate),
            ]);

        return Inertia::render('Governorates/Index', [
            'governorates' => $governorates,
            'selectedGovernorate' => $this->selectedGovernorate($selectedGovernorateId, $user, $scopedToBranch),
            'selectedArea' => $this->selectedArea($selectedAreaId, $user),
            'scopedToBranch' => $scopedToBranch,
            'canCreateGovernorate' => $user->can('create', Governorate::class),
            'filters' => $this->dataTableState($request, 'name'),
            'governorateOptions' => Governorate::orderBy('name')->get(),
            'areaOptions' => Area::visibleTo($user)->orderBy('name')->get(),
            'allowSubAreaWithoutArea' => $user->isSuperAdmin(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', Governorate::class);

        return Inertia::render('Governorates/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGovernorateRequest $request): RedirectResponse
    {
        $governorate = Governorate::create($request->validated());
        $request->user()->notify(new ActionCompleted('governorate-created', $governorate->name));

        return redirect()->route('governorates.index', ['selected' => $governorate->id])->with('status', 'governorate-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Governorate $governorate): InertiaResponse
    {
        $this->authorize('update', $governorate);

        return Inertia::render('Governorates/Edit', [
            'governorate' => $this->editableFields($governorate),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGovernorateRequest $request, Governorate $governorate): RedirectResponse
    {
        $governorate->update($request->validated());
        $request->user()->notify(new ActionCompleted('governorate-updated', $governorate->name));

        return redirect()->route('governorates.index', ['selected' => $governorate->id])->with('status', 'governorate-updated');
    }

    /**
     * Delete the governorate, once nothing uses it any more.
     */
    public function destroy(Request $request, Governorate $governorate): RedirectResponse
    {
        return $this->deleteRecord($request, $governorate, 'governorate-deleted', $governorate->name);
    }

    /**
     * A governorate's editable fields — used both for the dedicated edit
     * page and for the edit modal's initial form data on the index page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(Governorate $governorate): array
    {
        return [
            'id' => $governorate->id,
            'name' => $governorate->name,
        ];
    }

    /**
     * The selected governorate with its areas and how many sub-areas each
     * holds — the second column's data on the combined governorates/areas
     * page; only the user's own branch area when the page is scoped to
     * their branch. Null when nothing is selected (or the id no longer
     * exists).
     *
     * @return array{id: int, name: string, canCreateArea: bool, areas: Collection}|null
     */
    private function selectedGovernorate(?int $selectedId, User $user, bool $scopedToBranch): ?array
    {
        if (! $selectedId) {
            return null;
        }

        $governorate = Governorate::with(['areas' => fn ($query) => $query
            ->when($scopedToBranch, fn (Builder $query) => $query->visibleTo($user))
            ->withCount('subAreas')
            ->orderBy('name')])
            ->find($selectedId);

        if (! $governorate) {
            return null;
        }

        return [
            'id' => $governorate->id,
            'name' => $governorate->name,
            'canCreateArea' => $user->can('create', Area::class),
            'areas' => $governorate->areas->map(fn (Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'governorate_id' => $area->governorate_id,
                'subAreasCount' => $area->sub_areas_count,
                'canUpdate' => $user->can('update', $area),
                'canDelete' => $user->can('delete', $area),
            ]),
        ];
    }

    /**
     * The selected area with its sub-areas — the third panel's data on the
     * combined governorates/areas/sub-areas page — and whether the user may
     * add a sub-area there or edit each one. Null when nothing is selected
     * (or the id no longer exists).
     *
     * @return array{id: int, name: string, governorate_id: ?int, canCreateSubArea: bool, subAreas: Collection}|null
     */
    private function selectedArea(?int $selectedId, User $user): ?array
    {
        if (! $selectedId) {
            return null;
        }

        $area = Area::with(['subAreas' => fn ($query) => $query->orderBy('name')])->find($selectedId);

        if (! $area) {
            return null;
        }

        return [
            'id' => $area->id,
            'name' => $area->name,
            'governorate_id' => $area->governorate_id,
            'canCreateSubArea' => $user->can('create', [SubArea::class, $area]),
            'subAreas' => $area->subAreas->map(fn (SubArea $subArea) => [
                'id' => $subArea->id,
                'name' => $subArea->name,
                'area_id' => $subArea->area_id,
                'canUpdate' => $user->can('update', $subArea),
                'canDelete' => $user->can('delete', $subArea),
            ]),
        ];
    }
}
