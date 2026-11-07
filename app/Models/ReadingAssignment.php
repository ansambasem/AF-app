<?php

namespace App\Models;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ReadingAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meter box's allocation in a reading cycle: to one field reader, to the
 * paper sheets, or back in the available pool. A box has one row per cycle
 * (the database refuses a second), changed in place; every change raises
 * its version, so a field app that downloaded an older one is told when it
 * uploads.
 */
#[Fillable(['reading_cycle_id', 'meter_box_id', 'branch_id', 'channel', 'user_id', 'status', 'version', 'assigned_by', 'approved_by', 'approved_at', 'downloaded_at', 'downloaded_version'])]
class ReadingAssignment extends Model
{
    /** Only published mobile work belonging to this reader and their current branch. */
    #[Scope]
    protected function forMobileReader(Builder $query, User $reader): void
    {
        $query->where('user_id', $reader->id)->where('branch_id', $reader->workBranchId())
            ->where('channel', ReadingAssignmentChannel::Mobile)->where('status', ReadingAssignmentStatus::Approved)
            ->whereHas('cycle', fn (Builder $cycles) => $cycles->whereNull('closed_at'));
    }

    /** @use HasFactory<ReadingAssignmentFactory> */
    use BelongsToBranch, HasFactory;

    protected function casts(): array
    {
        return [
            'channel' => ReadingAssignmentChannel::class,
            'status' => ReadingAssignmentStatus::class,
            'approved_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReadingCycle::class, 'reading_cycle_id');
    }

    public function meterBox(): BelongsTo
    {
        return $this->belongsTo(MeterBox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReadingAssignmentEvent::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ReadingSubmission::class);
    }

    public function paperSheets(): BelongsToMany
    {
        return $this->belongsToMany(PaperSheet::class, 'paper_sheet_assignments');
    }

    public function isApproved(): bool
    {
        return $this->status === ReadingAssignmentStatus::Approved;
    }

    /**
     * Whether the reader has downloaded the version that stands now.
     */
    public function isDownloaded(): bool
    {
        return $this->downloaded_at !== null && $this->downloaded_version === $this->version;
    }

    /**
     * Whether a reader holds a copy of the box from any version: moving it
     * then needs a supervisor's say-so, since they may have readings not
     * uploaded yet.
     */
    public function wasDownloaded(): bool
    {
        return $this->downloaded_at !== null;
    }

    /**
     * Add a step to the allocation's history.
     */
    public function recordEvent(string $action, User $actor, ?ReadingAssignmentChannel $fromChannel = null, ?int $fromUserId = null, ?string $reason = null): ReadingAssignmentEvent
    {
        return $this->events()->create([
            'action' => $action,
            'from_channel' => $fromChannel?->value,
            'from_user_id' => $fromUserId,
            'to_channel' => $this->channel?->value,
            'to_user_id' => $this->user_id,
            'version' => $this->version,
            'reason' => $reason,
            'actor_id' => $actor->id,
        ]);
    }
}
