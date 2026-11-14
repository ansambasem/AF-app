<?php

namespace App\Models;

use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\DailySeries;
use Carbon\CarbonInterface;
use Database\Factories\ReadingCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reading week of a branch, prepared ahead: its meter boxes are
 * allocated to field readers or to paper sheets, and readings for it are
 * entered on its reading day only. That day is the week's reading day (the
 * branch's own schedule) unless moved for this cycle — a branch with a
 * problem that week may read a day or more later, never before the week
 * ends.
 */
#[Fillable(['branch_id', 'week_start', 'week_end', 'reading_date', 'notes', 'created_by', 'closed_at', 'closed_by'])]
class ReadingCycle extends Model
{
    /** @use HasFactory<ReadingCycleFactory> */
    use BelongsToBranch, HasFactory;

    /** How many days after the reading day an upload still counts as on time. */
    public const SYNC_GRACE_DAYS = 2;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'reading_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReadingAssignment::class);
    }

    public function paperSheets(): HasMany
    {
        return $this->hasMany(PaperSheet::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ReadingSubmission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * The current branch schedule (or the company default) determines the
     * reading day, even when this cycle was prepared before it changed.
     *
     * @return array{weekEnd: CarbonInterface, min: CarbonInterface, max: CarbonInterface}
     */
    public function readingDateBounds(): array
    {
        $weekEnd = MeterReading::weekEndFor($this->week_start, $this->branch_id);

        return [
            'weekEnd' => $weekEnd,
            'min' => $weekEnd->copy()->max(DailySeries::today()),
            'max' => $weekEnd->copy()->addDays(6),
        ];
    }

    /**
     * Where the cycle stands today: preparing before its reading day, the
     * reading day itself, ended after it, or closed.
     */
    public function stage(?CarbonInterface $today = null): string
    {
        $today ??= DailySeries::today();

        return match (true) {
            $this->isClosed() => 'closed',
            $this->reading_date->isSameDay($today) => 'reading_day',
            $this->reading_date->gt($today) => 'preparing',
            default => 'ended',
        };
    }

    /**
     * Whether readings may be entered on the given business date.
     */
    public function isReadingDay(CarbonInterface $date): bool
    {
        return ! $this->isClosed() && $this->reading_date->toDateString() === $date->toDateString();
    }

    /**
     * The latest moment an upload counts as on time: the end of the business
     * day SYNC_GRACE_DAYS after the reading day.
     */
    public function syncDeadline(): CarbonInterface
    {
        return $this->reading_date->copy()->shiftTimezone(config('app.business_timezone'))->addDays(self::SYNC_GRACE_DAYS)->endOfDay();
    }

    /**
     * The meter boxes of the cycle's branch that have active subscriptions:
     * the ones to be read.
     *
     * @return Collection<int, MeterBox>
     */
    public function eligibleMeterBoxes(): Collection
    {
        return MeterBox::query()
            ->where('branch_id', $this->branch_id)
            ->whereHas('subscriptions', fn (Builder $subscriptions) => $subscriptions->where('status', SubscriptionStatus::Active))
            ->withCount(['subscriptions as active_subscriptions_count' => fn (Builder $subscriptions) => $subscriptions->where('status', SubscriptionStatus::Active)])
            ->with('subArea')
            ->orderBy('name')
            ->orderBy('name_suffix')
            ->orderBy('box_number')
            ->get();
    }

    /**
     * The branch's active staff who may take boxes to the field: those who
     * may record readings.
     *
     * @return Collection<int, User>
     */
    public function eligibleReaders(): Collection
    {
        return User::query()
            ->where('branch_id', $this->branch_id)
            ->where('is_active', true)
            ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('key', PermissionKey::RecordMeterReadings->value))
            ->orderBy('name')
            ->get();
    }

    /**
     * The branch's cycle before this one, if any.
     */
    public function previous(): ?self
    {
        return self::query()
            ->where('branch_id', $this->branch_id)
            ->whereDate('week_start', '<', $this->week_start->toDateString())
            ->latest('week_start')
            ->first();
    }

    /**
     * The allocations made, without the boxes put back in the pool.
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('status', '!=', ReadingAssignmentStatus::Unassigned);
    }
}
