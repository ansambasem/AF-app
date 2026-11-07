<?php

namespace App\Support;

use App\Enums\SubscriptionStatus;
use App\Models\BillingPeriod;
use App\Models\Closing;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Each subscription's account for one billing period, read from its own
 * lines in cents:
 *
 *   opening        = every line before the period (positive: accumulated, negative: advance)
 *   net            = invoice + accumulated − advance
 *   before payments = net − discounts + charges
 *   remaining      = before payments − payments
 *
 * The invoice is the period's weekly readings (consumption × kilo price,
 * never under the minimum); discounts are the one-off and standing ones;
 * charges every amount charged by hand; payments cash, transfers and
 * clearings. A reversal or refund counts in the period it was made, on the
 * side of the line it takes back, so a closed period's figures never move
 * and `remaining` is always the subscription's balance at the period's end.
 */
class PeriodStatement
{
    /** The columns every row carries, in cents (readings as recorded). */
    public const TOTALS = ['invoice', 'accumulated', 'advance', 'net', 'discounts', 'charges', 'beforePayments', 'payments', 'remaining'];

    /**
     * The rows of the period's subscriptions (those of its branch, narrowed
     * by `$status`: active, disconnected or every one), keyed by
     * subscription id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(BillingPeriod $period, ?string $status = null, ?string $search = null): Collection
    {
        $subscriptions = Subscription::withoutGlobalScopes()->where('branch_id', $period->branch_id)
            ->when($status === SubscriptionStatus::Active->value, fn (Builder $query) => $query->where('status', SubscriptionStatus::Active->value))
            ->when($status === SubscriptionStatus::Disconnected->value, fn (Builder $query) => $query->where('status', '!=', SubscriptionStatus::Active->value))
            ->when(filled($search), fn (Builder $query) => $query->matchingSearch((string) $search))
            ->with(['meterBox:id,box_number', 'profile:id,subscriber_number'])
            ->get(['id', 'account_number', 'full_name', 'subscription_name', 'status', 'branch_id', 'meter_box_id', 'subscriber_profile_id']);
        $ids = $subscriptions->modelKeys();
        if ($ids === []) {
            return collect();
        }

        $opening = $this->openingBalances($period, $ids);
        $movements = $this->movements($period, $ids);
        $readings = MeterReading::withoutGlobalScopes()->where('billing_period_id', $period->id)->whereIn('subscription_id', $ids)
            ->get(['subscription_id', 'previous_reading', 'current_reading', 'consumption', 'unit_price', 'requires_reentry'])->keyBy('subscription_id');

        return $subscriptions->mapWithKeys(function (Subscription $subscription) use ($opening, $movements, $readings, $period): array {
            $open = (int) ($opening[$subscription->id] ?? 0);
            $moves = $movements[$subscription->id] ?? [];
            $row = self::figures($open, (int) ($moves['invoice'] ?? 0), (int) ($moves['discounts'] ?? 0), (int) ($moves['charges'] ?? 0), (int) ($moves['payments'] ?? 0));
            $reading = $readings->get($subscription->id);

            return [$subscription->id => [
                'subscriptionId' => $subscription->id,
                'accountNumber' => $subscription->account_number,
                'subscriberNumber' => $subscription->profile?->subscriber_number,
                'name' => $subscription->displayName(),
                'status' => $subscription->status->value,
                'branchId' => $period->branch_id,
                'periodId' => $period->id,
                'previousReading' => $reading?->previous_reading,
                'currentReading' => $reading && ! $reading->requires_reentry ? $reading->current_reading : null,
                'consumption' => $reading && ! $reading->requires_reentry ? $reading->consumption : null,
                'unitPrice' => $reading?->unit_price,
                ...$row,
            ]];
        });
    }

    /**
     * The period's figures from what the subscription opened with and what
     * moved in it, in cents.
     *
     * @return array{opening: int, invoice: int, accumulated: int, advance: int, net: int, discounts: int, charges: int, beforePayments: int, payments: int, remaining: int}
     */
    public static function figures(int $opening, int $invoice, int $discounts, int $charges, int $payments): array
    {
        $accumulated = max($opening, 0);
        $advance = max(-$opening, 0);
        $net = $invoice + $accumulated - $advance;
        $beforePayments = $net - $discounts + $charges;

        return [
            'opening' => $opening, 'invoice' => $invoice, 'accumulated' => $accumulated, 'advance' => $advance, 'net' => $net,
            'discounts' => $discounts, 'charges' => $charges, 'beforePayments' => $beforePayments,
            'payments' => $payments, 'remaining' => $beforePayments - $payments,
        ];
    }

    /**
     * The sum of each column over the rows, in cents, with how many
     * subscriptions owe something and how many are ahead.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function summarize(iterable $rows): array
    {
        $totals = array_fill_keys([...self::TOTALS, 'owing', 'ahead', 'count'], 0);

        foreach ($rows as $row) {
            foreach (self::TOTALS as $column) {
                $totals[$column] += $row[$column];
            }
            $totals['count']++;
            $totals['owing'] += $row['remaining'] > 0 ? 1 : 0;
            $totals['ahead'] += $row['remaining'] < 0 ? 1 : 0;
        }

        return $totals;
    }

    /** A row (or summary) with its money columns as decimal strings, for the pages and exports. */
    public static function present(array $row): array
    {
        foreach ([...self::TOTALS, 'opening'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Closing::money($row[$column]);
            }
        }

        return $row;
    }

    /**
     * What each subscription owed (positive) or had in hand (negative) when
     * the period began: its lines with no period, or of an earlier one.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function openingBalances(BillingPeriod $period, array $ids): array
    {
        return DB::table('subscription_transactions as lines')
            ->leftJoin('billing_periods as periods', 'periods.id', '=', 'lines.billing_period_id')
            ->whereIn('lines.subscription_id', $ids)
            ->where(fn ($query) => $query->whereNull('lines.billing_period_id')->orWhere('periods.starts_on', '<', $period->starts_on->toDateString()))
            ->groupBy('lines.subscription_id')
            ->selectRaw('lines.subscription_id, ROUND(SUM(lines.amount) * 100) as cents')
            ->pluck('cents', 'subscription_id')->map(fn ($cents): int => (int) $cents)->all();
    }

    /**
     * What moved in the period for each subscription, by side, in cents and
     * signed as the report shows it (each a positive amount when it is
     * what its name says): a reversal or refund on the side of the line it
     * takes back.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{invoice: int, discounts: int, charges: int, payments: int}>
     */
    private function movements(BillingPeriod $period, array $ids): array
    {
        $rows = DB::table('subscription_transactions as lines')
            ->leftJoin('subscription_transactions as reversed', 'reversed.id', '=', 'lines.reverses_id')
            ->where('lines.billing_period_id', $period->id)
            ->whereIn('lines.subscription_id', $ids)
            ->groupBy('lines.subscription_id', DB::raw('COALESCE(reversed.type, lines.type)'))
            ->selectRaw('lines.subscription_id, COALESCE(reversed.type, lines.type) as side_type, ROUND(SUM(lines.amount) * 100) as cents')
            ->get();

        $movements = [];
        foreach ($rows as $row) {
            [$side, $sign] = self::sideOf((string) $row->side_type);
            $movements[$row->subscription_id][$side] = ($movements[$row->subscription_id][$side] ?? 0) + $sign * (int) $row->cents;
        }

        return $movements;
    }

    /**
     * The column a line of this type counts in, and the sign that turns its
     * amount (positive owed, negative in the subscription's favour) into it.
     *
     * @return array{0: string, 1: int}
     */
    public static function sideOf(string $type): array
    {
        return match ($type) {
            SubscriptionTransaction::TYPE_METER_READING => ['invoice', 1],
            SubscriptionTransaction::TYPE_DISCOUNT, SubscriptionTransaction::TYPE_READING_DISCOUNT => ['discounts', -1],
            SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING, SubscriptionTransaction::TYPE_CREDIT,
            SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT => ['payments', -1],
            default => ['charges', 1],
        };
    }
}
