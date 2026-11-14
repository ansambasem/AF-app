<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How old each subscription's debt is (أعمار الديون). Payments, discounts and
 * clearings settle the oldest charges first, so what a subscription still
 * owes is their newest charges, adding up to their balance. Each part of
 * it is aged by the business-time day its charge was made, into the
 * buckets below.
 *
 * Cancelled lines and their reversals cancel each other out and are never
 * aged; the balance itself always counts every line, so a part refunded
 * from a payment comes back as debt on the charges it had paid. Any of the
 * balance that no charge accounts for is aged with the oldest charge.
 */
class DebtAging
{
    /**
     * The age buckets, each with the most days old a debt in it may be
     * (null: no limit), youngest first.
     *
     * @var array<string, int|null>
     */
    public const BUCKETS = ['current' => 30, 'days_60' => 60, 'days_90' => 90, 'older' => null];

    /**
     * Each bucket's name, as the report's columns and filters read.
     *
     * @var array<string, string>
     */
    public const BUCKET_LABELS = [
        'current' => 'حتى 30 يومًا',
        'days_60' => '31–60 يومًا',
        'days_90' => '61–90 يومًا',
        'older' => 'أكثر من 90 يومًا',
    ];

    /**
     * Subscriptions are aged this many at a time, to keep each query's list
     * of ids short.
     */
    private const CHUNK = 500;

    public function __construct(private CarbonImmutable $today) {}

    /**
     * Every subscription the query finds who owes money (a balance above
     * zero), with their debt split by age, the day of the oldest charge
     * still unpaid, and the day of their last payment.
     *
     * @param  Builder<Subscription>  $subscriptions
     * @return Collection<int, array{subscription: Subscription, balance: int, buckets: array<string, int>, oldestDate: ?string, oldestDays: int, lastPaymentDate: ?string, lastPaymentDays: ?int}>
     */
    public function debtors(Builder $subscriptions): Collection
    {
        $balance = '(select coalesce(sum(owed.amount), 0) from subscription_transactions as owed where owed.subscription_id = subscriptions.id)';

        $debtors = $subscriptions
            ->select('subscriptions.*')
            ->selectRaw("{$balance} as balance")
            ->whereRaw("{$balance} > 0.004")
            ->get();

        return $debtors
            ->chunk(self::CHUNK)
            ->flatMap(function (Collection $chunk): array {
                $ids = $chunk->modelKeys();
                $charges = $this->chargesNewestFirst($ids);
                $lastPayments = $this->lastPayments($ids);

                return $chunk
                    ->map(fn (Subscription $subscription): array => $this->age($subscription, $charges->get($subscription->id, collect()), $lastPayments[$subscription->id] ?? null))
                    ->all();
            })
            ->values();
    }

    /**
     * The bucket a debt this many days old falls in.
     */
    public static function bucketFor(int $days): string
    {
        foreach (self::BUCKETS as $bucket => $maxDays) {
            if ($maxDays === null || $days <= $maxDays) {
                return $bucket;
            }
        }

        return array_key_last(self::BUCKETS);
    }

    /**
     * A balance split by age over the charges that make it up: the newest
     * charges are the ones still unpaid, since payments settle the oldest
     * first. Whatever of the balance no charge accounts for is aged with
     * the oldest charge reached. The receivables page ages as of today and
     * the reports as of a period's end; both split the same way.
     *
     * @param  iterable<int, array{day: string, cents: int}>  $charges  newest first
     * @param  Closure(string): int  $daysOld  how many days old a charge of that day is
     * @return array{buckets: array<string, int>, oldestDate: ?string, oldestDays: int}
     */
    public static function split(int $balance, iterable $charges, Closure $daysOld): array
    {
        $remaining = $balance;
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $oldestDate = null;
        $oldestDays = 0;

        foreach ($charges as ['day' => $day, 'cents' => $cents]) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($remaining, $cents);
            $oldestDate = $day;
            $oldestDays = $daysOld($day);
            $buckets[self::bucketFor($oldestDays)] += $taken;
            $remaining -= $taken;
        }

        if ($remaining > 0) {
            $buckets[self::bucketFor($oldestDays)] += $remaining;
        }

        return ['buckets' => $buckets, 'oldestDate' => $oldestDate, 'oldestDays' => $oldestDays];
    }

    /**
     * One debtor's balance split over the charges that make it up, newest
     * first.
     *
     * @param  Collection<int, object{created_at: string, amount: string}>  $charges  newest first
     * @return array{subscription: Subscription, balance: int, buckets: array<string, int>, oldestDate: ?string, oldestDays: int, lastPaymentDate: ?string, lastPaymentDays: ?int}
     */
    private function age(Subscription $subscription, Collection $charges, ?string $lastPaidAt): array
    {
        $balance = self::cents((string) $subscription->balance);
        ['buckets' => $buckets, 'oldestDate' => $oldestDate, 'oldestDays' => $oldestDays] = self::split(
            $balance,
            $charges->lazy()->map(fn (object $charge): array => ['day' => DailySeries::localDate($charge->created_at), 'cents' => self::cents($charge->amount)]),
            fn (string $day): int => $this->daysSince($day),
        );
        $lastPaymentDate = $lastPaidAt ? DailySeries::localDate($lastPaidAt) : null;

        return [
            'subscription' => $subscription,
            'balance' => $balance,
            'buckets' => $buckets,
            'oldestDate' => $oldestDate,
            'oldestDays' => $oldestDays,
            'lastPaymentDate' => $lastPaymentDate,
            'lastPaymentDays' => $lastPaymentDate ? $this->daysSince($lastPaymentDate) : null,
        ];
    }

    /**
     * The charges that count (no cancelled line, no reversal) of the given
     * subscriptions, by subscription, newest first.
     *
     * @param  array<int, int>  $subscriptionIds
     * @return Collection<int, Collection<int, object>>
     */
    private function chargesNewestFirst(array $subscriptionIds): Collection
    {
        return SubscriptionTransaction::query()
            ->charges()
            ->where('amount', '>', 0)
            ->whereIn('subscription_id', $subscriptionIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->toBase()
            ->get(['subscription_id', 'created_at', 'amount'])
            ->groupBy('subscription_id');
    }

    /**
     * When each of the given subscriptions last paid, by subscription: their
     * latest payment that still stands.
     *
     * @param  array<int, int>  $subscriptionIds
     * @return array<int, string>
     */
    private function lastPayments(array $subscriptionIds): array
    {
        return SubscriptionTransaction::query()
            ->counted()
            ->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->whereIn('subscription_id', $subscriptionIds)
            ->groupBy('subscription_id')
            ->toBase()
            ->selectRaw('subscription_id, max(created_at) as last_paid_at')
            ->pluck('last_paid_at', 'subscription_id')
            ->all();
    }

    /**
     * Whole days from a business-time date (Y-m-d) to today.
     */
    private function daysSince(string $date): int
    {
        $day = CarbonImmutable::parse($date, $this->today->getTimezone())->startOfDay();

        return max(0, (int) round($day->diffInDays($this->today)));
    }

    private static function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
