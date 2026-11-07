<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreSubAreaRequest;
use App\Http\Requests\UpdateSubAreaRequest;
use App\Models\Area;
use App\Models\SubArea;
use App\Notifications\ActionCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubAreaController extends Controller
{
    use DeletesRecords;

    /**
     * Show the form for creating a new resource. Anyone but a Super Admin
     * can only pick their own branch's area.
     */
    public function create(Request $request): InertiaResponse
    {
        $this->authorize('create', SubArea::class);

        return Inertia::render('SubAreas/Create', [
            'areas' => Area::visibleTo($request->user())->orderBy('name')->get(),
            'allowNoArea' => $request->user()->isSuperAdmin(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSubAreaRequest $request): JsonResponse|RedirectResponse
    {
        $subArea = SubArea::create($request->validated());
        $request->user()->notify(new ActionCompleted('sub-area-created', $subArea->name));

        if ($request->expectsJson()) {
            return response()->json($subArea->only(['id', 'name', 'area_id']), 201);
        }

        return redirect()->route('governorates.index', array_filter([
            'selected' => $subArea->area?->governorate_id,
            'selectedArea' => $subArea->area_id,
        ]))->with('status', 'sub-area-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, SubArea $subArea): InertiaResponse
    {
        $this->authorize('update', $subArea);

        return Inertia::render('SubAreas/Edit', [
            'subArea' => $this->editableFields($subArea),
            'areas' => Area::visibleTo($request->user())->orderBy('name')->get(),
            'allowNoArea' => $request->user()->isSuperAdmin(),
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

        return redirect()->route('governorates.index', array_filter([
            'selected' => $subArea->area?->governorate_id,
            'selectedArea' => $subArea->area_id,
        ]))->with('status', 'sub-area-updated');
    }

    /**
     * Delete the sub-area, once nothing uses it any more.
     */
    public function destroy(Request $request, SubArea $subArea): RedirectResponse
    {
        return $this->deleteRecord($request, $subArea, 'sub-area-deleted', $subArea->name);
    }

    /**
     * A sub-area's editable fields — used for the dedicated edit page and
     * for the edit modal's initial form data on the combined governorates
     * page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(SubArea $subArea): array
    {
        return [
            'id' => $subArea->id,
            'name' => $subArea->name,
            'area_id' => $subArea->area_id,
        ];
    }
}
