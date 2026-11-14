<?php

namespace App\Models;

use Database\Factories\GovernorateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A governorate, no longer used: the work is organised by branch, then its
 * areas, then their meter boxes. The table is kept, unused, until it is
 * confirmed nothing depends on it and its removal is approved.
 */
#[Fillable(['name'])]
class Governorate extends Model
{
    /** @use HasFactory<GovernorateFactory> */
    use HasFactory;

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
