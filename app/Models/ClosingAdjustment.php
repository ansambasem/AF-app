<?php

namespace App\Models;

use Database\Factories\ClosingAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['closing_id', 'original_closing_id', 'audit_note_id', 'user_id', 'book', 'currency', 'amount', 'reason'])]
class ClosingAdjustment extends Model
{
    /** @use HasFactory<ClosingAdjustmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }
}
