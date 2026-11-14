<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\SubArea;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Areas are managed through their branch: pick the branch (only the
 * Super Admin has more than their own), then add, rename or remove its
 * areas in place, and open an area's meter boxes. A branch's areas are
 * those in its area container, through the link it already has.
 *
 * Where the data cannot say for certain which branch an area belongs to —
 * a container two branches share, or an area in no branch's container —
 * the Super Admin is shown the cases as they are. Nothing is changed or
 * assigned for them here.
 */
class BranchAreaController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', SubArea::class);

        $branches = Branch::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereKey($actor->workBranchId()))
            ->withCount('subAreas')->orderBy('name')->get(['id', 'name', 'area_id']);
        $branch = $branches->firstWhere('id', $request->integer('selected'))
            ?? $branches->firstWhere('id', $actor->workBranchId())
            ?? $branches->first();
        $sharers = SubArea::branchesByContainer();

        $areas = $branch?->area_id === null ? collect() : SubArea::query()->where('area_id', $branch->area_id)->withCount('meterBoxes')->orderBy('name')->get();
        $present = fn (SubArea $area): array => [
            'id' => $area->id,
            'name' => $area->name,
            'boxesCount' => (int) $area->meter_boxes_count,
            'canUpdate' => $actor->can('update', $area),
            'canDelete' => $actor->can('delete', $area),
        ];

        return Inertia::render('BranchAreas/Index', [
            'branches' => $branches->map(fn (Branch $option): array => ['id' => $option->id, 'name' => $option->name, 'areasCount' => (int) $option->sub_areas_count])->values(),
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'canCreateArea' => $actor->can('create', [SubArea::class, $branch]) && count($sharers[$branch->area_id] ?? []) <= 1,
                'sharedWith' => $branch->area_id === null ? [] : Branch::query()->withoutGlobalScopes()->whereKey(array_diff($sharers[$branch->area_id] ?? [], [$branch->id]))->orderBy('name')->pluck('name')->all(),
            ] : null,
            'areas' => $areas->map($present)->values(),
            'review' => $actor->isSuperAdmin() ? $this->review($sharers) : null,
            'canChooseBranch' => $actor->isSuperAdmin(),
        ]);
    }

    /**
     * What the data leaves undecided, listed as it is: branches sharing one
     * area container (their areas show under each of them), and areas in
     * no branch's container (they show under none).
     *
     * @param  Collection<int, list<int>>  $sharers
     * @return array{sharedContainers: list<array{branches: list<string>, areas: int}>, unattachedAreas: list<array{id: int, name: string, boxesCount: int}>}
     */
    private function review($sharers): array
    {
        $names = Branch::query()->withoutGlobalScopes()->pluck('name', 'id');
        $shared = $sharers->filter(fn (array $ids): bool => count($ids) > 1);

        return [
            'sharedContainers' => $shared->map(fn (array $ids, int $container): array => [
                'branches' => collect($ids)->map(fn (int $id): string => (string) $names[$id])->sort()->values()->all(),
                'areas' => SubArea::query()->where('area_id', $container)->count(),
            ])->values()->all(),
            'unattachedAreas' => SubArea::query()
                ->where(fn ($query) => $query->whereNull('area_id')->orWhereNotIn('area_id', $sharers->keys()->all()))
                ->withCount('meterBoxes')->orderBy('name')->get()
                ->map(fn (SubArea $area): array => ['id' => $area->id, 'name' => $area->name, 'boxesCount' => (int) $area->meter_boxes_count])->all(),
        ];
    }
}
