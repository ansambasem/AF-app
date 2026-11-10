<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\BillingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One billing period of a branch, from its first to its last day as the
 * branch's manager set them. It opens on its first day once no other
 * period of the branch is open, and stays open, however long, until the
 * manager closes it by hand; a closed period is changed by the manager
 * only, until they approve it, after which it never changes. Payments are
 * taken only while a period is open. Every account line
 * and reading recorded while it is open belongs to it (a weekly reading's
 * lines to their reading's period).
 */
#[Fillable(['branch_id', 'starts_on', 'ends_on', 'opened_at', 'closed_at', 'approved_at', 'approved_by', 'created_by', 'notes'])]
class BillingPeriod extends Model
{
    /** @use HasFactory<BillingPeriodFactory> */
    use BelongsToBranch, HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_APPROVED = 'approved';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** Waiting for its first day, open, closed awaiting the manager's approval, or approved. */
    public function status(): string
    {
        return match (true) {
            $this->approved_at !== null => self::STATUS_APPROVED,
            $this->closed_at !== null => self::STATUS_CLOSED,
            $this->opened_at !== null => self::STATUS_OPEN,
            default => self::STATUS_SCHEDULED,
        };
    }

    public function isOpen(): bool
    {
        return $this->status() === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status() === self::STATUS_CLOSED;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** "8/10/2026 – 14/10/2026", as the pages name a period. */
    public function label(): string
    {
        return $this->starts_on->format('j/n/Y').' – '.$this->ends_on->format('j/n/Y');
    }

    /**
     * The month the period was created in, "2026-10", on the business's
     * clock: the month the filters file it under, whatever days it covers.
     */
    public function createdMonth(): string
    {
        return $this->created_at->copy()->setTimezone(config('app.business_timezone'))->format('Y-m');
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::STATUS_SCHEDULED => 'مجدولة',
            self::STATUS_OPEN => 'مفتوحة',
            self::STATUS_CLOSED => 'مغلقة بانتظار الاعتماد',
            self::STATUS_APPROVED => 'معتمدة',
        };
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /** The balances kept for each subscription when the period was approved. */
    public function balances(): HasMany
    {
        return $this->hasMany(SubscriberPeriodBalance::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
