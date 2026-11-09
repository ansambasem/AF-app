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
     * How each subscription's period was settled (see allocate()), summed in
     * the report's and the dashboard's totals. Never columns of the
     * subscriptions table: they are read in the details and the indicators.
     */
    public const SETTLEMENT = [
        'afterDiscount', 'advanceUsed', 'invoiceTarget', 'unusedAdvance', 'paidToInvoice', 'discountToInvoice', 'invoiceSettled', 'invoiceRemaining',
        'paidToAccumulated', 'discountToAccumulated', 'accumulatedRemaining', 'newAdvance', 'carriedAdvance', 'collectible',
    ];

    /**
     * The rows of the period's subscriptions (those of its branch, narrowed
     * by `$status`: active, disconnected or every one), keyed by
     * subscription id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(BillingPeriod $period, ?string $status = null, ?string $search = null, ?array $only = null): Collection
    {
        $subscriptions = Subscription::withoutGlobalScopes()->where('branch_id', $period->branch_id)
            ->when($only !== null, fn (Builder $query) => $query->whereKey($only))
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
            ...self::allocate($accumulated, $advance, $invoice, $charges, $discounts, $payments),
        ];
    }

    /**
     * How the period was settled, in cents, the one rule every page reads,
     * in this order:
     *
     *   1. billed          = invoice + charges
     *   2. after discount  = MAX(0, billed − discounts)          (the discount comes off first, all of it)
     *   3. advance used    = MIN(advance brought forward, after discount)
     *      invoice target  = after discount − advance used        (never below nothing)
     *      unused advance  = advance brought forward − advance used (kept, carried to the next period)
     *   4. the period's new payments (cash, transfers, clearings) settle the invoice target,
     *   5. what is left of the discount comes off the accumulated debt, then the payments left settle it,
     *   6. and what is left of the payments is the new advance.
     *
     * The discount and the advance used only set what is still to pay; they
     * are never counted as money paid. Nothing is counted twice: each shekel
     * of a payment goes to one place, the advance is taken off once (in the
     * invoice target), and what is carried to the next period (unused
     * advance, new advance, a discount beyond everything owed) is the
     * period's closing credit. A reversal in the period of an earlier
     * period's line reopens that debt (a refunded payment, a cancelled
     * discount) or adds to the credit (a cancelled charge), so the parts
     * always add up to the period's closing balance.
     *
     * @return array<string, int>
     */
    public static function allocate(int $accumulated, int $advance, int $invoice, int $charges, int $discounts, int $payments): array
    {
        $billed = $invoice + $charges;
        $gross = max($billed, 0);
        $credit = $advance + max(-$billed, 0);
        $debt = $accumulated + max(-$discounts, 0) + max(-$payments, 0);
        $paid = max($payments, 0);
        $discounted = max($discounts, 0);

        $discountToInvoice = min($discounted, $gross);
        $afterDiscount = $gross - $discountToInvoice;
        $advanceUsed = min($credit, $afterDiscount);
        $target = $afterDiscount - $advanceUsed;
        $unusedAdvance = $credit - $advanceUsed;
        $paidToInvoice = min($paid, $target);
        $discountToAccumulated = min($discounted - $discountToInvoice, $debt);
        $paidToAccumulated = min($paid - $paidToInvoice, $debt - $discountToAccumulated);
        $newAdvance = $paid - $paidToInvoice - $paidToAccumulated;
        $discountCredit = $discounted - $discountToInvoice - $discountToAccumulated;

        return [
            'afterDiscount' => $afterDiscount,
            'advanceUsed' => $advanceUsed,
            'invoiceTarget' => $target,
            'unusedAdvance' => $unusedAdvance,
            'paidToInvoice' => $paidToInvoice,
            'discountToInvoice' => $discountToInvoice,
            // The invoice as settled in all: by its discount, the advance and new money.
            'invoiceSettled' => $discountToInvoice + $advanceUsed + $paidToInvoice,
            'invoiceRemaining' => $target - $paidToInvoice,
            'paidToAccumulated' => $paidToAccumulated,
            'discountToAccumulated' => $discountToAccumulated,
            'accumulatedRemaining' => $debt - $discountToAccumulated - $paidToAccumulated,
            'newAdvance' => $newAdvance,
            // Carried to the next period as its advance: the unused advance, the new one, and a discount beyond everything owed.
            'carriedAdvance' => $unusedAdvance + $newAdvance + $discountCredit,
            // What the subscription had to pay in money this period: the invoice target and the accumulated left after its discount.
            'collectible' => $target + ($debt - $discountToAccumulated),
        ];
    }

    /**
     * The three collection rates of summed rows, as whole percentages never
     * above 100:
     *
     *   total       = new money that settled what was owed (invoice and accumulated) ÷ what had to be paid in money
     *   invoice     = new money paid to the invoice ÷ invoice target (after its discount and the advance used);
     *                 100 when the target is nothing, the invoice being settled before any payment was needed
     *   accumulated = collected from the accumulated debt ÷ accumulated at the period's start
     *
     * The discount and the advance used are never in a rate's numerator: they
     * are already taken off the invoice target. The total and accumulated
     * rates are null when there was nothing to collect.
     *
     * @param  array<string, int>  $totals
     * @return array{totalRate: ?int, invoiceRate: int, accumulatedRate: ?int}
     */
    public static function rates(array $totals): array
    {
        $rate = fn (int $part, int $whole): ?int => $whole > 0 ? (int) min(100, max(0, floor($part * 100 / $whole))) : null;

        return [
            'totalRate' => $rate($totals['paidToInvoice'] + $totals['paidToAccumulated'], $totals['collectible']),
            'invoiceRate' => $rate($totals['paidToInvoice'], $totals['invoiceTarget']) ?? 100,
            'accumulatedRate' => $rate($totals['paidToAccumulated'], $totals['accumulated']),
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
        $totals = array_fill_keys([...self::TOTALS, ...self::SETTLEMENT, 'owing', 'ahead', 'count'], 0);

        foreach ($rows as $row) {
            foreach ([...self::TOTALS, ...self::SETTLEMENT] as $column) {
                $totals[$column] += $row[$column];
            }
            $totals['count']++;
            $totals['owing'] += $row['remaining'] > 0 ? 1 : 0;
            $totals['ahead'] += $row['remaining'] < 0 ? 1 : 0;
        }

        return [...$totals, ...self::rates($totals)];
    }

    /** A row (or summary) with its money columns as decimal strings, for the pages and exports. */
    public static function present(array $row): array
    {
        foreach ([...self::TOTALS, ...self::SETTLEMENT, 'opening'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Closing::money($row[$column]);
            }
        }

        return $row;
    }

    /**
     * One subscription's period in full: its row, its three rates, and each
     * payment of the period (cash, transfer, clearing) with the part of it
     * that settled the invoice, the accumulated debt, or became the new
     * advance — taken in the order they were made, so the parts add up to
     * the row's. Null when the subscription is not in the period's branch.
     *
     * @return array{row: array<string, mixed>, rates: array<string, ?int>, payments: list<array<string, mixed>>}|null
     */
    public function detail(BillingPeriod $period, int $subscriptionId): ?array
    {
        $row = $this->rows($period, only: [$subscriptionId])->get($subscriptionId);
        if ($row === null) {
            return null;
        }

        $toInvoice = $row['paidToInvoice'];
        $toAccumulated = $row['paidToAccumulated'];
        $budget = max($row['payments'], 0);
        $payments = SubscriptionTransaction::withoutGlobalScopes()->where('billing_period_id', $period->id)->where('subscription_id', $subscriptionId)
            ->whereIn('type', [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING, SubscriptionTransaction::TYPE_CREDIT, SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT])
            ->where('amount', '<', 0)->orderBy('created_at')->orderBy('id')->get()
            ->map(function (SubscriptionTransaction $line) use (&$toInvoice, &$toAccumulated, &$budget): array {
                $amount = -Closing::cents($line->amount);
                $counted = min($amount, $budget);
                $budget -= $counted;
                $invoice = min($counted, $toInvoice);
                $toInvoice -= $invoice;
                $accumulated = min($counted - $invoice, $toAccumulated);
                $toAccumulated -= $accumulated;

                return [
                    'id' => $line->id,
                    'date' => DailySeries::localDate($line->created_at),
                    'voucher' => $line->displayVoucherNumber(),
                    'method' => $line->isClearing() ? 'مقاصة' : ($line->payment_method ? __($line->payment_method->label()) : null),
                    'amount' => Closing::money($amount),
                    'toInvoice' => Closing::money($invoice),
                    'toAccumulated' => Closing::money($accumulated),
                    'toAdvance' => Closing::money($counted - $invoice - $accumulated),
                    // Taken back in the period (a refund or a cancelled payment): it settled nothing.
                    'returned' => Closing::money($amount - $counted),
                ];
            })->values()->all();

        return ['row' => self::present($row), 'rates' => self::rates($row), 'payments' => $payments];
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
