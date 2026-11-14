<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\BranchSettings;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For a setting kept for the whole company that a branch may override: the
 * row without a branch is the company's, and a branch uses its own row once
 * its admin has saved one, the company's until then. The rows are read once
 * per request or job (see BranchSettings).
 */
trait OverridableByBranch
{
    /**
     * Rows are read once, so read them again once one changes.
     */
    protected static function bootOverridableByBranch(): void
    {
        $model = static::class;
        $forget = fn () => app(BranchSettings::class)->forget($model);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * The company's setting, which the branches without their own follow.
     */
    public static function company(): static
    {
        return app(BranchSettings::class)->resolve(static::class, null);
    }

    /**
     * The setting in force for the branch: its own, or else the company's.
     * With no branch, the company's.
     */
    public static function forBranch(Branch|int|null $branch): static
    {
        return app(BranchSettings::class)->resolve(static::class, $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    /**
     * The branch's own setting, or null while it follows the company's.
     */
    public static function ownFor(Branch|int $branch): ?static
    {
        return app(BranchSettings::class)->own(static::class, $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    /**
     * How many branches have a setting of their own.
     */
    public static function ownCount(): int
    {
        return app(BranchSettings::class)->ownCount(static::class);
    }

    public function isCompanyDefault(): bool
    {
        return $this->branch_id === null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The company's setting, created with the defaults on first use.
     */
    abstract public static function createCompanyDefault(): static;
}
