<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreAreaRequest;
use App\Http\Requests\UpdateAreaRequest;
use App\Models\Area;
use App\Models\Governorate;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AreaController extends Controller
{
    use DeletesRecords;

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', Area::class);

        return Inertia::render('Areas/Create', [
            'governorates' => Governorate::orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAreaRequest $request): RedirectResponse
    {
        $area = Area::create($request->validated());
        $request->user()->notify(new ActionCompleted('area-created', $area->name));

        return redirect()->route('governorates.index', array_filter(['selected' => $area->governorate_id]))
            ->with('status', 'area-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Area $area): InertiaResponse
    {
        $this->authorize('update', $area);

        return Inertia::render('Areas/Edit', [
            'area' => $this->editableFields($area),
            'governorates' => Governorate::orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified resource in storage, then show the area still
     * selected on the governorates page (when it sits in a governorate),
     * with its sub-areas.
     */
    public function update(UpdateAreaRequest $request, Area $area): RedirectResponse
    {
        $area->update($request->validated());
        $request->user()->notify(new ActionCompleted('area-updated', $area->name));

        $selection = $area->governorate_id ? ['selected' => $area->governorate_id, 'selectedArea' => $area->id] : [];

        return redirect()->route('governorates.index', $selection)
            ->with('status', 'area-updated');
    }

    /**
     * Delete the area, once nothing uses it any more.
     */
    public function destroy(Request $request, Area $area): RedirectResponse
    {
        return $this->deleteRecord($request, $area, 'area-deleted', $area->name);
    }

    /**
     * An area's editable fields — used for the dedicated edit page and for
     * the edit modal's initial form data on the combined governorates page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(Area $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'governorate_id' => $area->governorate_id,
        ];
    }
}
