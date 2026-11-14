<?php

namespace App\Models;

use Database\Factories\ClosingVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['closing_id', 'version', 'snapshot', 'user_id', 'notes', 'withdrawn_at'])]
class ClosingVersion extends Model
{
    /** @use HasFactory<ClosingVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'withdrawn_at' => 'datetime'];
    }
}
