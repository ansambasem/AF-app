<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\SubArea;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Areas are managed through their branch: pick the branch (only the
 * Super Admin has more than their own), then add, rename or remove its
 * areas in place, and open an area's meter boxes. Areas that belong to no
 * branch yet (left so by the move from governorates) are listed for the
 * Super Admin to assign.
 */
class BranchAreaController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', SubArea::class);

        $branches = Branch::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereKey($actor->workBranchId()))
            ->withCount('subAreas')->orderBy('name')->get(['id', 'name']);
        $branch = $branches->firstWhere('id', $request->integer('selected'))
            ?? $branches->firstWhere('id', $actor->workBranchId())
            ?? $branches->first();

        $areas = $branch === null ? collect() : SubArea::query()->where('branch_id', $branch->id)->withCount('meterBoxes')->orderBy('name')->get();
        $unassigned = $actor->isSuperAdmin() ? SubArea::query()->whereNull('branch_id')->withCount('meterBoxes')->orderBy('name')->get() : collect();
        $present = fn (SubArea $area): array => [
            'id' => $area->id,
            'name' => $area->name,
            'branch_id' => $area->branch_id,
            'boxesCount' => (int) $area->meter_boxes_count,
            'canUpdate' => $actor->can('update', $area),
            'canDelete' => $actor->can('delete', $area),
        ];

        return Inertia::render('BranchAreas/Index', [
            'branches' => $branches->map(fn (Branch $option): array => ['id' => $option->id, 'name' => $option->name, 'areasCount' => (int) $option->sub_areas_count])->values(),
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'canCreateArea' => $actor->can('create', [SubArea::class, $branch])] : null,
            'areas' => $areas->map($present)->values(),
            'unassignedAreas' => $unassigned->map($present)->values(),
            'canChooseBranch' => $actor->isSuperAdmin(),
        ]);
    }
}
