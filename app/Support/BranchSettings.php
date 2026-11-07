<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The schedule rows (closing, reading) a branch may override: the company's
 * row, which has no branch, and the rows of the branches that have their own.
 * Each kind is read once per request or job (it is registered as scoped), since
 * every period and week calculation asks for it.
 */
class BranchSettings
{
    /**
     * @var array<class-string<Model>, array{company: Model, branches: Collection<int, Model>}>
     */
    private array $loaded = [];

    /**
     * The row in force for the branch: its own, or else the company's.
     *
     * @template TSetting of Model
     *
     * @param  class-string<TSetting>  $model
     * @return TSetting
     */
    public function resolve(string $model, ?int $branchId): Model
    {
        return $this->own($model, $branchId) ?? $this->rows($model)['company'];
    }

    /**
     * The branch's own row, or null while it follows the company's.
     *
     * @template TSetting of Model
     *
     * @param  class-string<TSetting>  $model
     * @return TSetting|null
     */
    public function own(string $model, ?int $branchId): ?Model
    {
        return $branchId === null ? null : $this->rows($model)['branches']->get($branchId);
    }

    /**
     * How many branches have a row of their own.
     *
     * @param  class-string<Model>  $model
     */
    public function ownCount(string $model): int
    {
        return $this->rows($model)['branches']->count();
    }

    /**
     * Read the rows again the next time they are asked for, once one changes.
     *
     * @param  class-string<Model>  $model
     */
    public function forget(string $model): void
    {
        unset($this->loaded[$model]);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{company: Model, branches: Collection<int, Model>}
     */
    private function rows(string $model): array
    {
        return $this->loaded[$model] ??= $this->load($model);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{company: Model, branches: Collection<int, Model>}
     */
    private function load(string $model): array
    {
        $rows = $model::query()->oldest('id')->get();

        return [
            'company' => $rows->first(fn (Model $row): bool => $row->branch_id === null) ?? $model::createCompanyDefault(),
            'branches' => $rows->whereNotNull('branch_id')->keyBy(fn (Model $row): int => (int) $row->branch_id),
        ];
    }
}
