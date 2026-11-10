<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreSubAreaRequest;
use App\Http\Requests\UpdateSubAreaRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\SubArea;
use App\Notifications\ActionCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubAreaController extends Controller
{
    use DeletesRecords;

    /**
     * The form for a new area: the Super Admin picks its branch; anyone
     * else adds to the branch they work in.
     */
    public function create(Request $request): InertiaResponse
    {
        $this->authorize('create', SubArea::class);

        return Inertia::render('SubAreas/Create', $this->branchChoices($request));
    }

    /**
     * Add an area to the branch: into the branch's area container, the one
     * the branch already has. A branch that has none yet gets its own, made
     * now for it alone; a container another branch shares is refused, so no
     * area is ever put under two branches.
     */
    public function store(StoreSubAreaRequest $request): JsonResponse|RedirectResponse
    {
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));

        if ($branch->area_id !== null && Branch::query()->withoutGlobalScopes()->where('area_id', $branch->area_id)->count() > 1) {
            throw ValidationException::withMessages(['branch_id' => 'مناطق هذا الفرع مسجّلة مع فرع آخر في النظام القديم؛ راجع «مناطق الفروع» قبل إضافة منطقة.']);
        }

        $subArea = DB::transaction(function () use ($branch, $request): SubArea {
            if ($branch->area_id === null) {
                $branch->area()->associate(Area::query()->create(['name' => $branch->name]))->save();
            }

            return SubArea::query()->create(['name' => $request->validated('name'), 'area_id' => $branch->area_id]);
        });
        $request->user()->notify(new ActionCompleted('sub-area-created', $subArea->name));

        if ($request->expectsJson()) {
            return response()->json([...$subArea->only(['id', 'name', 'area_id']), 'branch_ids' => [$branch->id]], 201);
        }

        return redirect()->route('branch-areas.index', ['selected' => $branch->id])->with('status', 'sub-area-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, SubArea $subArea): InertiaResponse
    {
        $this->authorize('update', $subArea);

        return Inertia::render('SubAreas/Edit', [
            'subArea' => $this->editableFields($subArea),
            ...$this->branchChoices($request),
        ]);
    }

    /**
     * Rename the area; it stays with its branch.
     */
    public function update(UpdateSubAreaRequest $request, SubArea $subArea): RedirectResponse
    {
        $subArea->update(['name' => $request->validated('name')]);
        $request->user()->notify(new ActionCompleted('sub-area-updated', $subArea->name));

        return redirect()->route('branch-areas.index', array_filter(['selected' => $this->branchOf($subArea)]))->with('status', 'sub-area-updated');
    }

    /**
     * Delete the sub-area, once nothing uses it any more.
     */
    public function destroy(Request $request, SubArea $subArea): RedirectResponse
    {
        return $this->deleteRecord($request, $subArea, 'sub-area-deleted', $subArea->name);
    }

    /**
     * An area's editable fields, for its edit page and the edit modal.
     *
     * @return array{id: int, name: string, branch_id: ?int}
     */
    private function editableFields(SubArea $subArea): array
    {
        return [
            'id' => $subArea->id,
            'name' => $subArea->name,
            'branch_id' => $this->branchOf($subArea),
        ];
    }

    /** The branch the area belongs to: the one whose area container holds it, or null when no single branch does. */
    private function branchOf(SubArea $subArea): ?int
    {
        $branches = $subArea->area_id === null ? collect() : Branch::query()->withoutGlobalScopes()->where('area_id', $subArea->area_id)->pluck('id');

        return $branches->count() === 1 ? $branches->first() : null;
    }

    /**
     * The branches an area can belong to: every branch for the Super Admin,
     * otherwise none to pick (theirs is used).
     *
     * @return array{branches: Collection<int, Branch>, canChooseBranch: bool, currentBranchId: ?int}
     */
    private function branchChoices(Request $request): array
    {
        $actor = $request->user();

        return [
            'branches' => $actor->isSuperAdmin() ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'canChooseBranch' => $actor->isSuperAdmin(),
            'currentBranchId' => $actor->workBranchId(),
        ];
    }
}
