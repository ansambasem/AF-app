<?php

namespace App\Models;

use App\Enums\AccountingType;
use App\Enums\MeterReadingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\ArabicSearch;
use App\Support\BranchDomainScope;
use App\Support\DailySeries;
use App\Support\DeletionBlocker;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'full_name', 'national_id', 'phone', 'address', 'meter_box_id', 'tariff_id', 'tariff_segment_id', 'branch_id',
    'registered_by', 'status', 'accounting_type', 'circuit_breaker_id', 'minimum_charge', 'kilowatt_price', 'initial_reading', 'subscription_fee',
    'subscription_date', 'reconnected_at', 'activated_at', 'subscription_name', 'subscription_phone', 'legacy_number', 'notes',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToBranch, HasFactory;

    /** Auditors may read every branch's subscriber accounts; other staff stay in their branch. */
    #[Scope]
    protected function readableTo(Builder $query, User $user, bool $auditLog = false): void
    {
        if ($user->canViewAllSubscriberAccounts() || ($auditLog && $user->hasFinancialAuditAccess())) {
            $user->scopeAuditBranches($query, 'subscriptions.branch_id');
        } else {
            $query->visibleTo($user);
        }
    }

    /**
     * Every new subscription gets an account number automatically; it is
     * never taken from user input.
     */
    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription): void {
            $subscription->account_number ??= static::nextAccountNumber();

            if ($subscription->subscriber_profile_id === null) {
                $subscription->profile()->associate(SubscriberProfile::create($subscription->only(SubscriberProfile::PERSONAL_FIELDS)));
            } else {
                $subscription->fill($subscription->profile->only(SubscriberProfile::PERSONAL_FIELDS));
            }
        });

        // The first time a subscription is active is remembered: they can be disconnected after that, but never go back to waiting.
        static::saving(function (Subscription $subscription): void {
            if ($subscription->status === SubscriptionStatus::Active && $subscription->activated_at === null) {
                $subscription->activated_at = now();
            }
        });

        static::saved(function (Subscription $subscription): void {
            if ($subscription->subscriber_profile_id !== null && $subscription->wasChanged(SubscriberProfile::PERSONAL_FIELDS)) {
                $subscription->profile->update($subscription->only(SubscriberProfile::PERSONAL_FIELDS));
            }
        });

        static::deleted(function (Subscription $subscription): void {
            $profile = $subscription->profile;

            if ($profile !== null && ! $profile->subscriptions()->withoutGlobalScope(BranchDomainScope::class)->exists()) {
                $profile->delete();
            }
        });
    }

    /** The account's own name, falling back to its shared personal name. */
    public function displayName(): string
    {
        return filled($this->subscription_name) ? $this->subscription_name : $this->full_name;
    }

    /**
     * Subscriptions whose name, account name, account number, old system number or meter box
     * number contains every word of the search, however its Arabic is
     * spelled (see ArabicSearch).
     */
    #[Scope]
    protected function matchingSearch(Builder $query, string $search): void
    {
        foreach (ArabicSearch::terms($search) as $term) {
            $query->where(function (Builder $matching) use ($term): void {
                foreach (['full_name', 'subscription_name', 'account_number', 'legacy_number'] as $column) {
                    ArabicSearch::orWhereContains($matching, $column, $term);
                }

                $matching->orWhereHas('meterBox', fn (Builder $box): Builder => $box
                    ->where(fn (Builder $number): Builder => ArabicSearch::orWhereContains($number, 'box_number', $term)));
            });
        }
    }

    /**
     * Order a meter box's subscribers by the name shown for them or by
     * account number (shorter numbers first, so 9 comes before 10).
     */
    #[Scope]
    protected function orderedInMeterBox(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $query->select('subscriptions.*')
            ->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) AS display_name");

        if ($sort === 'account_number') {
            $query->orderByRaw('LENGTH(account_number) '.$direction)->orderBy('account_number', $direction);
        } else {
            $query->orderBy('display_name', $direction);
        }

        $query->orderBy('subscriptions.id');
    }

    /** The subscription's contact number, falling back to the personal number. */
    public function contactPhone(): ?string
    {
        return filled($this->subscription_phone) ? $this->subscription_phone : $this->phone;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SubscriberProfile::class, 'subscriber_profile_id');
    }

    /**
     * The next account number for the current year: the four-digit year
     * followed by a five-digit sequence that restarts each year, e.g.
     * 202600001. Ordering by length first keeps the sequence correct if a
     * year ever passes 99,999 subscriptions.
     */
    public static function nextAccountNumber(): string
    {
        $year = now()->format('Y');

        $lastAccountNumber = static::withoutGlobalScope(BranchDomainScope::class)
            ->where('account_number', 'like', $year.'%')
            ->orderByRaw('LENGTH(account_number) DESC')
            ->orderByDesc('account_number')
            ->lockForUpdate()
            ->value('account_number');

        $nextSequence = $lastAccountNumber ? (int) substr($lastAccountNumber, 4) + 1 : 1;

        return $year.str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'accounting_type' => AccountingType::class,
            'subscription_date' => 'date',
            'reconnected_at' => 'date',
            'activated_at' => 'datetime',
            'archived_at' => 'datetime',
            'service_ended_on' => 'date',
            'archive_history' => 'array',
            'subscription_fee' => 'decimal:2',
            'kilowatt_price' => 'decimal:2',
            'initial_reading' => 'float',
        ];
    }

    public function meterBox(): BelongsTo
    {
        return $this->belongsTo(MeterBox::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function tariffSegment(): BelongsTo
    {
        return $this->belongsTo(TariffSegment::class);
    }

    public function circuitBreaker(): BelongsTo
    {
        return $this->belongsTo(CircuitBreaker::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }

    /**
     * The discount taken off every weekly reading recorded for them, if
     * they have one.
     */
    public function standingDiscount(): HasOne
    {
        return $this->hasOne(StandingDiscount::class);
    }

    /**
     * What a kilowatt costs this subscriber, in shekels: their own price if
     * they were given one, otherwise their tariff's.
     */
    public function kilowattPrice(): string
    {
        return (string) ($this->kilowatt_price ?? $this->tariff->rate);
    }

    /**
     * Whether the subscriber pays a price of their own instead of their tariff's.
     */
    public function hasOwnKilowattPrice(): bool
    {
        return $this->kilowatt_price !== null;
    }

    /**
     * Every time the subscription was put on a disconnection list.
     */
    public function disconnectionItems(): HasMany
    {
        return $this->hasMany(DisconnectionListItem::class);
    }

    /**
     * The weekly minimum payment: the subscription's own minimum charge, or
     * their circuit breaker's minimum payment if none is set.
     */
    public function weeklyMinimumPayment(): string
    {
        return (string) ($this->minimum_charge ?? $this->circuitBreaker?->minimum_payment ?? '0.00');
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function latestMeterReading(): HasOne
    {
        return $this->hasOne(MeterReading::class)->latestOfMany('week_start');
    }

    /**
     * Their reading for the latest week that has ended — the week the
     * reading sheet opens on — if it has been entered.
     */
    public function latestWeekReading(): ?MeterReading
    {
        return $this->meterReadings()->whereDate('week_start', MeterReading::latestEndedWeekStart($this->branch_id)->toDateString())->first();
    }

    /**
     * The meter reading a new week starts from: the current reading of the
     * last week recorded before it, or the subscription's initial reading if
     * this is their first week.
     */
    public function previousReadingBefore(Carbon $weekStart): float
    {
        $lastReading = $this->meterReadings()
            ->where('week_start', '<', $weekStart->toDateString())
            ->orderByDesc('week_start')
            ->first();

        return $lastReading?->nextPreviousReading() ?? (float) ($this->initial_reading ?? 0);
    }

    /**
     * What the subscription owes, in shekels: the sum of their account's
     * lines (negative when they are in credit).
     */
    public function balance(): float
    {
        return round((float) $this->transactions()->sum('amount'), 2);
    }

    /**
     * Whether a payment of `$amount` against `$owed` (what the subscription
     * owes, never below zero) is so far above it that it is probably a slip,
     * and so is saved only once the collector confirms it.
     */
    public static function overpaymentNeedsConfirmation(float $amount, float $owed): bool
    {
        return $amount > $owed * (float) config('powercollect.payments.overpayment_multiplier')
            && $amount - $owed >= (float) config('powercollect.payments.overpayment_confirmation_minimum');
    }

    /**
     * Why the subscription can't be deleted yet — readings or account lines
     * beyond the subscription fee charged when they were added — or null
     * when they can: a subscription added by mistake.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->archived_at !== null || ! empty($this->archive_history)) {
            return 'لا يمكن حذف اشتراك مؤرشف؛ يبقى ملفه وسجله المالي محفوظين في الأرشيف.';
        }

        return DeletionBlocker::describe('المشترك', [
            'القراءات' => $this->meterReadings()->count(),
            'قوائم القطع' => $this->disconnectionItems()->count(),
            'القراءات الميدانية' => ReadingSubmission::query()->where('subscription_id', $this->id)->count(),
            'الحركات المالية' => $this->transactions()->where(function (Builder $query): void {
                $query->where('type', '!=', SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE)
                    ->orWhere('source_key', 'like', 'charge:%');
            })->count(),
        ], 'يمكنك تغيير حالته إلى «مفصول» بدلًا من حذفه.');
    }

    /** Archive eligibility is checked again under a row lock when saving. */
    public function archiveBlocker(): ?string
    {
        return match (true) {
            $this->archived_at !== null => 'هذا الاشتراك مؤرشف بالفعل.',
            $this->status !== SubscriptionStatus::Disconnected => 'الأرشفة متاحة فقط للاشتراك المفصول بعد إنهاء خدمته.',
            $this->hasUnfinishedReadings() => 'أكمل القراءات المعلّقة وإعادة إدخال القراءات المطلوبة قبل الأرشفة.',
            default => null,
        };
    }

    private function hasUnfinishedReadings(): bool
    {
        if ($this->relationLoaded('meterReadings')) {
            return $this->meterReadings->contains(fn (MeterReading $reading): bool => $reading->status === MeterReadingStatus::Pending || $reading->requires_reentry);
        }

        return $this->meterReadings()->where(fn (Builder $readings): Builder => $readings->where('status', MeterReadingStatus::Pending)->orWhere('requires_reentry', true))->exists();
    }

    /**
     * The dates that being activated sets on this subscription, who is not
     * active now. A first connection starts the subscription today; a
     * reconnection keeps the day they first subscribed (the day they were
     * first activated, when none was recorded) and notes today as the day
     * they were connected again.
     *
     * @return array{subscription_date?: string, reconnected_at?: string}
     */
    public function datesWhenActivated(): array
    {
        $today = DailySeries::today()->toDateString();
        $wasActiveBefore = $this->activated_at !== null;

        return [
            ...($wasActiveBefore ? ['reconnected_at' => $today] : []),
            ...(! $wasActiveBefore || $this->subscription_date === null ? ['subscription_date' => $wasActiveBefore ? DailySeries::localDate($this->activated_at) : $today] : []),
        ];
    }

    /**
     * Whether a subscription fee has been charged to the account, whether
     * with the subscription or later from the transactions.
     */
    public function hasSubscriptionFeeCharge(): bool
    {
        return $this->transactions()->where('type', SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE)->exists();
    }

    /**
     * Delete the subscription with their subscription fee (and standing
     * discount); deletionBlocker() must allow it first.
     */
    public function deleteWithSubscriptionFee(): void
    {
        DB::transaction(function (): void {
            $this->transactions()->delete();
            $this->delete();
        });
    }
}
