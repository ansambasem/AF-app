<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreSubAreaRequest;
use App\Http\Requests\UpdateSubAreaRequest;
use App\Models\Branch;
use App\Models\SubArea;
use App\Notifications\ActionCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * Store a newly created resource in storage.
     */
    public function store(StoreSubAreaRequest $request): JsonResponse|RedirectResponse
    {
        $subArea = SubArea::create($request->validated());
        $request->user()->notify(new ActionCompleted('sub-area-created', $subArea->name));

        if ($request->expectsJson()) {
            return response()->json($subArea->only(['id', 'name', 'branch_id']), 201);
        }

        return redirect()->route('branch-areas.index', ['selected' => $subArea->branch_id])->with('status', 'sub-area-created');
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
     * Update the specified resource in storage.
     */
    public function update(UpdateSubAreaRequest $request, SubArea $subArea): RedirectResponse
    {
        $subArea->update($request->validated());
        $subArea->refresh();
        $request->user()->notify(new ActionCompleted('sub-area-updated', $subArea->name));

        return redirect()->route('branch-areas.index', ['selected' => $subArea->branch_id])->with('status', 'sub-area-updated');
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
            'branch_id' => $subArea->branch_id,
        ];
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
