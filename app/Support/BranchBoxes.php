<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The branch's boxes as the people who close and audit them see them, on
 * the financial boxes page (its open and closed boxes, and the
 * entries of its open boxes): which branch the actor looks at, and its
 * boxes in each place with what the actor may do to them.
 */
class BranchBoxes
{
    public function __construct(private BranchClosingWorkflow $workflow) {}

    /**
     * The branches the actor may open (every branch for administrators and auditors, else
     * their own) and the one asked for with `?branch_id=`, else the first.
     *
     * @return array{0: EloquentCollection<int, Branch>, 1: Branch}
     */
    public function branch(Request $request, User $actor): array
    {
        $allBranches = $actor->isSuperAdmin() || BranchClosingAccess::audit($actor);
        $branches = $allBranches ? $actor->scopeAuditBranches(Branch::query(), 'branches.id')->orderBy('name')->get(['id', 'name']) : Branch::query()->whereKey($actor->workBranchId())->get(['id', 'name']);
        $branchId = $allBranches ? ($request->integer('branch_id') ?: $branches->first()?->id) : $actor->workBranchId();
        $branch = $branches->firstWhere('id', $branchId);
        abort_unless($branch, 404);

        return [$branches, $branch];
    }

    /**
     * The people who had boxes in the branch, by name, to narrow its boxes
     * and entries to one of them.
     *
     * @param  int|list<int>  $branchId
     * @return EloquentCollection<int, User>
     */
    public function people(int|array $branchId): EloquentCollection
    {
        return User::query()->whereIn('id', CashBox::query()->whereIn('branch_id', (array) $branchId)->whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name']);
    }

    /** The two states of a financial box, independent of its historical audit metadata. */
    public const PLACES = ['open', 'closed'];

    /** Who closed and sent a box, besides its entries, for the boxes page. */
    private const PEOPLE = ['receivedBy:id,name', 'sentBy:id,name', 'audit.auditor:id,name', 'payments.audit.auditor:id,name'];

    /**
     * Boxes in one state, narrowed to a week for closed boxes. A linked box
     * may be included separately, subject to the same branch and audit scope.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $branchId, User $actor, string $place, ?int $alsoBoxId = null, ?string $week = null): Collection
    {
        abort_if($actor->hasFinancialAuditAccess() && ! $actor->auditIncludesBranch($branchId), 404);
        $query = $this->in($branchId, $place);
        if ($place === 'closed' && $week) {
            $query->inClosingWeek(ClosingPeriods::date($week));
        }
        $details = [...BranchClosingWorkflow::BOX_DETAILS, ...self::PEOPLE, 'payments' => fn ($entries) => $entries
            ->visibleInBox()
            ->when(BranchClosingAccess::audit($actor), fn (Builder $payments) => $payments
                ->whereHas('transaction', fn (Builder $transaction) => $transaction->where('status', SubscriptionTransaction::STATUS_ACTIVE)))];
        $boxes = $query->with($details)->get();
        if ($alsoBoxId && ! $boxes->contains('id', $alsoBoxId)) {
            $boxes->push(...CashBox::query()->where('branch_id', $branchId)->whereKey($alsoBoxId)
                ->when(BranchClosingAccess::closedOnly($actor, $branchId), fn (Builder $query) => $query->where('status', 'received'))
                ->with($details)->get());
        }
        $ledgers = $this->ledgers($boxes->flatMap(fn (CashBox $box) => $box->payments->pluck('subscription_transaction_id'))->all());

        return $boxes->map(function (CashBox $box) use ($ledgers, $actor): array {
            $row = $this->present($box, $ledgers);
            if ($actor->hasFinancialAuditAccess() && ! $actor->canViewAllSubscriberAccounts()) {
                $row['payments'] = array_map(fn (array $entry): array => [...$entry, 'statement_url' => null], $row['payments']);
            }
            $active = $box->payments->filter(fn ($entry): bool => $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE);
            $reviewed = $active->filter(fn ($entry): bool => $entry->audit !== null);
            $canAudit = $box->status === 'received' && BankTransferAuditing::allowed($actor) && $box->audit === null;

            return [
                ...$row,
                'canCount' => $this->workflow->mayReceive($box, $actor),
                'isOwn' => $box->user_id === $actor->id,
                'canAudit' => $canAudit || ($box->status === 'received' && $actor->canAuditAction(AuditAction::ApproveBoxes) && $box->audit === null),
                'canReviewTransfers' => $canAudit,
                'canCompleteAudit' => $box->status === 'received' && $actor->canAuditAction(AuditAction::ApproveBoxes) && $box->audit === null,
                'canPrint' => ! $actor->hasFinancialAuditAccess() || $actor->canAuditAction(AuditAction::PrintReports),
                'completeAuditUrl' => route('payments.box-audits.store', $box),
                'auditSummary' => [
                    'total' => $active->count(), 'reviewed' => $reviewed->count(),
                    'verified' => Closing::money($reviewed->sum(fn ($entry): int => Closing::cents($entry->amount) - Closing::cents($entry->audit->difference))),
                    'difference' => Closing::money($reviewed->sum(fn ($entry): int => Closing::cents($entry->audit->difference))),
                ],
            ];
        });
    }

    /**
     * Weekly browsing periods use the actual closing date in business time,
     * without rewriting a box or its original closing's saved schedule.
     *
     * @return Collection<int, array{start: string, end: string, count: int, reviewed: int}>
     */
    public function periods(int $branchId): Collection
    {
        return CashBox::query()->where('branch_id', $branchId)->where('status', 'received')
            ->with('closing:id,coverage_until')->withExists('audit')->get(['id', 'closing_id', 'close_reason', 'received_at', 'closed_at', 'opened_at'])
            ->groupBy(fn (CashBox $box): string => $this->weekOf($box->closingWeekMoment(), $branchId))
            ->map(fn (Collection $boxes, string $start): array => ['start' => $start, 'end' => ClosingPeriods::date($start)->addDays(6)->toDateString(), 'count' => $boxes->count(), 'reviewed' => $boxes->where('audit_exists', true)->count()])
            ->sortKeysDesc()->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    public function periodAudit(Collection $rows, int $branchId, string $week): array
    {
        $pending = $rows->filter(fn (array $box): bool => $box['status'] === 'received' && $box['audit'] === null)->sortBy('id')->values();
        $transfers = $pending->where('type', 'bank_transfer')->flatMap(fn (array $box): array => $box['payments'])
            ->filter(fn (array $entry): bool => ($entry['transaction']['type'] ?? null) === SubscriptionTransaction::TYPE_PAYMENT && $entry['transaction']['status'] === SubscriptionTransaction::STATUS_ACTIVE);
        $drafts = $transfers->filter(fn (array $entry): bool => isset($entry['audit']) && ! $entry['audit']['posted_at'] && ! $entry['audit']['adjustment_transaction_id']);
        $differences = $drafts->map(fn (array $entry): int => Closing::cents($entry['audit']['difference']));
        $snapshot = $pending->map(fn (array $box): array => [
            'id' => $box['id'], 'type' => $box['type'], 'closed_at' => $box['closed_at'],
            'payments' => collect($box['payments'])->sortBy('id')->map(fn (array $entry): array => [
                'id' => $entry['id'], 'transaction' => $entry['subscription_transaction_id'],
                'amount' => $entry['amount'], 'currency' => $entry['currency'],
                'status' => $entry['transaction']['status'] ?? null, 'audit' => $entry['audit'] ?? null,
            ])->values()->all(),
        ])->all();

        return [
            'branch_id' => $branchId, 'week' => $week, 'box_ids' => $pending->pluck('id')->all(),
            'snapshot' => hash('sha256', json_encode([$branchId, $week, $snapshot], JSON_THROW_ON_ERROR)),
            'boxes' => $pending->count(), 'transfers' => $transfers->count(),
            'exceptions' => $differences->filter(fn (int $amount): bool => $amount !== 0)->count(),
            'automaticMatches' => $transfers->whereNull('audit')->count(),
            'debit' => Closing::money($differences->filter(fn (int $amount): bool => $amount > 0)->sum()),
            'credit' => Closing::money(-$differences->filter(fn (int $amount): bool => $amount < 0)->sum()),
        ];
    }

    public function weekOf(CarbonInterface $moment, int $branchId): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('app.business_timezone'))
            ->startOfWeek(ClosingSetting::forBranch($branchId)->week_starts_on)->toDateString();
    }

    /**
     * A box as the boxes page shows it: its entries as the subscriber's
     * statement shows them, where it is (`place`), and when it was opened,
     * closed and sent and by whom.
     *
     * @param  array<int, array<string, mixed>>  $ledgers
     * @return array<string, mixed>
     */
    private function present(CashBox $box, array $ledgers = []): array
    {
        $box->user?->makeHidden('permissions');
        $row = $box->toArray();
        unset($row['received_by'], $row['sent_by']);

        return [
            ...$row,
            'status' => $box->isEditable() ? 'open' : $box->status,
            'place' => $this->placeOf($box),
            'stage' => $box->stage(),
            'payments' => array_map(fn (array $entry): array => $this->presentEntry($entry, $ledgers), $row['payments'] ?? []),
            'closed_at' => $box->isEditable() ? null : $box->closed_at,
            'closedAt' => $box->isEditable() ? null : ($box->received_at ?? $box->closed_at),
            'closedBy' => $box->close_reason === CashBox::CLOSE_REASON_PERIOD_END ? 'النظام · إغلاق تلقائي' : $box->receivedBy?->name,
            'sentBy' => $box->sentBy?->name,
        ];
    }

    /**
     * How many of the branch's boxes are in each place, for the tabs.
     *
     * @param  int|list<int>  $branchId
     * @return array<string, int>
     */
    public function counts(int|array $branchId): array
    {
        return collect(self::PLACES)->mapWithKeys(fn (string $place): array => [$place => $this->in($branchId, $place)->count()])->all();
    }

    private function placeOf(CashBox $box): string
    {
        return match (true) {
            $box->status === 'received' => 'closed',
            default => 'open',
        };
    }

    /**
     * The branch's boxes in one place. An open box shows once it holds an
     * entry; closed boxes retain their original records.
     *
     * @param  int|list<int>  $branchId
     * @return Builder<CashBox>
     */
    private function in(int|array $branchId, string $place): Builder
    {
        $boxes = CashBox::query()->whereIn('branch_id', (array) $branchId);

        return match ($place) {
            'open' => $boxes->whereIn('status', ['open', 'pending'])->whereHas('payments', fn (Builder $entries) => $entries->visibleInBox())->latest('id'),
            'closed' => $boxes->where('status', 'received')->orderByDesc('received_at')->orderByDesc('id'),
        };
    }

    /**
     * Each of the transactions as the subscriber's statement shows it, by id.
     *
     * @param  array<int, int|null>  $transactionIds
     * @return array<int, array<string, mixed>>
     */
    public function ledgers(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_filter($transactionIds)));

        return $ids === [] ? [] : SubscriptionTransaction::query()->whereIn('id', $ids)->with('recordedBy:id,name')->get()
            ->mapWithKeys(fn (SubscriptionTransaction $transaction): array => [$transaction->id => $transaction->ledgerDetails()])->all();
    }

    /**
     * A box entry with links to its subscriber's statement (opened at the
     * entry's own transaction) and, for a
     * payment or clearing, its voucher, and its transaction as the statement shows it
     * when found among `$ledgers`. Entries of a statement saved before the
     * transaction's type was kept get no receipt link.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, array<string, mixed>>  $ledgers
     * @return array<string, mixed>
     */
    public function presentEntry(array $entry, array $ledgers = []): array
    {
        $subscriptionId = $entry['transaction']['subscription_id'] ?? null;
        $vouched = in_array($entry['transaction']['type'] ?? null, [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING], true);

        return [
            ...$entry,
            'statement_url' => $subscriptionId ? route('subscriptions.statement', [$subscriptionId, 'transaction' => $entry['subscription_transaction_id'] ?? null]) : null,
            'receipt_url' => $subscriptionId && $vouched ? route('subscriptions.payments.receipt', [$subscriptionId, $entry['transaction']['id']]) : null,
            'audit_url' => isset($entry['id']) ? route('payments.bank-audits.store', $entry['id']) : null,
            'ledger' => $ledgers[$entry['subscription_transaction_id'] ?? null] ?? null,
        ];
    }

    /**
     * What the actor may do in the branch's closing.
     *
     * @return array{manage: bool, receive: bool}
     */
    public function access(User $actor, int $branchId): array
    {
        return ['manage' => BranchClosingAccess::manage($actor, $branchId), 'receive' => BranchClosingAccess::receive($actor, $branchId)];
    }
}
