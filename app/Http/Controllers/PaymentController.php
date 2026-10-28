<?php

namespace App\Http\Controllers;

use App\Enums\CashBoxType;
use App\Enums\ChargeType;
use App\Enums\DiscountMethod;
use App\Enums\PaymentMethod;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Concerns\BuildsSubscriptionStatement;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\FiltersSubscriptionList;
use App\Http\Concerns\PresentsCashBoxes;
use App\Http\Requests\StoreSplitPaymentRequest;
use App\Models\AuditNote;
use App\Models\Bank;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\CollectorDebt;
use App\Models\MeterBox;
use App\Models\StandingDiscount;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use App\Support\ClosingPeriods;
use App\Support\DailySeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PaymentController extends Controller
{
    use BuildsSubscriptionStatement, FiltersDataTable, FiltersSubscriptionList, PresentsCashBoxes;

    public function __construct(private BranchClosingWorkflow $workflow) {}

    /** The columns the subscribers table may be sorted by. */
    private const SORTABLE = ['account_number', 'display_name', 'status', 'outstanding_balance'];

    /**
     * The "Balance" filter: what a subscriber owes, against the sum of their
     * account's lines (positive is what they owe).
     */
    private const BALANCE_FILTERS = ['owing' => ['>', 'عليه رصيد مستحق'], 'settled' => ['=', 'مسدّد'], 'credit' => ['<', 'له رصيد دائن']];

    /** The most subscriptions the split form's search lists; a longer list asks the user to narrow the search. */
    private const RESULT_LIMIT = 12;

    /** The payments listed under the day's totals. */
    private const RECENT_PAYMENTS = 8;

    /**
     * The quick payments page: the subscribers of the user's branch in a table
     * (search, filters and sort, with each one's balance) with a button on each
     * to record their payment and a menu for the account's other lines
     * (charge, discount, clearing, a split transfer), and a button for one
     * transfer shared between several subscribers; what the user collected
     * is on the summary page. It takes only the "Record Collections"
     * permission, so it works for someone who cannot open the subscriptions
     * list; the charge, discount and clearing need "Adjust Balances" too.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('recordAnyPayment', Subscription::class);

        $actor = $request->user();
        $canAddDiscounts = $actor->hasPermission(PermissionKey::AddDiscounts);
        $query = Subscription::query()
            ->select('subscriptions.*')
            ->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) as display_name")
            ->visibleTo($actor)
            ->with(['branch', 'meterBox.subArea', 'circuitBreaker', 'profile', ...($canAddDiscounts ? ['standingDiscount.grantedBy'] : [])])
            ->withSum('transactions as outstanding_balance', 'amount');
        $this->applyTableFilters($query, $request);

        return Inertia::render('Payments/Index', [
            'subscriptions' => fn () => $query->paginate($this->dataTablePerPage($request))
                ->withQueryString()
                ->through(fn (Subscription $subscription): array => [
                    ...$this->row($subscription),
                    'canAddCharge' => $actor->can('addCharge', $subscription),
                    'canAddDiscount' => $actor->can('addDiscount', $subscription),
                    'canAddClearing' => $actor->can('addClearing', $subscription),
                    'standingDiscount' => $canAddDiscounts ? $this->statementStandingDiscount($subscription->standingDiscount) : null,
                ]),
            'scopeLabel' => $actor->isSuperAdmin() ? 'كل الفروع' : ($actor->workBranch()?->name ?? '—'),
            'filters' => $this->dataTableState($request, 'display_name'),
            'filterOptions' => fn () => $this->filterOptions($actor, $request),
            // A subscriber's statement, opened over the list from their row.
            'statement' => fn () => $this->requestedStatement($request, $actor),
            'pendingCount' => fn () => $this->collectionCounts($actor)['pendingCount'],
            'collectionUrls' => $this->collectionUrls(),
            // The ways this user may take a payment: what the payment form offers.
            'paymentMethods' => PaymentMethod::options($actor->allowedPaymentMethods()),
            'canSplitPayments' => $actor->can('recordSplitPayment', Subscription::class),
            'transferBanks' => fn () => Bank::receiverNames(),
            'senderBanks' => fn () => Bank::senderNames(),
            'chargeTypes' => ChargeType::formOptions(),
            'discountMethods' => DiscountMethod::options(),
            'discountSegments' => fn () => $canAddDiscounts ? StandingDiscount::segmentSuggestions() : [],
        ]);
    }

    /**
     * The collector's summary: what they collected today and their latest
     * payments, what waits on them (a handover to confirm, audit notes,
     * debts), and the boxes open now with every entry in them, filtered by
     * `?filter[type]=` to one type of box and searched as the branch's
     * entries are. The entries of a closed box are looked over inside it.
     */
    public function summary(Request $request): InertiaResponse
    {
        $this->authorize('viewOwnCashBoxes', Subscription::class);

        $actor = $request->user();
        $cashBox = $this->ownCashBoxes($actor);

        return Inertia::render('Payments/Summary', [
            'today' => $this->todaysPayments($actor),
            'cashBox' => $cashBox,
            'current' => $cashBox['current'],
            ...$this->entryList($request, $this->ownOpenEntries($actor), fn (CashBox $box): string => route('payments.cash-boxes.show', $box)),
            'needsAction' => $cashBox['needsAction'],
            ...$this->collectionCounts($actor),
            'collectionUrls' => $this->collectionUrls(),
        ]);
    }

    /**
     * The entries of the actor's own open boxes in their branch.
     *
     * @return Builder<CashBoxPayment>
     */
    private function ownOpenEntries(User $actor): Builder
    {
        return CashBoxPayment::query()->whereHas('box', fn (Builder $box) => $box->where('branch_id', $actor->workBranchId())->where('user_id', $actor->id)->whereIn('status', ['open', 'pending']));
    }

    /**
     * The actor's boxes, with what waits on them: received cash boxes to
     * confirm, audit notes to answer, and the shortfalls they still owe.
     *
     * @return array{started: bool, branchName: ?string, boxes: array<int, array<string, mixed>>, current: array<string, array<string, mixed>|null>, needsAction: array<int, array<string, mixed>>, auditNotes: array<int, array<string, mixed>>, debts: array<int, array<string, mixed>>}
     */
    private function ownCashBoxes(User $actor, bool $includeRecentHistory = true): array
    {
        if (! $this->boxesStarted($actor)) {
            return ['started' => false, 'branchName' => $actor->workBranch()?->name, 'boxes' => [], 'current' => [], 'needsAction' => [], 'auditNotes' => [], 'debts' => []];
        }

        $this->workflow->refresh($actor->workBranchId());
        $query = $this->ownBoxQuery($actor);
        $outstanding = (clone $query)->whereNull('confirmed_at')->get();
        $history = $includeRecentHistory ? (clone $query)->whereNotNull('confirmed_at')->limit(10)->get() : collect();
        $rows = $outstanding->concat($history)->map(fn (CashBox $box): array => $this->cashBoxRow($box));

        return [
            'started' => true,
            'branchName' => $actor->workBranch()?->name,
            'boxes' => $rows->values()->all(),
            'current' => collect(CashBoxType::cases())->mapWithKeys(fn (CashBoxType $type): array => [$type->value => $rows->first(fn (array $box): bool => $box['type'] === $type->value && $box['status'] === 'open')])->all(),
            'needsAction' => $rows->filter(fn (array $box): bool => $box['stage'] === 'awaiting_confirmation')->values()->all(),
            'auditNotes' => $this->waitingAuditNotes($actor)->with(['box:id,number', 'closing:id,coverage_from,coverage_until,workflow_settings', 'entry.transaction.subscription:id,full_name,subscription_name,account_number', 'replies' => fn ($query) => $query->where('kind', 'collector')->latest('id')])
                ->latest('id')->get()->map(fn (AuditNote $note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'question' => $note->replies->first()?->body,
                    'differences' => $note->differences,
                    'closing' => $note->closing->only(['coverage_from', 'coverage_until', 'workflow_settings']),
                    'entry' => $note->entry?->transaction ? [
                        'subscriber' => $note->entry->transaction->subscription->displayName(),
                        'voucherNumber' => $note->entry->transaction->displayVoucherNumber(),
                        'typeLabel' => $note->entry->transaction->typeLabel(),
                        'amount' => $note->entry->amount, 'currency' => $note->entry->currency,
                    ] : null,
                    'box' => $note->box ? ['number' => $note->box->number, 'url' => route('payments.cash-boxes.show', $note->box)] : null,
                    'replyUrl' => route('payments.audit-notes.reply', $note),
                ])->all(),
            'debts' => CollectorDebt::query()->where('user_id', $actor->id)->where('branch_id', $actor->workBranchId())->where('remaining', '>', 0)
                ->with('box:id,number')->oldest('id')->get()->map(fn (CollectorDebt $debt): array => [
                    'id' => $debt->id,
                    'currency' => $debt->currency,
                    'amount' => $debt->amount,
                    'remaining' => $debt->remaining,
                    'createdAt' => $debt->created_at,
                    'box' => $debt->box ? ['number' => $debt->box->number, 'url' => route('payments.cash-boxes.show', $debt->box)] : null,
                ])->all(),
        ];
    }

    /**
     * The table's search, filters and sort. The search finds a name, account
     * or old number, phone or meter box number; the balance filter sorts
     * those who owe from those settled or in credit.
     *
     * @param  Builder<Subscription>  $query
     */
    private function applyTableFilters(Builder $query, Request $request): void
    {
        $search = $this->searchTerm($request);

        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        $this->applyDataTableFilters($query, $request, [], self::SORTABLE, 'display_name');
        $this->applyDataTableFilterSelects($query, $request, ['status', 'branch_id', 'tariff_id', 'meter_box_id']);
        $this->applyMeterBoxNameFilter($query, $request);
        $this->applySubAreaFilter($query, $request);

        $balance = self::BALANCE_FILTERS[(string) $this->filterValue($request, 'balance')] ?? null;

        if ($balance !== null) {
            $query->whereRaw('(select coalesce(sum(amount), 0) from subscription_transactions where subscription_transactions.subscription_id = subscriptions.id) '.$balance[0].' 0');
        }
    }

    /**
     * The Filter menu's dropdowns: what they owe, status, subscription type,
     * منطقة 2 and meter box — and the branch, for the Super Admin.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array<string, mixed>>}>
     */
    private function filterOptions(User $actor, Request $request): array
    {
        $groups = [
            $this->filterGroup('balance', 'الرصيد', collect(self::BALANCE_FILTERS)->map(fn (array $filter, string $value): array => ['value' => $value, 'label' => $filter[1]])),
            $this->filterGroup('status', 'الحالة', SubscriptionStatus::options()),
            $this->filterGroup('tariff_id', 'نوع الاشتراك', $this->modelOptions(Tariff::orderBy('category')->get(), fn (Tariff $tariff): string => __($tariff->category->label()))),
            $this->subAreaFilterGroup(SubArea::query()->visibleTo($actor)->orderBy('name')->get()),
            ...$this->meterBoxFilterGroupsFor($actor, $request, $actor->isSuperAdmin() ? fn (MeterBox $box): string => $box->branch->name : null),
        ];

        if ($actor->isSuperAdmin()) {
            $groups[] = $this->branchFilterGroup();
        }

        return $groups;
    }

    /**
     * The split payment form's search, as JSON: the subscriptions matching
     * `?search=`, or — with `?siblings_of=` — the other subscriptions under
     * the same identity number as that one, so a payer's accounts are added
     * to the split together.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('recordAnyPayment', Subscription::class);

        $actor = $request->user();
        $siblingsOf = $request->query('siblings_of');

        if (is_string($siblingsOf) && ctype_digit($siblingsOf)) {
            $source = Subscription::query()->visibleTo($actor)->findOrFail($siblingsOf);
            $found = $source->subscriber_profile_id === null ? collect() : Subscription::query()
                ->visibleTo($actor)
                ->with(['branch', 'meterBox', 'circuitBreaker', 'profile'])
                ->withSum('transactions as outstanding_balance', 'amount')
                ->where('subscriber_profile_id', $source->subscriber_profile_id)
                ->whereKeyNot($source->id)
                ->orderBy('account_number')
                ->limit(StoreSplitPaymentRequest::MAX_PARTS)
                ->get();

            return response()->json(['subscriptions' => $found->map(fn (Subscription $subscription): array => $this->row($subscription))->values(), 'hasMore' => false]);
        }

        $search = trim((string) $request->query('search', ''));
        $found = $search === '' ? collect() : $this->matching($actor, $search);

        return response()->json([
            'subscriptions' => $found->take(self::RESULT_LIMIT)->map(fn (Subscription $subscription): array => $this->row($subscription))->values(),
            'hasMore' => $found->count() > self::RESULT_LIMIT,
        ]);
    }

    /**
     * The subscriptions of the user's branch (any branch for the Super Admin)
     * whose name, account name, account or old number or meter box number
     * match every word of the search, or whose phone contains its digits.
     *
     * @return Collection<int, Subscription>
     */
    private function matching(User $actor, string $search): Collection
    {
        return $this->applySearch(Subscription::query()
            ->visibleTo($actor)
            ->with(['branch', 'meterBox', 'circuitBreaker', 'profile'])
            ->withSum('transactions as outstanding_balance', 'amount'), $search)
            ->orderBy('full_name')
            ->limit(self::RESULT_LIMIT + 1)
            ->get();
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

    /**
     * Keep the subscriptions whose name, account name, account or old number
     * or meter box number match every word of the search, or whose phone
     * contains its digits.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $digits = preg_replace('/\D/', '', $search);

        return $query->where(function (Builder $matching) use ($search, $digits): void {
            $matching->matchingSearch($search);

            if (strlen($digits) >= 3) {
                $matching->orWhere('phone', 'like', '%'.$digits.'%')->orWhere('subscription_phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * One search result: who, where, and what they owe, in the shape the
     * payment form reads.
     *
     * @return array<string, mixed>
     */
    private function row(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'fullName' => $subscription->displayName(),
            'accountNumber' => $subscription->account_number,
            'subscriberNumber' => $subscription->profile?->subscriber_number,
            'phone' => $subscription->contactPhone(),
            'meterBoxNumber' => $subscription->meterBox?->box_number,
            'meterBoxName' => $subscription->meterBox?->displayName(),
            'subAreaId' => $subscription->meterBox?->sub_area_id,
            'subAreaName' => $subscription->meterBox?->subArea?->name,
            'branchName' => $subscription->branch->name,
            'status' => $subscription->status->value,
            'statusLabel' => __($subscription->status->label()),
            'balance' => number_format((float) ($subscription->outstanding_balance ?? 0), 2, '.', ''),
            'weeklyMinimumPayment' => $subscription->weeklyMinimumPayment(),
        ];
    }

    /**
     * What the user has collected in the current business day: the totals
     * (cancelled payments left out), and their latest payments.
     *
     * @return array{count: int, total: string, cash: string, transfers: string, payments: array<int, array<string, mixed>>}
     */
    private function todaysPayments(User $actor): array
    {
        $periods = ClosingPeriods::for($actor->workBranchId());
        $day = $periods->dayOf(now());
        [$from, $until] = $periods->utcRange($day, $day);

        $payments = SubscriptionTransaction::query()
            ->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->whereNull('cancelled_at')
            ->where('recorded_by', $actor->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->with('subscription')
            ->latest('id')
            ->get();

        $sumOf = fn (Collection $lines): string => number_format($lines->sum(fn (SubscriptionTransaction $payment): float => -(float) $payment->amount), 2, '.', '');
        $cash = $payments->filter(fn (SubscriptionTransaction $payment): bool => $payment->payment_method === PaymentMethod::Cash);

        return [
            'count' => $payments->count(),
            'total' => $sumOf($payments),
            'cash' => $sumOf($cash),
            'transfers' => $sumOf($payments->reject(fn (SubscriptionTransaction $payment): bool => $payment->payment_method === PaymentMethod::Cash)),
            'payments' => $payments->take(self::RECENT_PAYMENTS)->map(fn (SubscriptionTransaction $payment): array => [
                'id' => $payment->id,
                'subscriptionName' => $payment->subscription->displayName(),
                'amount' => SubscriptionTransaction::formatAmount(ltrim($payment->amount, '-')),
                'methodLabel' => $payment->payment_method ? __($payment->payment_method->label()) : null,
                'bankName' => $payment->bank_name,
                'voucherNumber' => $payment->displayVoucherNumber(),
                'time' => DailySeries::localTime($payment->created_at),
                'receiptUrl' => route('subscriptions.payments.receipt', [$payment->subscription, $payment]),
            ])->values()->all(),
        ];
    }
}
