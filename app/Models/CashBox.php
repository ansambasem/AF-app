<?php

namespace App\Models;

use App\Enums\CashBoxType;
use App\Support\BranchClosingWorkflow;
use Carbon\CarbonImmutable;
use Database\Factories\CashBoxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['number', 'branch_id', 'user_id', 'kind', 'type', 'open_key', 'closing_id', 'status', 'opened_at', 'closed_at', 'collector_submitted_at', 'close_reason', 'collector_counts', 'head_counts', 'expected_counts', 'decisions', 'reason', 'received_by', 'received_at', 'received_in_closing_id', 'confirmed_at', 'sent_at', 'sent_by', 'send_note'])]
class CashBox extends Model
{
    public const CLOSE_REASON_PERIOD_END = 'period_end';

    /** @use HasFactory<CashBoxFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => CashBoxType::class, 'collector_submitted_at' => 'datetime', 'collector_counts' => 'array', 'head_counts' => 'array', 'expected_counts' => 'array', 'decisions' => 'array', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'received_at' => 'datetime', 'confirmed_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function stage(): string
    {
        return match (true) {
            $this->isEditable() => 'open',
            $this->status === 'received' && $this->type->isCounted() && ! $this->confirmed_at && $this->close_reason !== self::CLOSE_REASON_PERIOD_END => 'awaiting_confirmation',
            default => 'completed',
        };
    }

    /**
     * Entries remain editable until manual closure or automatic period-end
     * closure. Legacy pending boxes are still open for review.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['open', 'pending'], true);
    }

    /** Automatic closure at the next week's boundary belongs to the week that ended. */
    public function closingWeekMoment(): CarbonImmutable
    {
        return $this->close_reason === self::CLOSE_REASON_PERIOD_END
            ? $this->closing->coverage_until->subSecond()
            : CarbonImmutable::instance($this->received_at ?? $this->closed_at ?? $this->opened_at);
    }

    /** @param Builder<CashBox> $query @return Builder<CashBox> */
    public function scopeInClosingWeek(Builder $query, CarbonImmutable $from): Builder
    {
        $until = $from->addWeek()->utc();
        $from = $from->utc();

        return $query->where(function (Builder $boxes) use ($from, $until): void {
            $boxes->where(fn (Builder $automatic) => $automatic->where('close_reason', self::CLOSE_REASON_PERIOD_END)
                ->whereHas('closing', fn (Builder $closing) => $closing->where('coverage_until', '>', $from)->where('coverage_until', '<=', $until)))
                ->orWhere(fn (Builder $manual) => $manual
                    ->where(fn (Builder $reasons) => $reasons->whereNull('close_reason')->orWhere('close_reason', '!=', self::CLOSE_REASON_PERIOD_END))
                    ->whereRaw('COALESCE(received_at, closed_at, opened_at) >= ?', [$from])
                    ->whereRaw('COALESCE(received_at, closed_at, opened_at) < ?', [$until]));
        });
    }

    /** @return array<string, string> */
    public function totals(): array
    {
        $this->loadMissing('payments.transaction');
        $entries = $this->payments->filter(fn (CashBoxPayment $entry): bool => $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE);

        return collect(BranchClosingWorkflow::CURRENCIES)->mapWithKeys(fn (string $currency): array => [
            $currency => Closing::money($entries->where('currency', $currency)->sum(fn (CashBoxPayment $entry): int => Closing::cents($entry->amount))),
        ])->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(Closing::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CashBoxPayment::class);
    }

    public function audit(): HasOne
    {
        return $this->hasOne(CashBoxAudit::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedIn(): BelongsTo
    {
        return $this->belongsTo(Closing::class, 'received_in_closing_id');
    }
}
