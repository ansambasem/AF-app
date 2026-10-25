<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\FiltersDataTable;
use App\Models\Branch;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\DailySeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The financial log (السجل المالي): every line of the accounts of the
 * subscriptions the user may see, newest first and grouped by day, with the
 * period's totals, a daily chart and the totals per branch. Read-only.
 *
 * The figures sum one side of the accounts: the charges (عليه) unless the
 * type filter picks payments or discounts (له), since adding money owed to
 * money paid would mean nothing. Amounts are in shekels. Cancelled lines
 * and their reversals are listed but left out of every figure, since they
 * cancel each other out.
 */
class LedgerController extends Controller
{
    use BuildsSubscriptionStatement, FiltersDataTable;

    /**
     * The period tabs, as the number of days each covers (null: since the
     * first entry).
     */
    private const PERIODS = ['today' => 1, 'yesterday' => 1, '7' => 7, '30' => 30, '90' => 90, 'month' => null, 'custom' => null, 'all' => null];

    private const DEFAULT_PERIOD = '30';

    private const DEFAULT_PER_PAGE = 25;

    /**
     * The chart never shows fewer days than this, and shows this many when
     * the period has no start.
     */
    private const MIN_CHART_DAYS = 7;

    private const OPEN_CHART_DAYS = 30;

    /**
     * Type-filter values that pick a whole side instead of one type.
     */
    private const DEBIT = 'debit';

    private const CREDIT = 'credit';

    public function index(Request $request): InertiaResponse|StreamedResponse
    {
        $this->authorize('viewAny', SubscriptionTransaction::class);

        $actor = $request->user();
        $period = $this->period($request);
        $dateBasis = $request->validate(['date_basis' => ['sometimes', 'in:recorded,paid']])['date_basis'] ?? 'recorded';
        [$from, $until, $days] = $this->dateWindow($request, $period);
        $side = $this->headlineSide($request);

        $ledger = fn (): Builder => $this->filteredLedger($request, $actor);
        $inPeriod = fn (): Builder => $this->dateFiltered($ledger(), $from, $until, $dateBasis);
        $onSide = fn (Builder $query): Builder => $side === self::CREDIT ? $query->credits() : $query->charges();

        if ($request->query('format') === 'csv') {
            return $this->export($this->sortEntries($inPeriod()->with(['subscription.branch', 'recordedBy', 'splitPayment']), $request));
        }

        $entries = $this->sortEntries($inPeriod()->with(['subscription.branch', 'recordedBy', 'splitPayment']), $request)
            ->paginate($this->dataTablePerPage($request, self::DEFAULT_PER_PAGE))
            ->withQueryString()
            ->through(fn (SubscriptionTransaction $transaction): array => $this->row($transaction));

        $groupedByDay = ! $this->sortedByAmount($request);

        return Inertia::render('Ledger/Index', [
            'entries' => $entries,
            'period' => $period,
            'dateBasis' => $dateBasis,
            'dateRange' => [
                'from' => $from?->setTimezone(config('app.business_timezone'))->toDateString(),
                'to' => $until->setTimezone(config('app.business_timezone'))->subDay()->toDateString(),
            ],
            'ledgerTotals' => $this->ledgerTotals($inPeriod()),
            'side' => $side,
            'summary' => $this->summary($onSide($inPeriod()), $from && $days ? $onSide($ledger()) : null, $from, $days, $inPeriod(), $dateBasis),
            'dayTotals' => $groupedByDay ? $this->dayTotals($inPeriod(), collect($entries->items())->pluck('day')->unique()->all()) : [],
            'dailyTotals' => $dateBasis === 'paid' || in_array($period, ['yesterday', 'month', 'custom'], true) ? [] : DailySeries::sums(
                $onSide($ledger()),
                'subscription_transactions.created_at',
                'subscription_transactions.amount',
                $days === null ? self::OPEN_CHART_DAYS : max($days, self::MIN_CHART_DAYS),
            ),
            'branchTotals' => $this->branchTotals($onSide($inPeriod())),
            'today' => DailySeries::today()->toDateString(),
            'scopeLabel' => $this->scopeLabel($request, $actor),
            'filters' => $this->dataTableState($request, 'created_at', 'desc', self::DEFAULT_PER_PAGE),
            'filterOptions' => $this->filterOptions($actor),
            // A line's subscription's statement, opened over the log.
            'statement' => fn () => $this->requestedStatement($request, $actor),
        ]);
    }

    /**
     * The lines the user may see — of subscriptions in their own branch (any
     * branch for the Super Admin) — narrowed by the search box and the
     * filters. Unordered, so it can be totalled.
     *
     * @return Builder<SubscriptionTransaction>
     */
    private function filteredLedger(Request $request, User $actor): Builder
    {
        $search = $this->searchTerm($request);
        $branchId = $actor->hasFinancialAuditAccess() ? $this->filterValue($request, 'branch_id') : null;
        $type = $this->filterValue($request, 'type');
        $recordedBy = $this->filterValue($request, 'recorded_by');

        return SubscriptionTransaction::query()
            ->whereHas('subscription', fn (Builder $subscriptions) => $subscriptions
                ->readableTo($actor, true)
                ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->whereHas('subscription', fn (Builder $subscriptions) => $subscriptions->where(fn (Builder $inner) => $inner
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('subscription_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('subscription_phone', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")))
                ->orWhere('voucher_number', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('voucher_number', ltrim($search, '0') ?: '0'))
                ->orWhere('manual_voucher_number', 'like', "%{$search}%")
                ->orWhere('reference_number', 'like', "%{$search}%")))
            ->when($type === self::DEBIT, fn (Builder $query) => $query->charges())
            ->when($type === self::CREDIT, fn (Builder $query) => $query->credits())
            ->when(
                ! in_array($type, [self::DEBIT, self::CREDIT], true) && array_key_exists((string) $type, SubscriptionTransaction::typeLabels()),
                fn (Builder $query) => $query->where('type', $type),
            )
            ->when($recordedBy, fn (Builder $query) => $query->where('recorded_by', $recordedBy))
            ->when($this->filterValue($request, 'payment_method'), fn (Builder $query, string $method) => $query->where('payment_method', $method))
            ->when($this->filterValue($request, 'bank_name'), fn (Builder $query, string $bank) => $query->where('bank_name', $bank))
            ->when($this->filterValue($request, 'show_cancelled') === '0', fn (Builder $query) => $query->counted());
    }

    /**
     * Newest first unless a column header asks otherwise; "amount" sorts by
     * the size of the line, whichever side it is on.
     *
     * @param  Builder<SubscriptionTransaction>  $query
     * @return Builder<SubscriptionTransaction>
     */
    private function sortEntries(Builder $query, Request $request): Builder
    {
        $direction = $request->input('sort') !== null && $request->string('direction')->lower()->value() === 'asc' ? 'asc' : 'desc';

        return ($this->sortedByAmount($request)
            ? $query->orderByRaw('abs(subscription_transactions.amount) '.$direction)
            : $query->orderBy('subscription_transactions.created_at', $direction))
            ->orderBy('subscription_transactions.id', $direction);
    }

    private function sortedByAmount(Request $request): bool
    {
        return $request->input('sort') === 'amount';
    }

    /**
     * The headline figures for the period — total, count, average and
     * largest line — and the same total over as many days just before it.
     * `collected` is what was paid in the period, shown beside the charges.
     *
     * @param  Builder<SubscriptionTransaction>  $headline  the period's lines on the headline side
     * @param  Builder<SubscriptionTransaction>|null  $ledger  every line on that side, for the period before
     * @param  Builder<SubscriptionTransaction>  $inPeriod  every line of the period, both sides
     * @return array{total: float, count: int, average: float, largest: float, previousTotal: float|null, changePct: int|null, collected: float}
     */
    private function summary(Builder $headline, ?Builder $ledger, ?CarbonImmutable $from, ?int $days, Builder $inPeriod, string $dateBasis = 'recorded'): array
    {
        $totals = $headline->toBase()->selectRaw('count(*) as entries, coalesce(sum(abs(amount)), 0) as total, coalesce(max(abs(amount)), 0) as largest')->first();
        $total = round((float) $totals->total, 2);
        $count = (int) $totals->entries;

        $previousTotal = $ledger && $from && $days
            ? round((float) $this->dateFiltered($ledger, $from->subDays($days), $from, $dateBasis)
                ->toBase()
                ->selectRaw('coalesce(sum(abs(amount)), 0) as total')
                ->value('total'), 2)
            : null;

        return [
            'total' => $total,
            'count' => $count,
            'average' => $count > 0 ? round($total / $count, 2) : 0.0,
            'largest' => round((float) $totals->largest, 2),
            'previousTotal' => $previousTotal,
            'changePct' => $previousTotal ? (int) round(($total - $previousTotal) / $previousTotal * 100) : null,
            'collected' => round(-(float) $inPeriod->counted()->whereIn('type', [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT])->sum('amount'), 2),
        ];
    }

    /**
     * Each listed day's full figures — however many of its lines this page
     * shows — for the headers that group the table by day: how many lines,
     * and the charges and the credits that day.
     *
     * @param  Builder<SubscriptionTransaction>  $inPeriod
     * @param  array<int, string>  $days
     * @return array<string, array{count: int, charged: float, credited: float}>
     */
    private function dayTotals(Builder $inPeriod, array $days): array
    {
        if ($days === []) {
            return [];
        }

        $from = CarbonImmutable::parse(min($days), config('app.business_timezone'))->utc();
        $until = CarbonImmutable::parse(max($days), config('app.business_timezone'))->addDay()->utc();

        return $inPeriod
            ->counted()
            ->where('subscription_transactions.created_at', '>=', $from)
            ->where('subscription_transactions.created_at', '<', $until)
            ->toBase()
            ->get(['subscription_transactions.created_at as moment', 'amount', 'type'])
            ->groupBy(fn (object $line): string => DailySeries::localDate($line->moment))
            ->only($days)
            ->map(fn ($lines): array => [
                'count' => $lines->count(),
                'charged' => round($lines->reject(fn (object $line): bool => SubscriptionTransaction::isCreditLine($line->type, $line->amount))->sum('amount'), 2),
                'credited' => round(-$lines->filter(fn (object $line): bool => SubscriptionTransaction::isCreditLine($line->type, $line->amount))->sum('amount'), 2),
            ])
            ->all();
    }

    /**
     * The period's headline total and line count per branch, largest first.
     *
     * @param  Builder<SubscriptionTransaction>  $headline
     * @return array<int, array{id: int, name: string, total: float, count: int}>
     */
    private function branchTotals(Builder $headline): array
    {
        $totals = $headline
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_transactions.subscription_id')
            ->toBase()
            ->selectRaw('subscriptions.branch_id, sum(abs(subscription_transactions.amount)) as total, count(*) as entries')
            ->groupBy('subscriptions.branch_id')
            ->get();

        $names = Branch::query()->whereIn('id', $totals->pluck('branch_id'))->pluck('name', 'id');

        return $totals
            ->map(fn (object $branch): array => [
                'id' => (int) $branch->branch_id,
                'name' => $names[$branch->branch_id] ?? '—',
                'total' => round((float) $branch->total, 2),
                'count' => (int) $branch->entries,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function dateFiltered(Builder $query, ?CarbonImmutable $from, CarbonImmutable $until, string $dateBasis): Builder
    {
        if ($dateBasis === 'recorded') {
            return $query->when($from, fn (Builder $lines) => $lines->where('subscription_transactions.created_at', '>=', $from))
                ->where('subscription_transactions.created_at', '<', $until);
        }

        return $query->where(function (Builder $dates) use ($from, $until): void {
            $dates->where(function (Builder $paid) use ($from, $until): void {
                $paid->whereNotNull('subscription_transactions.paid_on')
                    ->when($from, fn (Builder $lines) => $lines->where('subscription_transactions.paid_on', '>=', $from->setTimezone(config('app.business_timezone'))->toDateString()))
                    ->where('subscription_transactions.paid_on', '<', $until->setTimezone(config('app.business_timezone'))->toDateString());
            })->orWhere(function (Builder $recorded) use ($from, $until): void {
                $recorded->whereNull('subscription_transactions.paid_on')
                    ->when($from, fn (Builder $lines) => $lines->where('subscription_transactions.created_at', '>=', $from))
                    ->where('subscription_transactions.created_at', '<', $until);
            });
        });
    }

    private function row(SubscriptionTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'day' => DailySeries::localDate($transaction->created_at),
            'time' => DailySeries::localTime($transaction->created_at),
            'paidOn' => $transaction->paid_on?->toDateString(),
            'bankReceivedOn' => $transaction->bank_received_on?->toDateString(),
            'lateReason' => $transaction->late_reason,
            'subscriptionId' => $transaction->subscription_id,
            'subscriptionName' => $transaction->subscription->displayName(),
            'subscriptionAccountNumber' => $transaction->subscription->account_number,
            'subscriptionPhone' => $transaction->subscription->contactPhone(),
            'subscriptionStatus' => $transaction->subscription->status->value,
            'subscriptionStatusLabel' => __($transaction->subscription->status->label()),
            'branchName' => $transaction->subscription->branch->name,
            'type' => $transaction->type,
            'typeLabel' => $transaction->typeLabel(),
            'isCredit' => $transaction->isCredit(),
            // Listed, but left out of the totals.
            'isCancelled' => $transaction->isCancelled() || $transaction->isReversal(),
            'recordedByName' => $transaction->recordedBy?->name,
            'amount' => ltrim($transaction->amount, '-'),
            'voucherNumber' => $transaction->displayVoucherNumber(),
            'isManualVoucher' => (bool) $transaction->manual_voucher_number,
            'paymentMethod' => $transaction->payment_method?->value,
            'paymentMethodLabel' => $transaction->payment_method ? __($transaction->payment_method->label()) : null,
            'bankName' => $transaction->bank_name,
            'referenceNumber' => $transaction->reference_number,
            'splitPayment' => $transaction->splitPayment?->badge(),
            'balanceAfter' => $transaction->balance_after,
            'currency' => $transaction->currency,
            'currencyAmount' => $transaction->currency_amount,
            'exchangeRate' => $transaction->exchange_rate,
        ];
    }

    /**
     * What the log covers, shown above its title: every branch, the branch
     * the Super Admin filtered on, or the user's own branch.
     */
    private function scopeLabel(Request $request, User $actor): string
    {
        if (! $actor->hasFinancialAuditAccess()) {
            return $actor->workBranch()?->name ?? '—';
        }

        $branchId = $this->filterValue($request, 'branch_id');

        return ($branchId ? $actor->scopeAuditBranches(Branch::query(), 'branches.id')->find($branchId)?->name : null) ?? ($actor->auditSettings()['scope'] === 'selected' ? 'الفروع المحددة للتدقيق' : 'كل الفروع');
    }

    /**
     * The filters: the branch (the Super Admin's only — everyone else's log
     * is their own branch), the type of line or a whole side, and who
     * recorded it.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = $actor->hasFinancialAuditAccess() ? [$this->branchFilterGroup($actor)] : [];

        $groups[] = $this->filterGroup('type', 'نوع القيد', [
            ['value' => self::DEBIT, 'label' => 'كل ما عليه (تحميل)'],
            ['value' => self::CREDIT, 'label' => 'كل ما له (تسديد وخصم ومقاصة)'],
            ...collect(SubscriptionTransaction::typeLabels())->map(fn (string $label, string $type): array => ['value' => $type, 'label' => $label])->values(),
        ]);

        $groups[] = $this->filterGroup('payment_method', 'طريقة الدفع', collect(PaymentMethod::cases())
            ->map(fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => __($method->label())])->all());

        $groups[] = $this->filterGroup('bank_name', 'البنك', SubscriptionTransaction::query()
            ->whereHas('subscription', fn (Builder $subscriptions) => $subscriptions->readableTo($actor, true))
            ->whereNotNull('bank_name')->where('bank_name', '!=', '')
            ->distinct()->orderBy('bank_name')->pluck('bank_name')
            ->map(fn (string $bank): array => ['value' => $bank, 'label' => $bank])->all());

        $groups[] = $this->filterGroup('recorded_by', 'سجّله', $this->staffOptions(
            User::query()
                ->whereIn('id', SubscriptionTransaction::query()
                    ->whereHas('subscription', fn (Builder $subscriptions) => $subscriptions->readableTo($actor, true))
                    ->select('recorded_by'))
                ->orderBy('name')
                ->get(),
        ));

        return $groups;
    }

    /**
     * Which side the headline figures sum: the credits when the type filter
     * picks payments, discounts, clearings or everything له; otherwise the charges.
     */
    private function headlineSide(Request $request): string
    {
        $type = $this->filterValue($request, 'type');

        return $type === self::CREDIT || in_array($type, SubscriptionTransaction::CREDIT_TYPES, true) ? self::CREDIT : self::DEBIT;
    }

    private function period(Request $request): string
    {
        $period = $request->query('period');

        return is_string($period) && array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * @return array{CarbonImmutable|null, CarbonImmutable, int|null}
     */
    private function dateWindow(Request $request, string $period): array
    {
        $today = DailySeries::today();
        $until = $today->addDay();
        $days = self::PERIODS[$period];
        $from = $days ? $today->subDays($days - 1) : null;

        if ($period === 'yesterday') {
            $from = $today->subDay();
            $until = $today;
        } elseif ($period === 'month') {
            $from = $today->startOfMonth();
            $days = $today->day;
        } elseif ($period === 'custom') {
            $dates = $request->validate([
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ]);
            $from = CarbonImmutable::parse($dates['from'], config('app.business_timezone'))->startOfDay();
            $until = CarbonImmutable::parse($dates['to'], config('app.business_timezone'))->startOfDay()->addDay();
            $days = (int) CarbonImmutable::parse($dates['from'], 'UTC')->diffInDays(CarbonImmutable::parse($dates['to'], 'UTC')) + 1;
        }

        return [$from?->utc(), $until->utc(), $days];
    }

    /**
     * @param  Builder<SubscriptionTransaction>  $query
     * @return array{charged: float, debitCount: int, credited: float, creditCount: int, net: float, cancelled: float, cancelledCount: int}
     */
    private function ledgerTotals(Builder $query): array
    {
        $aggregate = fn (Builder $lines): object => $lines->toBase()->selectRaw('count(*) as entries, coalesce(sum(abs(amount)), 0) as total')->first();
        $debits = $aggregate((clone $query)->charges());
        $credits = $aggregate((clone $query)->credits());
        $cancelled = $aggregate((clone $query)->whereNotNull('cancelled_at')->whereNull('reverses_id'));
        $charged = round((float) $debits->total, 2);
        $credited = round((float) $credits->total, 2);

        return [
            'charged' => $charged, 'debitCount' => (int) $debits->entries,
            'credited' => $credited, 'creditCount' => (int) $credits->entries,
            'net' => round($charged - $credited, 2),
            'cancelled' => round((float) $cancelled->total, 2), 'cancelledCount' => (int) $cancelled->entries,
        ];
    }

    /**
     * @param  Builder<SubscriptionTransaction>  $lines
     */
    private function export(Builder $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($lines): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['التاريخ', 'الوقت', 'المشترك', 'الحساب', 'الفرع', 'النوع', 'السند', 'الطريقة', 'البنك', 'المرجع', 'سجّله', 'عليه', 'له', 'الرصيد بعده', 'حالة الرصيد', 'ملغاة', 'تاريخ الدفع الفعلي', 'سبب تأخر التسجيل', 'تاريخ وصول الحوالة إلى البنك']);

            foreach ($lines->lazy(500) as $transaction) {
                $row = $this->row($transaction);
                $cells = [$row['day'], $row['time'], $row['subscriptionName'], $row['subscriptionAccountNumber'], $row['branchName'], $row['typeLabel'], $row['voucherNumber'], $row['paymentMethodLabel'], $row['bankName'], $row['referenceNumber'], $row['recordedByName'], $row['isCredit'] ? '' : (float) $row['amount'], $row['isCredit'] ? (float) $row['amount'] : '', $row['balanceAfter'] === null ? '' : (float) $row['balanceAfter'], $this->balanceWord($row['balanceAfter']), $row['isCancelled'] ? 'نعم' : 'لا'];
                $cells[] = $row['paidOn'];
                $cells[] = $row['lateReason'];
                $cells[] = $row['bankReceivedOn'];
                fputcsv($file, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+@\-\t\r]/u', $cell) ? "'".$cell : $cell, $cells));
            }

            fclose($file);
        }, 'ledger-'.DailySeries::today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * What a balance is called on paper beside its signed number: owed
     * (عليه), credit (له) or settled (مسدّد); nothing when there is none.
     */
    private function balanceWord(int|float|string|null $balance): string
    {
        if ($balance === null) {
            return '';
        }

        $cents = (int) round((float) $balance * 100);

        return $cents > 0 ? 'عليه' : ($cents < 0 ? 'له' : 'مسدّد');
    }

    /**
     * One `?filter[key]=` value, or null when it is missing, empty or not
     * plain text.
     */
    private function filterValue(Request $request, string $key): ?string
    {
        $value = $request->input("filter.{$key}");

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
