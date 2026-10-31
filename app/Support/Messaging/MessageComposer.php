<?php

namespace App\Support\Messaging;

use App\Enums\MessageKind;
use App\Enums\MeterReadingStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who a message to subscriptions goes to, and its text for each of them: the
 * wording's `{placeholders}` are filled in with the subscription's own
 * details — their name, balance, and for a weekly reading message that
 * week's reading.
 */
class MessageComposer
{
    /**
     * The most subscriptions one send can reach.
     */
    public const MAX_RECIPIENTS = 5000;

    /**
     * Every placeholder a wording can use, with the kinds of message that
     * can fill it in (null: every kind).
     *
     * @var array<string, array<int, MessageKind>|null>
     */
    public const PLACEHOLDERS = [
        'الاسم' => null,
        'رقم_الاشتراك' => null,
        'الطبلون' => null,
        'الفرع' => null,
        'الرصيد' => null,
        'تاريخ_القراءة' => [MessageKind::WeeklyReading],
        'يوم_القراءة' => [MessageKind::WeeklyReading],
        'سعر_الكيلو' => [MessageKind::WeeklyReading],
        'القراءة_السابقة' => [MessageKind::WeeklyReading],
        'القراءة_الحالية' => [MessageKind::WeeklyReading],
        'الاستهلاك' => [MessageKind::WeeklyReading],
        'قيمة_القراءة' => [MessageKind::WeeklyReading],
        'موعد_القطع' => [MessageKind::DisconnectionWarning],
    ];

    /**
     * The subscriptions the actor may write to that match the criteria, by
     * name: for a weekly reading those with a reading for the week, for a
     * balance reminder those who owe more than the minimum balance. At most
     * `$limit` of them (MAX_RECIPIENTS by default).
     *
     * @param  array{week_start?: ?string, approved_only?: bool, min_balance?: float|string|null, branch_id?: int|string|null, status?: ?string, meter_box_name?: ?string, meter_box_id?: int|string|null, circuit_breaker_id?: int|string|null, search?: ?string, subscription_ids?: array<int, int|string>|null}  $criteria
     * @return Collection<int, Subscription>
     */
    public function recipients(MessageKind $kind, User $actor, array $criteria, int $limit = self::MAX_RECIPIENTS): Collection
    {
        $query = Subscription::query()
            ->visibleTo($actor)
            ->whereNull('subscriptions.archived_at')
            ->with(['branch', 'meterBox'])
            ->withSum('transactions as balance', 'amount')
            ->orderByRaw("COALESCE(NULLIF(subscription_name, ''), full_name)")
            ->orderBy('id');

        $this->applyCommonCriteria($query, $criteria);

        if ($kind === MessageKind::WeeklyReading) {
            $weekReading = function ($readings) use ($criteria): void {
                $readings->whereDate('week_start', (string) ($criteria['week_start'] ?? ''))
                    ->when($criteria['approved_only'] ?? true, fn ($approved) => $approved->where('status', MeterReadingStatus::Approved));
            };

            $query->whereHas('meterReadings', $weekReading)->with(['meterReadings' => $weekReading]);
        }

        if ($kind === MessageKind::BalanceReminder) {
            $query->whereRaw(
                '(select coalesce(sum(amount), 0) from subscription_transactions where subscription_transactions.subscription_id = subscriptions.id) > CAST(? AS DECIMAL(12, 2))',
                [(float) ($criteria['min_balance'] ?? 0)],
            );
        }

        return $query->limit($limit)->get();
    }

    /**
     * The subscription's own value for each placeholder the kind of message
     * can use. Recipients come from recipients(), with their balance and
     * (for a weekly reading) the week's reading loaded.
     *
     * @return array<string, string>
     */
    public function variables(Subscription $subscription, MessageKind $kind): array
    {
        $variables = [
            'الاسم' => $subscription->displayName(),
            'رقم_الاشتراك' => (string) $subscription->account_number,
            'الطبلون' => $subscription->meterBox?->displayName() ?? '',
            'الفرع' => $subscription->branch?->name ?? '',
            'الرصيد' => self::money((float) ($subscription->balance ?? $subscription->balance())),
        ];

        if ($kind === MessageKind::WeeklyReading) {
            $reading = $subscription->relationLoaded('meterReadings') ? $subscription->meterReadings->first() : null;

            $variables += [
                'تاريخ_القراءة' => ($reading?->week_end ?? $reading?->week_start)?->format('d/m/Y') ?? '',
                'يوم_القراءة' => ($reading?->week_end ?? $reading?->week_start)?->copy()->locale('ar')->dayName ?? '',
                'سعر_الكيلو' => $reading ? self::number((float) $reading->unit_price) : '',
                'القراءة_السابقة' => $reading ? self::number($reading->previous_reading) : '',
                'القراءة_الحالية' => $reading ? self::number($reading->current_reading) : '',
                'الاستهلاك' => $reading ? self::number($reading->consumption) : '',
                'قيمة_القراءة' => $reading ? self::money((float) $reading->amount_due) : '',
            ];
        }

        return $variables;
    }

    /**
     * The wording with each `{placeholder}` replaced by its value. A
     * placeholder without a value is left as written.
     *
     * @param  array<string, string>  $variables
     */
    public function render(string $body, array $variables): string
    {
        return preg_replace_callback(
            '/\{([^{}]+)\}/u',
            fn (array $match): string => $variables[trim($match[1])] ?? $match[0],
            $body,
        ) ?? $body;
    }

    /**
     * The placeholders the given kind of message can use.
     *
     * @return array<int, string>
     */
    public static function placeholdersFor(MessageKind $kind): array
    {
        return array_keys(array_filter(
            self::PLACEHOLDERS,
            fn (?array $kinds): bool => $kinds === null || in_array($kind, $kinds, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function applyCommonCriteria(Builder $query, array $criteria): void
    {
        foreach (['branch_id', 'status', 'meter_box_id'] as $column) {
            $value = $criteria[$column] ?? null;

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        $breaker = $criteria['circuit_breaker_id'] ?? null;

        if ($breaker === 'none') {
            $query->whereNull('circuit_breaker_id');
        } elseif ($breaker !== null && $breaker !== '') {
            $query->where('circuit_breaker_id', (int) $breaker);
        }

        if (filled($criteria['meter_box_name'] ?? null)) {
            $query->whereHas('meterBox', fn (Builder $box) => $box->where('name', $criteria['meter_box_name']));
        }

        if (filled($criteria['subscription_ids'] ?? null)) {
            $query->whereIn('id', array_map('intval', (array) $criteria['subscription_ids']));
        }

        $search = trim((string) ($criteria['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                foreach (['full_name', 'subscription_name', 'phone', 'subscription_phone', 'account_number'] as $column) {
                    $inner->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }
    }

    /** An amount with two decimals only when it has any: 120 → "120", 58.5 → "58.50". */
    private static function money(float $amount): string
    {
        $rounded = round($amount, 2);

        return number_format($rounded, floor($rounded) === $rounded ? 0 : 2);
    }

    /** A meter reading or consumption without trailing zeros: 1250.50 → "1250.5". */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
