<?php

namespace App\Models;

use App\Enums\ClosingDifferenceReason;
use App\Enums\ClosingStatus;
use App\Enums\ClosingType;
use Database\Factories\ClosingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Historical closing records retained for transaction protection and audit references. */
#[Fillable([
    'number', 'type', 'branch_id', 'period_start', 'period_end', 'status', 'opening_cash', 'counted_cash', 'denominations',
    'difference_reason', 'difference_notes', 'prepared_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'return_reason',
    'book', 'is_manual', 'coverage_from', 'coverage_until', 'branch_approved_by', 'branch_approved_at', 'branch_notes', 'submitted_snapshot', 'workflow_settings', 'scheduled_until',
])]
class Closing extends Model
{
    /** @use HasFactory<ClosingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ClosingType::class,
            'status' => ClosingStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'denominations' => 'array',
            'difference_reason' => ClosingDifferenceReason::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'coverage_from' => 'immutable_datetime',
            'coverage_until' => 'immutable_datetime',
            'is_manual' => 'boolean',
            'branch_approved_at' => 'datetime',
            'submitted_snapshot' => 'array',
            'workflow_settings' => 'array',
            'scheduled_until' => 'immutable_datetime',
        ];
    }

    public function branchApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_approved_by');
    }

    public static function cents(string|float|int|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClosingPayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClosingEvent::class);
    }

    public function auditNotes(): HasMany
    {
        return $this->hasMany(AuditNote::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(CashTransfer::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
