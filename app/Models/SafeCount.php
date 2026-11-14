<?php

namespace App\Models;

use Database\Factories\SafeCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['closing_id', 'user_id', 'counts', 'expected', 'differences', 'reason'])]
class SafeCount extends Model
{
    /** @use HasFactory<SafeCountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['counts' => 'array', 'expected' => 'array', 'differences' => 'array'];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }
}
