<?php

namespace App\Models;

use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The former area level (منطقة 1), no longer used: it stood for the branch
 * itself, and a branch's areas are now SubArea rows that belong to it. The
 * table is kept, unused, until its removal is approved.
 */
#[Fillable(['name', 'governorate_id'])]
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }
}
