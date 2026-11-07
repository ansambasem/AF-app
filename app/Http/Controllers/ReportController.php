<?php

namespace App\Http\Controllers;

use App\Http\Concerns\FiltersDataTable;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Support\ArabicSearch;
use App\Support\BranchReport;
use App\Support\BranchScope;
use App\Support\ClosingPeriods;
use App\Support\DailySeries;
use App\Support\DebtAging;
use App\Support\FinancialReportAccess;
use App\Support\ReportDetails;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports page (التقارير): a branch's day — or any stretch of days, or
 * every branch the user may see: how what the subscriptions owe moved,
 * where the payments came in, the readings, and every line, which download
 * as a CSV. Report access retains the user's existing branch scope.
 */
class ReportController extends Controller
{
    use FiltersDataTable;

    /**
     * The longest stretch a report covers.
     */
    private const MAX_DAYS = 92;

    private const PER_PAGE = 50;

    /**
     * The summary sections (overview, collections, movements) carry the
     * period's figures and lines for the screen, print and Excel; the
     * detail sections (boxes, debts, statement) build only their own table.
     */
    public function index(Request $request): InertiaResponse
    {
        abort_unless(FinancialReportAccess::view($request->user()), 403);
        $section = $request->validate(['section' => ['nullable', Rule::in(['overview', 'collections', 'boxes', 'debts', 'statement', 'movements'])]])['section'] ?? 'overview';
        $branches = FinancialReportAccess::branches($request->user());
        ['chosen' => $chosen, 'branch' => $branch, 'from' => $from, 'to' => $to, 'today' => $today, 'kind' => $kind] = $this->filters($request, $branches);
        $report = new BranchReport($chosen, $from, $to);
        $details = new ReportDetails($chosen, $from, $to);
        $debtData = $section === 'debts' ? $details->debts() : null;
        $boxData = $section === 'boxes' ? $details->boxes() : null;
        $statementData = $section === 'statement' ? $this->statement($request, $chosen, $from, $to) : null;
        $accountDirectory = $section === 'statement' && $statementData === null && $request->user()->can('viewAny', Subscription::class)
            ? $this->accountDirectory($request, $chosen) : null;
        $detailTable = match ($section) {
            'debts' => $this->detailTable($request, collect($debtData['rows'])->map(fn (array $row): array => [...$row, ...$row['buckets']]), $section),
            'boxes' => $this->detailTable($request, collect($boxData['rows']), $section),
            'statement' => $statementData ? $this->detailTable($request, collect($statementData['lines']), $section) : null,
            default => null,
        };
        $period = ReportPeriod::describe($from, $to, $request->query('view'), $today);
        $summary = $chosen->isNotEmpty() && in_array($section, ['overview', 'collections', 'movements'], true);
        $collections = $summary ? $report->collections() : null;
        $comparison = null;
        if ($collections !== null) {
            $previous = (new BranchReport($chosen, ClosingPeriods::date($period['comparison']['from']), ClosingPeriods::date($period['comparison']['to'])))->collections();
            $previousCents = Closing::cents($previous['total']);
            $difference = Closing::cents($collections['total']) - $previousCents;
            $comparison = [
                ...$period['comparison'],
                'total' => $previous['total'],
                'difference' => Closing::money($difference),
                'percentage' => $previousCents === 0 ? null : round($difference / $previousCents * 100, 1),
            ];
        }
        $branchSummary = $summary && $chosen->count() > 1
            ? $chosen->map(function (Branch $item) use ($from, $to): array {
                $branchReport = new BranchReport(collect([$item]), $from, $to);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'flow' => $branchReport->flow(),
                    'collections' => $branchReport->collections(),
                ];
            })->values()
            : collect();
        $flow = $summary ? $report->flow() : null;
        $scopeLabel = $chosen->count() === 1 ? $chosen->first()->name : 'كل الفروع';
        $transactions = $summary
            ? $report->lines($kind)
                ->with(['subscription.branch', 'subscription.meterBox', 'recordedBy', 'meterReading', 'reverses.meterReading', 'bankTransferAudit'])
                ->orderByDesc('subscription_transactions.created_at')
                ->orderByDesc('subscription_transactions.id')
                ->paginate($request->boolean('print') ? $this->dataTablePerPage($request, self::PER_PAGE) : self::PER_PAGE)
                ->withQueryString()
                ->through(fn (SubscriptionTransaction $line): array => $this->row($line))
            : null;
        $printData = null;
        if ($request->boolean('print') && $flow !== null) {
            $detail = $request->query('print_view') === 'lines';
            $columns = $detail
                ? ['التاريخ', 'الوقت', 'الفرع', 'السند', 'المشترك', 'رقم الحساب', 'البيان', 'الحساب', 'عليه', 'له', 'سجّله', 'الحالة']
                : ['البند', 'العدد', 'المبلغ ₪'];
            $rows = $detail ? $transactions->getCollection()->map(fn (array $line): array => [
                $line['day'], $line['time'], $line['branchName'], $line['voucherNumber'], $line['subscriptionName'], $line['accountNumber'],
                $line['description'], $line['account'], $line['isCredit'] ? '' : $line['amount'], $line['isCredit'] ? $line['amount'] : '',
                $line['recordedBy'], $line['isCancelled'] ? 'ملغى / قيد عكسي' : '',
            ])->all() : $this->summaryPrintRows($flow, $collections, $branchSummary, $comparison);
            $printData = [
                'columns' => array_map(fn (string $label): array => ['key' => $label, 'label' => $label], $columns),
                'rows' => array_map(fn (array $row): array => ['type' => 'row', 'cells' => array_combine($columns, array_map(fn (mixed $value): string => (string) $value, $row))], $rows),
                'total' => $detail ? $transactions->total() : count($rows),
                'summary' => $scopeLabel.' · '.$from->format('d/m/Y').' – '.$to->format('d/m/Y').($detail ? ' · '.collect(BranchReport::kinds())->firstWhere('value', $kind)['label'] : ' · الملخص لجميع أنواع الحركات'),
                'context' => ['branchName' => $scopeLabel, 'templatePage' => '/reports'],
            ];
        }
        if ($request->boolean('print') && in_array($section, ['debts', 'boxes', 'statement'], true)) {
            [$columns, $rows] = match ($section) {
                'debts' => [['المشترك', 'رقم الحساب', 'الفرع', 'حتى 30 يومًا', '31–60 يومًا', '61–90 يومًا', 'أكثر من 90 يومًا', 'الرصيد عليه', 'آخر دفعة'], collect($detailTable['rows'])->map(fn (array $row): array => [$row['name'], $row['accountNumber'], $row['branchName'], ...array_values($row['buckets']), $row['balance'], $row['lastPaymentDate'] ?? ''])->all()],
                'boxes' => [['الصندوق', 'المحصّل', 'الفرع', 'النوع', 'الحالة', 'العملة', 'المبلغ المسجل', 'عدد الحركات'], collect($detailTable['rows'])->map(fn (array $row): array => [$row['number'], $row['collector'], $row['branchName'], $row['type'], $row['status'], $row['currency'], $row['amount'], $row['count']])->all()],
                default => [['التاريخ', 'السند', 'البيان', 'عليه (مدين)', 'له (دائن)', 'الرصيد'], $statementData ? [
                    [$from->toDateString(), '', 'الرصيد الافتتاحي', '', '', $statementData['opening']],
                    ...collect($detailTable['rows'])->map(fn (array $line): array => [$line['day'], $line['voucherNumber'], $line['description'], $line['debit'] === '0.00' ? '' : $line['debit'], $line['credit'] === '0.00' ? '' : $line['credit'], $line['balance']])->all(),
                    [$to->toDateString(), '', 'الرصيد الختامي / إجمالي الفترة', $statementData['debit'], $statementData['credit'], $statementData['closing']],
                ] : []],
            };
            if ($section === 'debts') {
                $rows[] = ['الإجمالي', '', '', ...array_map(fn (string $key): string => $detailTable['totals'][$key], array_keys(DebtAging::BUCKETS)), $detailTable['totals']['balance'], ''];
            }
            if ($section === 'statement' && $statementData && $detailTable['active']) {
                array_shift($rows);
                array_pop($rows);
                $rows[] = ['', '', 'إجمالي الحركات المطابقة', $detailTable['totals']['debit'], $detailTable['totals']['credit'], ''];
            }
            $identity = $statementData ? $statementData['subscription']['name'].' · '.$statementData['subscription']['accountNumber'].' · ' : '';
            $printData = [
                'columns' => array_map(fn (string $label): array => ['key' => $label, 'label' => $label], $columns),
                'rows' => array_map(fn (array $row): array => ['type' => 'row', 'cells' => array_combine($columns, array_map(fn (mixed $value): string => (string) $value, $row))], $rows),
                'total' => count($rows), 'summary' => $identity.$scopeLabel.' · '.$from->format('d/m/Y').' – '.$to->format('d/m/Y'),
                'context' => ['branchName' => $scopeLabel, 'templatePage' => '/reports'],
            ];
        }

        return Inertia::render('Reports/Index', [
            'reportSection' => $section,
            'reportAccounts' => $accountDirectory,
            'accountFilters' => $accountDirectory !== null ? $this->dataTableState($request, 'account_number') : null,
            'detailTable' => $detailTable === null ? null : $this->detailTablePage($request, $detailTable),
            'detailExport' => Inertia::optional(fn (): ?Collection => $detailTable['rows'] ?? null),
            'debts' => $debtData === null ? null : Arr::except($debtData, 'rows'),
            'boxData' => $boxData === null ? null : Arr::except($boxData, 'rows'),
            'reportStatement' => $statementData === null ? null : Arr::except($statementData, 'lines'),
            'canViewStatements' => $request->user()->can('viewAny', Subscription::class),
            'branches' => $branches->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name])->values(),
            'filters' => ['branch' => $branch, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'kind' => $kind],
            'scopeLabel' => $scopeLabel,
            'branchScope' => BranchScope::shownTo($request->user()) ? BranchScope::describe($chosen) : null,
            'canExport' => FinancialReportAccess::export($request->user()),
            'period' => $period,
            'comparison' => $comparison,
            'printData' => $printData,
            'branchSummary' => $branchSummary,
            'presets' => $this->presets($today),
            'today' => $today->toDateString(),
            'cutoff' => ClosingPeriods::for($chosen->count() === 1 ? $chosen->first() : null)->cutoff(),
            'kinds' => BranchReport::kinds(),
            'flow' => $flow,
            'collections' => $collections,
            'readings' => $summary ? $report->readings() : null,
            'days' => $summary ? $report->days() : [],
            'transactions' => $transactions,
        ]);
    }

    /** Filter presentation rows after historical running balances have been calculated. @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function detailTable(Request $request, Collection $rows, string $section): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'array'],
            'filter.*' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'max:40'],
        ]);
        $columns = match ($section) {
            'statement' => ['day', 'voucherNumber', 'description', 'debit', 'credit', 'balance'],
            'debts' => ['name', 'accountNumber', 'branchName', ...array_keys(DebtAging::BUCKETS), 'balance', 'lastPaymentDate'],
            default => ['number', 'collector', 'branchName', 'type', 'status', 'currency', 'amount', 'count'],
        };
        $allowedFilters = match ($section) {
            'statement' => ['side', 'type'],
            'debts' => ['age', 'branchName'],
            default => ['status', 'type', 'collector', 'currency'],
        };
        $defaultSort = $section === 'statement' ? 'day' : ($section === 'debts' ? 'balance' : 'number');
        $state = $this->dataTableState($request, $defaultSort, $section === 'debts' ? 'desc' : 'asc');
        $state['sort'] = in_array($validated['sort'] ?? '', $columns, true) ? $validated['sort'] : $defaultSort;
        $state['filter'] = array_intersect_key($validated['filter'] ?? [], array_flip($allowedFilters));
        $state['search'] = $validated['search'] ?? '';
        $options = collect($allowedFilters)->map(function (string $key) use ($rows): array {
            $values = match ($key) {
                'side' => [['value' => 'debit', 'label' => 'عليه (مدين)'], ['value' => 'credit', 'label' => 'له (دائن)']],
                'age' => collect(DebtAging::BUCKET_LABELS)->map(fn (string $label, string $value): array => compact('value', 'label'))->values()->all(),
                default => $rows->unique($key)->filter(fn (array $row): bool => filled($row[$key] ?? null))->map(fn (array $row): array => ['value' => (string) $row[$key], 'label' => $key === 'type' ? ($row['typeLabel'] ?? $row[$key]) : $row[$key]])->values()->all(),
            };
            $labels = ['side' => 'جهة الحركة', 'type' => 'نوع الحركة', 'age' => 'عمر الدين', 'branchName' => 'الفرع', 'status' => 'الحالة', 'collector' => 'المحصّل', 'currency' => 'العملة'];

            return $this->filterGroup($key, $labels[$key], $values);
        })->all();
        $terms = ArabicSearch::terms($state['search']);
        $matching = $rows->filter(function (array $row) use ($terms, $state, $columns): bool {
            $text = ArabicSearch::normalize(implode(' ', array_map(fn (string $key): string => (string) ($row[$key] ?? ''), $columns)));
            foreach ($terms as $term) {
                if (! str_contains($text, $term)) {
                    return false;
                }
            }
            foreach ($state['filter'] as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $matches = match ($key) {
                    'side' => in_array($value, ['debit', 'credit'], true) && Closing::cents($row[$value]) > 0,
                    'age' => in_array($value, array_keys(DebtAging::BUCKETS), true) && Closing::cents($row[$value]) > 0,
                    default => (string) ($row[$key] ?? '') === $value,
                };
                if (! $matches) {
                    return false;
                }
            }

            return true;
        })->sortBy($state['sort'], SORT_REGULAR, $state['direction'] === 'desc')->values();
        $sumKeys = $section === 'statement' ? ['debit', 'credit'] : ($section === 'debts' ? [...array_keys(DebtAging::BUCKETS), 'balance'] : []);
        $totals = [];
        foreach ($sumKeys as $key) {
            $totals[$key] = Closing::money($matching->reject(fn (array $row): bool => $row['netted'] ?? false)->sum(fn (array $row): int => Closing::cents($row[$key])));
        }

        return ['rows' => $matching, 'state' => $state, 'options' => $options, 'total' => $matching->count(), 'unfilteredTotal' => $rows->count(), 'totals' => $totals, 'active' => $terms !== [] || collect($state['filter'])->contains(fn (?string $value): bool => filled($value))];
    }

    /**
     * The page of a detail table the screen shows. Printing uses every
     * matching row, and the Excel export asks for them as `detailExport`.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function detailTablePage(Request $request, array $table): array
    {
        $size = $table['state']['per_page'];
        $last = max(1, (int) ceil($table['total'] / $size));
        $page = min(max(1, $request->integer('page', 1)), $last);

        return [
            ...$table,
            'rows' => $table['rows']->forPage($page, $size)->values(),
            'meta' => ['current_page' => $page, 'last_page' => $last, 'from' => $table['total'] === 0 ? 0 : ($page - 1) * $size + 1, 'to' => min($page * $size, $table['total']), 'total' => $table['total']],
        ];
    }

    /** @param Collection<int, Branch> $branches */
    private function accountDirectory(Request $request, Collection $branches): LengthAwarePaginator
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'string', 'max:40']]);
        $sort = in_array($validated['sort'] ?? '', ['account_number', 'display_name'], true) ? $validated['sort'] : 'account_number';

        return Subscription::query()->readableTo($request->user())
            ->whereIn('subscriptions.branch_id', $branches->pluck('id')->all())
            ->when(! $request->user()->can('viewArchived', Subscription::class), fn (Builder $query) => $query->whereNull('archived_at'))
            ->select('subscriptions.*')->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) as display_name")
            ->with('branch:id,name')
            ->when(($validated['search'] ?? '') !== '', fn (Builder $query) => $query->matchingSearch($validated['search']))
            ->orderBy($sort, $this->sortDirection($request))->orderBy('subscriptions.id')
            ->paginate($this->dataTablePerPage($request))->withQueryString()
            ->through(fn (Subscription $subscription): array => ['id' => $subscription->id, 'name' => $subscription->displayName(), 'accountNumber' => $subscription->account_number, 'branchName' => $subscription->branch?->name]);
    }

    /** All signed ledger lines in the period, including reversals, share one running balance. @param Collection<int, Branch> $branches @return array<string, mixed>|null */
    private function statement(Request $request, Collection $branches, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $validated = $request->validate(['subscription' => ['nullable', 'integer', 'min:1']]);
        if (! isset($validated['subscription'])) {
            return null;
        }
        $subscription = Subscription::query()->readableTo($request->user())->whereIn('branch_id', $branches->pluck('id')->all())->findOrFail($validated['subscription']);
        $this->authorize('view', $subscription);
        [$start, $end] = ClosingPeriods::for($subscription->branch_id)->utcRange($from, $to);
        $opening = Closing::cents((string) $subscription->transactions()->where('created_at', '<', $start)->sum('amount'));
        $balance = $opening;
        $debit = 0;
        $credit = 0;
        $transactions = $subscription->transactions()->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->with(['subscription.branch', 'subscription.meterBox', 'recordedBy', 'meterReading', 'reverses.meterReading', 'bankTransferAudit'])
            ->orderBy('created_at')->orderBy('id')->get();
        $netted = $this->nettedWithinPeriod($transactions);
        $lines = $transactions->map(function (SubscriptionTransaction $line) use (&$balance, &$debit, &$credit, $netted): array {
            $amount = Closing::cents($line->amount);
            $balance += $amount;
            if (! isset($netted[$line->id])) {
                $debit += max(0, $amount);
                $credit += max(0, -$amount);
            }

            return [...$this->row($line), 'balance' => Closing::money($balance), 'debit' => Closing::money(max(0, $amount)), 'credit' => Closing::money(max(0, -$amount)), 'netted' => isset($netted[$line->id])];
        });

        return ['subscription' => ['id' => $subscription->id, 'name' => $subscription->displayName(), 'accountNumber' => $subscription->account_number, 'branchName' => $branches->firstWhere('id', $subscription->branch_id)?->name, 'url' => route('subscriptions.statement', $subscription)], 'opening' => Closing::money($opening), 'debit' => Closing::money($debit), 'credit' => Closing::money($credit), 'closing' => Closing::money($balance), 'lines' => $lines];
    }

    /**
     * The lines that cancel each other out inside the period: a cancelled
     * line and the reversal that takes it back, both recorded in it. They
     * stay on the statement but count in neither its debit nor its credit
     * total, as on the subscription's own statement page. A line cancelled
     * after the period still stood at its end, so it keeps counting.
     *
     * @param  Collection<int, SubscriptionTransaction>  $transactions
     * @return array<int, true>
     */
    private function nettedWithinPeriod(Collection $transactions): array
    {
        $ids = $transactions->pluck('id')->flip();

        return $transactions->filter(fn (SubscriptionTransaction $line): bool => $line->reverses_id !== null && $ids->has($line->reverses_id))
            ->flatMap(fn (SubscriptionTransaction $line): array => [$line->id, $line->reverses_id])
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * The summary's printable rows, using the same figures as the screen.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $collections
     * @param  Collection<int, array<string, mixed>>  $branches
     * @param  array<string, mixed>|null  $comparison
     * @return list<array{string, int|string, string}>
     */
    private function summaryPrintRows(array $flow, array $collections, Collection $branches, ?array $comparison): array
    {
        return [
            ['الرصيد الافتتاحي لذمم المشتركين', '', $flow['opening']],
            ...array_map(fn (array $line): array => ['+ '.$line['label'], $line['count'], $line['total']], $flow['charges']),
            ['مجموع التحميلات (عليه)', '', $flow['chargesTotal']],
            ...array_map(fn (array $line): array => ['− '.$line['label'], $line['count'], $line['total']], $flow['credits']),
            ['مجموع الدفعات والخصومات (له)', '', $flow['creditsTotal']],
            ['الإلغاءات والقيود العكسية', $flow['corrections']['count'], $flow['corrections']['total']],
            ['الرصيد الختامي لذمم المشتركين', '', $flow['closing']],
            ['إجمالي المحصّل', $collections['count'], $collections['total']],
            ['التحصيل النقدي', '', $collections['cash']],
            ['التحويلات والشيكات', '', $collections['nonCash']],
            ...($comparison === null ? [] : [
                ['المحصّل سابقًا ('.$comparison['from'].' – '.$comparison['to'].')', '', $comparison['total']],
                ['فرق التحصيل عن الفترة السابقة', '', $comparison['difference']],
            ]),
            ...array_map(fn (array $account): array => ['حساب: '.$account['label'], $account['count'], $account['total']], $collections['accounts']),
            ...array_map(fn (array $collector): array => ['محصّل: '.$collector['name'], $collector['count'], $collector['total']], $collections['collectors']),
            ...$branches->map(fn (array $branch): array => ['تحصيل: '.$branch['name'], $branch['collections']['count'], $branch['collections']['total']])->all(),
        ];
    }

    /**
     * The report's lines, oldest first, as a CSV file for a spreadsheet,
     * with the same filters as on the page. The branch's name heads the
     * file and names it, so a file shared later still says whose it is.
     */
    public function export(Request $request): StreamedResponse
    {
        ['chosen' => $chosen, 'from' => $from, 'to' => $to, 'lines' => $lines] = $this->exportedLines($request);
        $scope = BranchScope::describe($chosen)['name'];
        $columns = ['التاريخ', 'الوقت', 'الفرع', 'رقم السند', 'المشترك', 'رقم الحساب', 'الطبلون', 'النوع', 'البيان', 'طريقة الدفع', 'الحساب', 'العملة', 'المبلغ بالعملة', 'عليه', 'له', 'سجّله', 'ملغى'];

        return response()->streamDownload(function () use ($lines, $columns, $scope, $from, $to): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\u{FEFF}");
            fputcsv($file, ["{$scope} — التقارير المالية"]);
            fputcsv($file, ['الفترة: '.$from->format('d/m/Y').' – '.$to->format('d/m/Y')]);
            fputcsv($file, []);
            fputcsv($file, $columns);

            foreach ($lines->lazy(500) as $line) {
                $row = $this->row($line);
                fputcsv($file, [
                    $row['day'], $row['time'], $row['branchName'], $row['voucherNumber'], $row['subscriptionName'], $row['accountNumber'], $row['meterBoxNumber'],
                    $row['typeLabel'], $row['description'], $row['methodLabel'], $row['account'], $row['currency'], $row['currencyAmount'],
                    $row['isCredit'] ? '' : $row['amount'], $row['isCredit'] ? $row['amount'] : '', $row['recordedBy'], $row['isCancelled'] ? 'نعم' : '',
                ]);
            }

            fclose($file);
        }, 'التقارير_'.str_replace(' ', '-', $scope)."_{$from->toDateString()}_{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A bounded page of export lines, oldest first, and its next cursor.
     * Every request applies the same authorization and report filters.
     */
    public function lines(Request $request): JsonResponse
    {
        ['lines' => $lines] = $this->exportedLines($request);
        $page = $lines->cursorPaginate(500);

        return response()->json([
            'lines' => $page->getCollection()->map(fn (SubscriptionTransaction $line): array => $this->row($line))->values(),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * The lines a download holds, for whoever may export the report.
     *
     * @return array{chosen: Collection<int, Branch>, from: CarbonImmutable, to: CarbonImmutable, lines: Builder<SubscriptionTransaction>}
     */
    private function exportedLines(Request $request): array
    {
        abort_unless(FinancialReportAccess::export($request->user()), 403);
        ['chosen' => $chosen, 'from' => $from, 'to' => $to, 'kind' => $kind] = $this->filters($request, FinancialReportAccess::branches($request->user()));
        $lines = (new BranchReport($chosen, $from, $to))->lines($kind)
            ->with(['subscription.branch', 'subscription.meterBox', 'recordedBy', 'meterReading', 'reverses.meterReading', 'bankTransferAudit'])
            ->orderBy('subscription_transactions.created_at')
            ->orderBy('subscription_transactions.id');

        return ['chosen' => $chosen, 'from' => $from, 'to' => $to, 'lines' => $lines];
    }

    /**
     * The request's branch (one of the user's, or all of them when they see
     * more than one), its days (today by default, the day furthest along
     * among the branches; never past today, at most a quarter) and the kind
     * of line listed.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array{chosen: Collection<int, Branch>, branch: int|string|null, from: CarbonImmutable, to: CarbonImmutable, today: CarbonImmutable, kind: string}
     */
    private function filters(Request $request, Collection $branches): array
    {
        $validated = $request->validate([
            'branch' => ['nullable', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'kind' => ['nullable', Rule::in(array_column(BranchReport::kinds(), 'value'))],
        ]);
        $requested = $validated['branch'] ?? null;
        $all = ($requested === null || $requested === '' || $requested === 'all') && $branches->count() > 1;
        $branch = $all ? null : ($branches->firstWhere('id', (int) $requested)
            ?? $branches->firstWhere('id', $request->user()->branch_id)
            ?? $branches->first());
        $chosen = $all ? $branches : collect([$branch])->filter()->values();
        $today = ClosingPeriods::furthestToday($chosen);
        $to = isset($validated['to']) ? ClosingPeriods::date($validated['to']) : $today;
        $from = isset($validated['from']) ? ClosingPeriods::date($validated['from']) : $to;

        if ($to->greaterThan($today)) {
            $to = $today;
        }

        if ($from->greaterThan($to) || $from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'اختر فترة صحيحة لا تزيد على ثلاثة أشهر ولا تتجاوز اليوم.']);
        }

        return [
            'chosen' => $chosen,
            'branch' => $all ? 'all' : $branch?->id,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'kind' => $validated['kind'] ?? 'all',
        ];
    }

    /**
     * The quick picks above the report: today, yesterday, this week, this
     * month and last month, by the closing schedule's week and cut-off.
     *
     * @return array<int, array{key: string, label: string, from: string, to: string}>
     */
    private function presets(CarbonImmutable $today): array
    {
        [$weekStart] = ClosingPeriods::week($today);
        [$lastMonthStart, $lastMonthEnd] = ClosingPeriods::month($today->startOfMonth()->subDay());

        return array_map(fn (array $preset): array => [...$preset, 'from' => $preset['from']->toDateString(), 'to' => $preset['to']->toDateString()], [
            ['key' => 'today', 'label' => 'اليوم', 'from' => $today, 'to' => $today],
            ['key' => 'yesterday', 'label' => 'أمس', 'from' => $today->subDay(), 'to' => $today->subDay()],
            ['key' => 'week', 'label' => 'هذا الأسبوع', 'from' => $weekStart, 'to' => $today],
            ['key' => 'last-week', 'label' => 'الأسبوع الماضي', 'from' => $weekStart->subWeek(), 'to' => $weekStart->subDay()],
            ['key' => 'month', 'label' => 'هذا الشهر', 'from' => $today->startOfMonth(), 'to' => $today],
            ['key' => 'last-month', 'label' => 'الشهر الماضي', 'from' => $lastMonthStart, 'to' => $lastMonthEnd],
            ['key' => 'quarter', 'label' => 'آخر 90 يومًا', 'from' => $today->subDays(89), 'to' => $today],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SubscriptionTransaction $line): array
    {
        $isPayment = $line->isPayment();

        return [
            'id' => $line->id,
            'subscriptionId' => $line->subscription_id,
            'day' => ClosingPeriods::for($line->subscription->branch_id)->dayOf($line->created_at),
            'time' => DailySeries::localTime($line->created_at),
            'branchName' => $line->subscription->branch?->name,
            'voucherNumber' => $line->printedVoucherNumber(),
            'subscriptionName' => $line->subscription->displayName(),
            'accountNumber' => $line->subscription->account_number,
            'meterBoxNumber' => $line->subscription->meterBox?->box_number,
            'type' => $line->type,
            'typeLabel' => $line->typeLabel(),
            'description' => $line->description(),
            'methodLabel' => $isPayment && $line->payment_method ? __($line->payment_method->label()) : null,
            'account' => $isPayment ? ($line->bank_name ?? 'الصندوق النقدي') : null,
            'currency' => $isPayment ? $line->currency?->value : null,
            'currencyAmount' => $isPayment && $line->currency_amount !== null ? SubscriptionTransaction::formatAmount($line->currency_amount) : null,
            'isCredit' => $line->isCredit(),
            'isCancelled' => $line->isCancelled() || $line->isReversal(),
            'amount' => ltrim((string) $line->amount, '-'),
            'recordedBy' => $line->recordedBy?->name,
        ];
    }
}
