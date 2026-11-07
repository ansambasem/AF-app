<?php

namespace App\Http\Concerns;

use App\Models\Area;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Adds search, sort, filter, and adjustable page-size support to an index
 * query, plus helpers for building the Filter menu's dropdowns — shared
 * across every list page's controller.
 */
trait FiltersDataTable
{
    /**
     * The page sizes a visitor may pick from.
     */
    private const PAGE_SIZES = [15, 25, 50, 100];

    /**
     * The most rows a printout of every matching row holds.
     */
    public const PRINT_PAGE_SIZE = 2000;

    /**
     * Apply a `search` term (across the given columns) and a `sort` +
     * `direction` pair (restricted to the given allow-list) to the query.
     * A searchable column may be an expression, such as a subquery for a
     * related table's column.
     *
     * @param  array<int, string|Expression>  $searchableColumns
     * @param  array<int, string>  $sortableColumns
     */
    protected function applyDataTableFilters(
        Builder $query,
        Request $request,
        array $searchableColumns,
        array $sortableColumns,
        string $defaultSort,
        string $defaultDirection = 'asc',
    ): Builder {
        $search = $this->searchTerm($request);

        if ($search !== '' && $searchableColumns !== []) {
            $query->where(function (Builder $inner) use ($search, $searchableColumns) {
                foreach ($searchableColumns as $column) {
                    $inner->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $sort = (string) $request->string('sort');

        if (in_array($sort, $sortableColumns, true)) {
            $query->orderBy($sort, $this->sortDirection($request));
        } else {
            $query->orderBy($defaultSort, $defaultDirection);
        }

        return $query;
    }

    /**
     * Apply simple exact-match filters from `?filter[column]=value` — e.g. a
     * status or role dropdown in the table's Filter menu. Only columns
     * named in `$allowedColumns` are honored, so a client can't filter on
     * an arbitrary column. An empty/missing value for a column is a no-op.
     *
     * @param  array<int, string>  $allowedColumns
     */
    protected function applyDataTableFilterSelects(Builder $query, Request $request, array $allowedColumns): Builder
    {
        $filters = (array) $request->input('filter', []);

        foreach ($allowedColumns as $column) {
            $value = $filters[$column] ?? null;

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * The validated page size, restricted to a fixed allow-list. A printout
     * of every matching row (`?print_all=1`, see resources/js/lib/print.js)
     * gets them all on one page, up to PRINT_PAGE_SIZE.
     */
    protected function dataTablePerPage(Request $request, int $default = 15): int
    {
        if ($request->boolean('print_all')) {
            return self::PRINT_PAGE_SIZE;
        }

        $perPage = (int) $request->input('per_page', $default);

        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : $default;
    }

    /**
     * The current filter state, echoed back to the page so the search box,
     * sort indicators, and page-size selector stay in sync with the URL.
     *
     * @return array{search: string, sort: string, direction: string, per_page: int, filter: array<string, string>}
     */
    protected function dataTableState(Request $request, string $defaultSort, string $defaultDirection = 'asc', int $defaultPerPage = 15): array
    {
        $sort = (string) $request->string('sort');

        return [
            'search' => $this->searchTerm($request),
            'sort' => $sort !== '' ? $sort : $defaultSort,
            'direction' => $sort !== '' ? $this->sortDirection($request) : $defaultDirection,
            'per_page' => $this->dataTablePerPage($request, $defaultPerPage),
            'filter' => (array) $request->input('filter', []),
        ];
    }

    /**
     * One dropdown in the table's Filter menu. `$key` is the column (or
     * custom filter name) sent back as `?filter[key]=value`.
     *
     * @param  iterable<int, array{value: string, label: string}>  $options
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string}>}
     */
    protected function filterGroup(string $key, string $label, iterable $options): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'options' => collect($options)->values()->all(),
        ];
    }

    /**
     * Models as dropdown options: the id as the value, and the given
     * attribute — or whatever the callback returns — as the label.
     *
     * @param  iterable<int, Model>  $models
     * @param  string|Closure(Model): string  $label
     * @return array<int, array{value: string, label: string}>
     */
    protected function modelOptions(iterable $models, string|Closure $label = 'name'): array
    {
        return collect($models)
            ->map(fn (Model $model) => [
                'value' => (string) $model->getKey(),
                'label' => $label instanceof Closure ? $label($model) : $model->{$label},
            ])
            ->values()
            ->all();
    }

    /**
     * Apply `?filter[meter_box_name]=…`: every subscription on a box with
     * that name, whatever the box's suffix or number.
     */
    protected function applyMeterBoxNameFilter(Builder $query, Request $request): Builder
    {
        $name = ((array) $request->input('filter', []))['meter_box_name'] ?? null;

        if (is_string($name) && $name !== '') {
            $query->whereHas('meterBox', fn (Builder $box) => $box->where('name', $name));
        }

        return $query;
    }

    /**
     * The meter box filter as two linked dropdowns: the box name, then —
     * shown under it once a name is picked — that name's boxes by suffix
     * and number, e.g. "1 (1234)". Each box option names its `parent`, so
     * the page lists only the chosen name's boxes. Both carry the branch,
     * sub-area and area their boxes are in (`scope`), so picking one of
     * those lists only its boxes.
     *
     * This lists every box it is given, so it suits only a short list; a
     * page that reloads with each filter change uses
     * meterBoxFilterGroupsFor(), which never grows with the boxes.
     *
     * @param  iterable<int, MeterBox>  $boxes
     * @param  (Closure(MeterBox): string)|null  $context  extra text after a box, e.g. its branch
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string, parent?: string, scope?: array<string, string|array<int, string>>}>, dependsOn?: string}>
     */
    protected function meterBoxFilterGroups(iterable $boxes, ?Closure $context = null): array
    {
        $boxes = (new EloquentCollection(collect($boxes)->all()))->loadMissing('subArea');
        // A box without a name has nothing to pick it by: listing it would add a blank option whose empty value is also "no filter", so it would show as ticked.
        $placements = $boxes->filter(fn (MeterBox $box): bool => filled($box->name))
            ->map(fn (MeterBox $box): object => (object) [
                'name' => $box->name,
                'branch_id' => $box->branch_id,
                'sub_area_id' => $box->sub_area_id,
                'area_id' => $box->subArea?->area_id,
            ]);

        return $this->linkedMeterBoxGroups($this->meterBoxNameOptions($placements), $this->meterBoxNumberOptions($boxes, $context));
    }

    /**
     * The meter box filter for a page that reloads with every filter
     * change: every box name (one query over the distinct names and where
     * they are, not every box), and the box numbers of the picked name only
     * — or of the picked box's name, for a link that carries just the box.
     * So what a page sends never grows with the number of boxes: a company
     * with thousands of boxes lists the names, and a name's own boxes once
     * it is picked.
     *
     * @param  (Closure(MeterBox): string)|null  $context  extra text after a box, e.g. its branch
     * @return array<int, array{key: string, label: string, options: array<int, array<string, mixed>>, dependsOn?: string}>
     */
    protected function meterBoxFilterGroupsFor(User $actor, Request $request, ?Closure $context = null): array
    {
        $context = filled($request->input('filter.branch_id')) ? null : $context;

        $placements = MeterBox::query()
            ->visibleTo($actor)
            ->whereNotNull('meter_boxes.name')
            ->where('meter_boxes.name', '!=', '')
            ->leftJoin('sub_areas', 'sub_areas.id', '=', 'meter_boxes.sub_area_id')
            ->distinct()
            ->toBase()
            ->get(['meter_boxes.name', 'meter_boxes.branch_id', 'meter_boxes.sub_area_id', 'sub_areas.area_id']);
        $pickedName = $this->pickedMeterBoxName($actor, $request);
        $boxes = $pickedName === null
            ? new EloquentCollection
            : MeterBox::query()->visibleTo($actor)->with(['branch', 'subArea'])->where('name', $pickedName)->get();

        return $this->linkedMeterBoxGroups($this->meterBoxNameOptions($placements), $this->meterBoxNumberOptions($boxes, $context));
    }

    /**
     * The box name the filter shows boxes of: `?filter[meter_box_name]`, or
     * else the name of the box `?filter[meter_box_id]` picks. Null for none.
     */
    private function pickedMeterBoxName(User $actor, Request $request): ?string
    {
        $filter = (array) $request->input('filter', []);
        $name = $filter['meter_box_name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $boxId = $filter['meter_box_id'] ?? null;

        if (! is_string($boxId) || ! ctype_digit($boxId)) {
            return null;
        }

        $boxName = MeterBox::query()->visibleTo($actor)->whereKey((int) $boxId)->value('name');

        return filled($boxName) ? $boxName : null;
    }

    /**
     * The box name and box number dropdowns, the numbers shown beside the name.
     *
     * @param  Collection<int, array<string, mixed>>  $names
     * @param  Collection<int, array<string, mixed>>  $numbers
     * @return array<int, array{key: string, label: string, options: array<int, array<string, mixed>>, dependsOn?: string}>
     */
    private function linkedMeterBoxGroups(Collection $names, Collection $numbers): array
    {
        return [
            $this->filterGroup('meter_box_name', 'الطبلون', $names),
            [...$this->filterGroup('meter_box_id', 'رقم الطبلون', $numbers), 'dependsOn' => 'meter_box_name'],
        ];
    }

    /**
     * One option per box name, each with the branches, sub-areas and areas
     * its boxes are in.
     *
     * @param  Collection<int, object{name: string, branch_id: int|null, sub_area_id: int|null, area_id: int|null}>  $placements  where each box is
     * @return Collection<int, array<string, mixed>>
     */
    private function meterBoxNameOptions(Collection $placements): Collection
    {
        return $placements
            ->groupBy('name')
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Collection $named, int|string $name): array => [
                'value' => (string) $name,
                'label' => (string) $name,
                'scope' => $this->filterScope([
                    'branch_id' => $named->pluck('branch_id')->all(),
                    'sub_area_id' => $named->pluck('sub_area_id')->all(),
                    'area_id' => $named->pluck('area_id')->all(),
                ]),
            ])
            ->values();
    }

    /**
     * One option per box, by suffix and number, naming its box name as
     * `parent` and where it is as `scope`.
     *
     * @param  iterable<int, MeterBox>  $boxes
     * @param  (Closure(MeterBox): string)|null  $context  extra text after a box, e.g. its branch
     * @return Collection<int, array<string, mixed>>
     */
    private function meterBoxNumberOptions(iterable $boxes, ?Closure $context): Collection
    {
        return collect($boxes)
            ->sortBy([['name_suffix', 'asc'], ['box_number', 'asc']], SORT_NATURAL)
            ->map(fn (MeterBox $box): array => [
                'value' => (string) $box->getKey(),
                'label' => ltrim($box->name_suffix.' ('.$box->box_number.')').($context ? ' — '.$context($box) : ''),
                'parent' => $box->name,
                'scope' => $this->filterScope([
                    'branch_id' => $box->branch_id,
                    'sub_area_id' => $box->sub_area_id,
                    'area_id' => $box->subArea?->area_id,
                ]),
            ])
            ->values();
    }

    /**
     * The "Area" dropdown, each area tied to its governorate and to the
     * branches in it, so picking a governorate or a branch lists only its
     * areas.
     *
     * @param  iterable<int, Area>  $areas
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string, scope: array<string, string|array<int, string>>}>}
     */
    protected function areaFilterGroup(iterable $areas): array
    {
        $branchAreas = Branch::query()->pluck('area_id', 'id');

        return $this->filterGroup('area_id', 'المنطقة', collect($areas)->map(fn (Area $area) => [
            'value' => (string) $area->getKey(),
            'label' => $area->name,
            'scope' => $this->filterScope([
                'governorate_id' => $area->governorate_id,
                'branch_id' => $branchAreas->filter(fn ($areaId) => $areaId === $area->id)->keys()->all(),
            ]),
        ]));
    }

    /**
     * The "Sub-area" dropdown (منطقة 2), each tied to its area and to the
     * branches of that area, so picking an area or a branch lists only its
     * sub-areas.
     *
     * @param  iterable<int, SubArea>  $subAreas
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string, scope: array<string, string|array<int, string>>}>}
     */
    protected function subAreaFilterGroup(iterable $subAreas): array
    {
        $branchAreas = Branch::query()->pluck('area_id', 'id');

        return $this->filterGroup('sub_area_id', 'منطقة 2', collect($subAreas)->map(fn (SubArea $subArea) => [
            'value' => (string) $subArea->getKey(),
            'label' => $subArea->name,
            'scope' => $this->filterScope([
                'area_id' => $subArea->area_id,
                'branch_id' => $branchAreas->filter(fn ($areaId) => $areaId === $subArea->area_id)->keys()->all(),
            ]),
        ]));
    }

    /**
     * Staff as dropdown options, each tied to their branch, so picking a
     * branch lists only its staff. Staff without a branch (the Super
     * Admins) stay listed whatever the branch.
     *
     * @param  iterable<int, User>  $users
     * @return array<int, array{value: string, label: string, scope?: array<string, string>}>
     */
    protected function staffOptions(iterable $users): array
    {
        return collect($users)->map(fn (User $user) => [
            'value' => (string) $user->getKey(),
            'label' => $user->name,
            ...($user->workBranchId() ? ['scope' => $this->filterScope(['branch_id' => $user->workBranchId()])] : []),
        ])->values()->all();
    }

    /**
     * An option's `scope`: the other filters' values it belongs to — one
     * id, or several — as strings, the way the Filter menu compares them.
     * The menu lists an option only while every chosen filter it names
     * matches. A missing one (null) is left out, so it never hides the
     * option; an empty list means it belongs to none of them.
     *
     * @param  array<string, int|string|null|array<int, int|string|null>>  $scope
     * @return array<string, string|array<int, string>>
     */
    protected function filterScope(array $scope): array
    {
        return collect($scope)
            ->map(fn ($ids) => is_array($ids)
                ? collect($ids)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (string) $id)->unique()->values()->all()
                : ($ids === null || $ids === '' ? null : (string) $ids))
            ->reject(fn ($ids) => $ids === null)
            ->all();
    }

    /**
     * Apply `?filter[circuit_breaker_id]=…` to a subscriptions query: the
     * subscriptions on that circuit breaker, or with none for `none`.
     */
    protected function applyCircuitBreakerFilter(Builder $query, Request $request): Builder
    {
        $value = ((array) $request->input('filter', []))['circuit_breaker_id'] ?? null;
        $column = $query->qualifyColumn('circuit_breaker_id');

        if ($value === 'none') {
            $query->whereNull($column);
        } elseif (is_string($value) && ctype_digit($value)) {
            $query->where($column, (int) $value);
        }

        return $query;
    }

    /**
     * The "Circuit breaker" dropdown — every breaker by its size, smallest
     * first, plus the subscriptions without one.
     *
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string}>}
     */
    protected function circuitBreakerFilterGroup(): array
    {
        return $this->filterGroup('circuit_breaker_id', 'القاطع', [
            ['value' => 'none', 'label' => 'بدون قاطع'],
            ...$this->modelOptions(CircuitBreaker::orderBy('ampere')->get(), fn (CircuitBreaker $breaker) => $breaker->ampere.' أمبير'),
        ]);
    }

    /**
     * The "Branch" dropdown — every branch, by name.
     *
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string}>}
     */
    protected function branchFilterGroup(?User $actor = null): array
    {
        $branches = Branch::query()->orderBy('name');
        if ($actor) {
            $actor->scopeAuditBranches($branches, 'branches.id');
        }

        return $this->filterGroup('branch_id', 'الفرع', $this->modelOptions($branches->get()));
    }

    /**
     * The "Status" dropdown for an `is_active` column.
     *
     * @return array{key: string, label: string, options: array<int, array{value: string, label: string}>}
     */
    protected function activeStatusFilterGroup(): array
    {
        return $this->filterGroup('is_active', 'الحالة', [
            ['value' => '1', 'label' => 'نشط'],
            ['value' => '0', 'label' => 'متوقف'],
        ]);
    }

    private function searchTerm(Request $request): string
    {
        return trim((string) $request->string('search'));
    }

    private function sortDirection(Request $request): string
    {
        return $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc';
    }
}
