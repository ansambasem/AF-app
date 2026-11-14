<?php

namespace App\Models;

use Database\Factories\CashBoxAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['cash_box_id', 'audited_by', 'audited_at'])]
class CashBoxAudit extends Model
{
    /** @use HasFactory<CashBoxAuditFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['audited_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['audit' => 'لا يمكن تغيير ختم التدقيق.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['audit' => 'لا يمكن حذف ختم التدقيق.']));
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }
}
