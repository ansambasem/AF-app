<?php

namespace App\Models;

use Database\Factories\TariffRateChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kilo price a tariff was given, when and by whom. Readings keep the
 * price they were billed at, so a change only reaches new readings.
 */
#[Fillable(['tariff_id', 'rate', 'changed_by'])]
class TariffRateChange extends Model
{
    /** @use HasFactory<TariffRateChangeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
