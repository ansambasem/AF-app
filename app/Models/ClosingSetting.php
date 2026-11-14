<?php

namespace App\Models;

use App\Models\Concerns\OverridableByBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's box settings and each branch's override. The saved cutoff
 * and workflow fields support existing box sessions; closed records retain
 * their original dates when the period start day changes.
 *
 * The company's row (no branch) holds the default weekday that starts the
 * week (0 = Sunday … 6 = Saturday). A branch admin explicitly authorized by
 * the Super Admin may override their branch's period start day.
 */
#[Fillable(['branch_id', 'cutoff_time', 'week_starts_on', 'auto_open', 'frequency', 'arrangement', 'updated_by', 'workflow_started_at', 'receivers', 'approvers'])]
class ClosingSetting extends Model
{
    use OverridableByBranch;

    public const DEFAULT_CUTOFF = '00:00';

    public const DEFAULT_WEEK_START = 6;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'week_starts_on' => 'integer',
            'auto_open' => 'boolean',
            'workflow_started_at' => 'immutable_datetime',
            'receivers' => 'array',
            'approvers' => 'array',
        ];
    }

    public static function createCompanyDefault(): static
    {
        return static::create(['cutoff_time' => self::DEFAULT_CUTOFF, 'week_starts_on' => self::DEFAULT_WEEK_START, 'auto_open' => true, 'frequency' => 'weekly', 'arrangement' => 'combined']);
    }

    public static function startFor(Branch|int $branch): static
    {
        $defaults = static::company();

        return static::query()->firstOrCreate(['branch_id' => $branch instanceof Branch ? $branch->id : $branch], [
            'week_starts_on' => $defaults->week_starts_on,
            'cutoff_time' => $defaults->cutoff_time,
            'auto_open' => $defaults->auto_open,
            'frequency' => $defaults->frequency,
            'arrangement' => $defaults->arrangement,
        ]);
    }

    /**
     * The cut-off as HH:MM.
     */
    public function cutoff(): string
    {
        return substr((string) $this->cutoff_time, 0, 5);
    }

    /**
     * Minutes after a day's midnight at which it closes: midnight (00:00)
     * closes it at the end of the day.
     */
    public function cutoffMinutes(): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $this->cutoff()));
        $total = $hours * 60 + $minutes;

        return $total === 0 ? 24 * 60 : $total;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
