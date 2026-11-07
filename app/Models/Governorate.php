<?php

namespace App\Models;

use App\Support\DeletionBlocker;
use Database\Factories\GovernorateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Governorate extends Model
{
    /** @use HasFactory<GovernorateFactory> */
    use HasFactory;

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /**
     * Why the governorate can't be deleted yet — what still uses it — or null
     * when it can.
     */
    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('المحافظة', [
            'المناطق' => $this->areas()->count(),
            'الفروع' => Branch::query()->where('governorate_id', $this->id)->count(),
        ]);
    }
}
