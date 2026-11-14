<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A printed reading sheet of a cycle: its own number and the paper boxes on
 * it. Printing it again only counts the reprint; it never allocates a box
 * twice.
 */
#[Fillable(['reading_cycle_id', 'sheet_number', 'print_count', 'last_printed_at', 'last_printed_by', 'created_by'])]
class PaperSheet extends Model
{
    protected function casts(): array
    {
        return ['last_printed_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReadingCycle::class, 'reading_cycle_id');
    }

    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(ReadingAssignment::class, 'paper_sheet_assignments');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastPrintedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }
}
