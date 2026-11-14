<?php

namespace App\Models;

use App\Enums\ReadingAssignmentChannel;
use Database\Factories\ReadingDistributionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'meter_box_id', 'channel', 'user_id', 'approved_by'])]
class ReadingDistribution extends Model
{
    /** @use HasFactory<ReadingDistributionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['channel' => ReadingAssignmentChannel::class];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
