<?php

namespace App\Http\Controllers;

use App\Http\Concerns\FiltersDataTable;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\SubscriptionTransaction;
use App\Support\BranchReport;
use App\Support\BranchScope;
use App\Support\ClosingPeriods;
use App\Support\DailySeries;
use App\Support\FinancialReportAccess;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): InertiaResponse
    {
        abort_unless(FinancialReportAccess::view($request->user()), 403);
        $branches = FinancialReportAccess::branches($request->user());
        ['chosen' => $chosen, 'branch' => $branch, 'from' => $from, 'to' => $to, 'today' => $today, 'kind' => $kind] = $this->filters($request, $branches);
        $report = new BranchReport($chosen, $from, $to);
        $period = ReportPeriod::describe($from, $to, $request->query('view'), $today);
        $collections = $chosen->isEmpty() ? null : $report->collections();
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
        $branchSummary = $chosen->count() > 1
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
        $flow = $chosen->isEmpty() ? null : $report->flow();
        $scopeLabel = $chosen->count() === 1 ? $chosen->first()->name : 'كل الفروع';
        $transactions = $report->lines($kind)
            ->with(['subscription.branch', 'subscription.meterBox', 'recordedBy', 'meterReading', 'reverses.meterReading', 'bankTransferAudit'])
            ->orderByDesc('subscription_transactions.created_at')
            ->orderByDesc('subscription_transactions.id')
            ->paginate($request->boolean('print') ? $this->dataTablePerPage($request, self::PER_PAGE) : self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SubscriptionTransaction $line): array => $this->row($line));
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

        return Inertia::render('Reports/Index', [
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
            'readings' => $chosen->isEmpty() ? null : $report->readings(),
            'days' => $chosen->isEmpty() ? [] : $report->days(),
            'transactions' => $transactions,
        ]);
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
            ['key' => 'month', 'label' => 'هذا الشهر', 'from' => $today->startOfMonth(), 'to' => $today],
            ['key' => 'last-month', 'label' => 'الشهر الماضي', 'from' => $lastMonthStart, 'to' => $lastMonthEnd],
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
