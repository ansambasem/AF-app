<?php

namespace App\Models;

use Database\Factories\AuditNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['closing_id', 'cash_box_id', 'cash_box_payment_id', 'body', 'status', 'assigned_to', 'differences', 'difference_decisions'])]
class AuditNote extends Model
{
    /** @use HasFactory<AuditNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['differences' => 'array', 'difference_decisions' => 'array'];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    /** The entry the note is about, when it is about one. */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(CashBoxPayment::class, 'cash_box_payment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(AuditNoteReply::class);
    }
}
