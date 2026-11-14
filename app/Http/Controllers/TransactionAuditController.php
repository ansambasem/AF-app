<?php

namespace App\Http\Controllers;

use App\Enums\TransactionAction;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\FiltersDataTable;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionAmendment;
use App\Models\TransactionDeletion;
use App\Models\User;
use App\Support\DailySeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The audit log (سجل التدقيق): every change made to the subscriptions' account
 * lines after they were recorded, newest first — details edited in place,
 * lines cancelled or corrected, payments refunded and lines deleted for
 * good — with who did it, when and why. Read-only; a row opens the
 * subscription's statement over the page.
 */
class TransactionAuditController extends Controller
{
    use BuildsSubscriptionStatement, FiltersDataTable;

    /**
     * The period tabs, as the number of days each covers (null: since the
     * first change).
     */
    private const PERIODS = ['today' => 1, '7' => 7, '30' => 30, '90' => 90, 'all' => null];

    private const DEFAULT_PERIOD = '30';

    private const DEFAULT_PER_PAGE = 25;

    /**
     * The kinds of change, as the "الإجراء" filter and column name them.
     *
     * @var array<string, string>
     */
    private const KINDS = [
        'amendment' => 'تعديل',
        'cancellation' => 'إلغاء',
        'correction' => 'تصحيح',
        'refund' => 'إرجاع',
        'deletion' => 'حذف نهائي',
    ];

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewTransactionAudit', SubscriptionTransaction::class);

        $actor = $request->user();
        $period = $this->period($request);
        $days = self::PERIODS[$period];
        $from = $days ? DailySeries::startOfDay($days - 1) : null;
        $kind = $this->filterValue($request, 'kind');

        $inPeriod = fn (): QueryBuilder => $this->filteredEvents($request, $actor)
            ->when($from, fn (QueryBuilder $query) => $query->where('happened_at', '>=', $from));

        $events = $inPeriod()
            ->when(array_key_exists((string) $kind, self::KINDS), fn (QueryBuilder $query) => $query->where('kind', $kind))
            ->orderByDesc('happened_at')
            ->orderByDesc('event_id')
            ->paginate($this->dataTablePerPage($request, self::DEFAULT_PER_PAGE))
            ->withQueryString();

        $rows = $this->rows(collect($events->items()));
        $events->setCollection($rows);

        return Inertia::render('TransactionAudit/Index', [
            'events' => $events,
            'period' => $period,
            'counts' => $this->counts($inPeriod()),
            'today' => DailySeries::today()->toDateString(),
            'scopeLabel' => $actor->hasFinancialAuditAccess() ? ($actor->auditSettings()['scope'] === 'selected' ? 'الفروع المحددة للتدقيق' : 'كل الفروع') : ($actor->workBranch()?->name ?? '—'),
            'filters' => $this->dataTableState($request, 'happened_at', 'desc', self::DEFAULT_PER_PAGE),
            'filterOptions' => $this->filterOptions($actor),
            // A row's subscription's statement, opened over the log.
            'statement' => fn () => $this->requestedStatement($request, $actor),
        ]);
    }

    /**
     * Every change as one list — `kind`, `event_id` (the row of its own
     * table), `happened_at`, `user_id` (who made it) and `subscription_id` —
     * of subscriptions the user may see, narrowed by the search box, the
     * branch and who made it. Unordered, so it can be counted.
     */
    private function filteredEvents(Request $request, User $actor): QueryBuilder
    {
        $search = $this->searchTerm($request);
        $branchId = $actor->hasFinancialAuditAccess() ? $this->filterValue($request, 'branch_id') : null;
        $userId = $this->filterValue($request, 'user_id');
        $narrowsSubscriptions = ($actor->hasFinancialAuditAccess() && $actor->auditSettings()['scope'] === 'selected') || ! $actor->hasFinancialAuditAccess() || $branchId !== null || $search !== '';

        $amendments = DB::table('transaction_amendments')
            ->join('subscription_transactions', 'subscription_transactions.id', '=', 'transaction_amendments.transaction_id')
            ->selectRaw("'amendment' as kind, transaction_amendments.id as event_id, transaction_amendments.created_at as happened_at, transaction_amendments.user_id as user_id, subscription_transactions.subscription_id as subscription_id");

        $reversals = DB::table('subscription_transactions as reversal')
            ->whereIn('reversal.type', SubscriptionTransaction::REVERSAL_TYPES)
            ->selectRaw(
                "case when reversal.type = ? then 'refund' "
                ."when reversal.type = ? and exists (select 1 from subscription_transactions as replacement where replacement.corrects_id = reversal.reverses_id) then 'correction' "
                ."else 'cancellation' end as kind, reversal.id as event_id, reversal.created_at as happened_at, reversal.recorded_by as user_id, reversal.subscription_id as subscription_id",
                [SubscriptionTransaction::TYPE_REFUND, SubscriptionTransaction::TYPE_REVERSAL],
            );

        $deletions = DB::table('transaction_deletions')
            ->selectRaw("'deletion' as kind, id as event_id, created_at as happened_at, user_id, subscription_id");

        return DB::query()
            ->fromSub($amendments->unionAll($reversals)->unionAll($deletions), 'events')
            ->when($narrowsSubscriptions, fn (QueryBuilder $query) => $query->whereIn('subscription_id', Subscription::query()
                ->readableTo($actor, true)
                ->when($branchId, fn (Builder $subscriptions) => $subscriptions->where('branch_id', $branchId))
                ->when($search !== '', fn (Builder $subscriptions) => $subscriptions->matchingSearch($search))
                ->select('subscriptions.id')))
            ->when($userId, fn (QueryBuilder $query) => $query->where('user_id', $userId));
    }

    /**
     * How many changes of each kind the period holds.
     *
     * @return array<string, int>
     */
    private function counts(QueryBuilder $inPeriod): array
    {
        $counts = $inPeriod->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return collect(self::KINDS)->map(fn (string $label, string $kind): int => (int) ($counts[$kind] ?? 0))->all();
    }

    /**
     * The page's changes, each with what the log shows of it.
     *
     * @param  Collection<int, object{kind: string, event_id: int}>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $events): Collection
    {
        $ids = fn (array $kinds): array => $events->whereIn('kind', $kinds)->pluck('event_id')->all();
        $amendments = TransactionAmendment::query()->with(['user', 'transaction.subscription.branch'])->findMany($ids(['amendment']))->keyBy('id');
        $reversals = SubscriptionTransaction::query()
            ->with(['recordedBy', 'subscription.branch', 'reverses.correction'])
            ->findMany($ids(['cancellation', 'correction', 'refund']))
            ->keyBy('id');
        $deletions = TransactionDeletion::query()->with(['user', 'subscription.branch'])->findMany($ids(['deletion']))->keyBy('id');

        return $events
            ->map(fn (object $event): ?array => match ($event->kind) {
                'amendment' => $amendments->has($event->event_id) ? $this->amendmentRow($amendments[$event->event_id]) : null,
                'deletion' => $deletions->has($event->event_id) ? $this->deletionRow($deletions[$event->event_id]) : null,
                default => $reversals->has($event->event_id) ? $this->reversalRow($event->kind, $reversals[$event->event_id]) : null,
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function amendmentRow(TransactionAmendment $amendment): array
    {
        $line = $amendment->transaction;

        return [
            ...$this->eventBase('amendment', $amendment->id, $amendment->created_at, $amendment->user, $line->subscription),
            'lines' => [$this->lineSummary($line->type, $line->amount, $line->displayVoucherNumber())],
            'changes' => collect($amendment->changes)->map(fn (array $values, string $field): array => [
                'label' => $this->amendmentFieldLabel($field),
                'from' => $values[0] ?? null,
                'to' => $values[1] ?? null,
            ])->values()->all(),
            'reason' => $amendment->reason,
            'notes' => null,
        ];
    }

    /**
     * A cancellation, a correction or a refund: the reversal line it
     * added, and why from the line it reverses.
     *
     * @return array<string, mixed>
     */
    private function reversalRow(string $kind, SubscriptionTransaction $reversal): array
    {
        $original = $reversal->reverses;
        $isFullRefund = $kind === 'refund' && $original?->status === SubscriptionTransaction::STATUS_LINKED_CANCELLATION;

        return [
            ...$this->eventBase($kind, $reversal->id, $reversal->created_at, $reversal->recordedBy, $reversal->subscription),
            'kindNote' => $kind === 'refund' ? ($isFullRefund ? 'كامل' : 'جزئي') : null,
            'lines' => [
                $original
                    ? $this->lineSummary($original->type, $original->amount, $original->displayVoucherNumber())
                    : $this->lineSummary($reversal->type, $reversal->amount, null),
                ...($kind === 'refund' ? [['label' => 'المبلغ المُرجع', 'amount' => SubscriptionTransaction::formatAmount(abs((float) $reversal->amount)), 'voucherNumber' => null]] : []),
            ],
            'changes' => [],
            // A partial refund leaves the payment standing, without a reason of its own.
            'reason' => $original?->cancellation_reason && ($kind !== 'refund' || $isFullRefund) ? __($original->cancellation_reason->label()) : null,
            'notes' => $kind !== 'refund' || $isFullRefund ? $original?->cancellation_notes : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deletionRow(TransactionDeletion $deletion): array
    {
        return [
            ...$this->eventBase('deletion', $deletion->id, $deletion->created_at, $deletion->user, $deletion->subscription),
            'kindNote' => match ($deletion->action) {
                TransactionAction::DeleteReversal->value => 'الإلغاء فقط، وعادت الحركة الأصلية',
                TransactionAction::DeleteTree->value => 'الحركة وكل ما ارتبط بها',
                default => null,
            },
            'lines' => collect($deletion->transactions)
                ->map(fn (array $line): array => $this->lineSummary(
                    (string) ($line['type'] ?? ''),
                    (string) ($line['amount'] ?? '0'),
                    filled($line['manual_voucher_number'] ?? null)
                        ? $line['manual_voucher_number']
                        : (filled($line['voucher_number'] ?? null) ? str_pad((string) $line['voucher_number'], 6, '0', STR_PAD_LEFT) : null),
                ))
                ->all(),
            'changes' => [],
            'reason' => $deletion->reason,
            'notes' => null,
        ];
    }

    /**
     * What every row shows: its kind, when (business time), who made the
     * change, and whose account it was on.
     *
     * @return array<string, mixed>
     */
    private function eventBase(string $kind, int $id, mixed $at, ?User $user, ?Subscription $subscription): array
    {
        return [
            'key' => $kind.'-'.$id,
            'kind' => $kind,
            'kindLabel' => self::KINDS[$kind],
            'kindNote' => null,
            'day' => DailySeries::localDate($at),
            'time' => DailySeries::localTime($at),
            'userName' => $user?->name,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'name' => $subscription->displayName(),
                'accountNumber' => $subscription->account_number,
                'status' => $subscription->status->value,
                'statusLabel' => __($subscription->status->label()),
                'branchName' => $subscription->branch->name,
            ] : null,
        ];
    }

    /**
     * A line as the log names it: its type, its amount in shekels and its
     * voucher, if any.
     *
     * @return array{label: string, amount: string, voucherNumber: ?string}
     */
    private function lineSummary(string $type, string $amount, ?string $voucherNumber): array
    {
        return [
            'label' => SubscriptionTransaction::typeLabels()[$type] ?? 'حركة',
            'amount' => SubscriptionTransaction::formatAmount(abs((float) $amount)),
            'voucherNumber' => $voucherNumber,
        ];
    }

    /**
     * The filters: the branch (the Super Admin's only — everyone else's
     * log is their own branch), the kind of change, and who made it.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = $actor->hasFinancialAuditAccess() ? [$this->branchFilterGroup($actor)] : [];

        $groups[] = $this->filterGroup('kind', 'الإجراء', collect(self::KINDS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values());
        $groups[] = $this->filterGroup('user_id', 'بواسطة', $this->staffOptions(
            User::query()
                ->when($actor->hasFinancialAuditAccess() && $actor->auditSettings()['scope'] === 'selected', fn (Builder $users) => $users->whereIn('id', $this->filteredEvents(new Request, $actor)->select('user_id')))
                // Branch staff see their branch's staff and the Super Admins, who have no branch.
                ->when(! $actor->hasFinancialAuditAccess(), fn (Builder $users) => $users->where(fn (Builder $inBranch) => $inBranch
                    ->where('branch_id', $actor->workBranchId())
                    ->orWhereNull('branch_id')))
                ->orderBy('name')
                ->get(),
        ));

        return $groups;
    }

    private function period(Request $request): string
    {
        $period = $request->query('period');

        return is_string($period) && array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
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
