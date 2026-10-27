<?php

namespace App\Http\Controllers;

use App\Enums\DisconnectionEventType;
use App\Enums\DisconnectionItemStatus;
use App\Enums\DisconnectionReason;
use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\FiltersDisconnectionItems;
use App\Http\Concerns\PresentsDisconnectionItems;
use App\Http\Requests\StoreDisconnectionListRequest;
use App\Http\Requests\UpdateDisconnectionListRequest;
use App\Models\Branch;
use App\Models\DisconnectionEvent;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\MessageTemplate;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Support\BranchScope;
use App\Support\DailySeries;
use App\Support\Messaging\MessageComposer;
use App\Support\Messaging\SmsGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The disconnection lists of each branch: the lists themselves, one list's
 * subscriptions with their dates, warnings and disconnections, and the page
 * where subscriptions are picked to be put on a list. Nothing here
 * disconnects anyone or sends anything by itself.
 */
class DisconnectionListController extends Controller
{
    use FiltersDataTable, FiltersDisconnectionItems, PresentsDisconnectionItems;

    /** The warning offered when none of the saved wordings is picked. */
    public const DEFAULT_WARNING = 'عزيزنا المشترك {الاسم}، رقم الاشتراك {رقم_الاشتراك}: عليك رصيد مستحق قدره {الرصيد} شيكل. سيتم قطع الخدمة بتاريخ {موعد_القطع} ما لم يتم السداد. {الفرع}';

    /**
     * Every list the user may see, newest first, with where each stands, and
     * the totals across them.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', DisconnectionList::class);

        $actor = $request->user();
        $today = DailySeries::today()->toDateString();
        $scheduled = fn (Builder $items) => $items->where('status', DisconnectionItemStatus::Scheduled);

        $query = DisconnectionList::query()
            ->visibleTo($actor)
            ->with(['branch', 'createdBy', 'closedBy'])
            ->withCount([
                'items',
                'items as scheduled_count' => $scheduled,
                'items as overdue_count' => fn (Builder $items) => $scheduled($items)->whereDate('scheduled_for', '<', $today),
                'items as disconnected_count' => fn (Builder $items) => $items->where('status', DisconnectionItemStatus::Disconnected),
                'items as removed_count' => fn (Builder $items) => $items->where('status', DisconnectionItemStatus::Removed),
            ])
            ->withSum('items as fees_total', 'fee_amount')
            ->withMin(['items as next_date' => $scheduled], 'scheduled_for');
        $this->applyDataTableFilters($query, $request, ['title', 'notes'], ['created_at', 'title'], 'created_at', 'desc');
        $this->applyDataTableFilterSelects($query, $request, ['branch_id']);

        $state = ((array) $request->input('filter', []))['state'] ?? null;
        $query->when($state === 'open', fn (Builder $lists) => $lists->whereNull('closed_at'))
            ->when($state === 'closed', fn (Builder $lists) => $lists->whereNotNull('closed_at'));

        $lists = $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (DisconnectionList $list) => [
                'id' => $list->id,
                'title' => $list->title,
                'notes' => $list->notes,
                'branchName' => $list->branch->name,
                'createdBy' => $list->createdBy?->name,
                'createdAt' => $list->created_at->toIso8601String(),
                'isClosed' => $list->isClosed(),
                'closedAt' => $list->closed_at?->toIso8601String(),
                'itemsCount' => (int) $list->items_count,
                'scheduledCount' => (int) $list->scheduled_count,
                'overdueCount' => (int) $list->overdue_count,
                'disconnectedCount' => (int) $list->disconnected_count,
                'removedCount' => (int) $list->removed_count,
                'feesTotal' => number_format((float) $list->fees_total, 2, '.', ''),
                'nextDate' => $list->next_date ? substr((string) $list->next_date, 0, 10) : null,
            ]);

        $filterOptions = [
            $this->filterGroup('state', 'الحالة', [['value' => 'open', 'label' => 'مفتوحة'], ['value' => 'closed', 'label' => 'مغلقة']]),
        ];

        if ($actor->isSuperAdmin()) {
            $filterOptions[] = $this->branchFilterGroup();
        }

        // The Super Admin narrows the page to one branch in the banner: the figures above the lists follow it.
        $branchId = $actor->isSuperAdmin() && is_numeric($request->input('filter.branch_id')) ? (int) $request->input('filter.branch_id') : null;

        return Inertia::render('Disconnections/Index', [
            'lists' => $lists,
            'summary' => $this->summary($actor, $branchId),
            'branchScope' => fn () => BranchScope::shownTo($actor) ? BranchScope::forFilter($actor, $branchId) : null,
            'canCreate' => $actor->can('create', DisconnectionList::class),
            'branchOptions' => $actor->isSuperAdmin() ? $this->modelOptions(Branch::orderBy('name')->get()) : [],
            'filters' => $this->dataTableState($request, 'created_at', 'desc'),
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Open a new list for the user's branch (the chosen one for the Super Admin).
     */
    public function store(StoreDisconnectionListRequest $request): RedirectResponse
    {
        $actor = $request->user();

        $list = DisconnectionList::create([
            'branch_id' => $actor->isSuperAdmin() ? $request->validated('branch_id') : $actor->workBranchId(),
            'title' => $request->validated('title'),
            'notes' => $request->validated('notes'),
            'created_by' => $actor->id,
        ]);

        $actor->notify(new ActionCompleted('disconnection-list-created', $list->title));

        return redirect()->route('disconnection-lists.show', $list)->with('status', 'disconnection-list-created');
    }

    /**
     * One list: its subscriptions with the search and filters applied, what
     * the user may do with them, and one item's history on request
     * (`?history=` with `only: ['history']`).
     */
    public function show(Request $request, DisconnectionList $disconnectionList): InertiaResponse
    {
        $this->authorize('view', $disconnectionList);

        $actor = $request->user();
        $list = $disconnectionList->load(['branch', 'createdBy', 'closedBy']);
        $canWarn = $actor->can('warn', $list);

        $items = $this->filteredDisconnectionItems($list, $request)
            ->with($this->disconnectionItemRelations())
            ->paginate($this->dataTablePerPage($request, 25))
            ->withQueryString()
            ->through(fn (DisconnectionListItem $item) => $this->disconnectionItemRow($item, $canWarn));

        return Inertia::render('Disconnections/Show', [
            'list' => [
                'id' => $list->id,
                'title' => $list->title,
                'notes' => $list->notes,
                'branchName' => $list->branch->name,
                'createdBy' => $list->createdBy?->name,
                'createdAt' => $list->created_at->toIso8601String(),
                'isClosed' => $list->isClosed(),
                'closedAt' => $list->closed_at?->toIso8601String(),
                'closedBy' => $list->closedBy?->name,
            ],
            'items' => $items,
            'summary' => $this->listSummary($list),
            'abilities' => [
                'manage' => $actor->can('manage', $list) && ! $list->isClosed(),
                'manageList' => $actor->can('manage', $list),
                'warn' => $canWarn && ! $list->isClosed(),
                'execute' => $actor->can('execute', $list) && ! $list->isClosed(),
                'changeFee' => $actor->hasPermission(PermissionKey::ChangeDisconnectionFee),
            ],
            'reasonOptions' => DisconnectionReason::options(),
            'channelOptions' => MessageChannel::options(),
            'smsDeliversMessages' => app(SmsGateway::class)->deliversMessages(),
            'warningTemplates' => MessageTemplate::query()->where('kind', MessageKind::DisconnectionWarning)->orderBy('name')->get(['id', 'name', 'body']),
            'defaultWarning' => self::DEFAULT_WARNING,
            'placeholders' => MessageComposer::placeholdersFor(MessageKind::DisconnectionWarning),
            'today' => DailySeries::today()->toDateString(),
            'usualFee' => DisconnectionListItem::usualFee(),
            'filters' => $this->dataTableState($request, 'scheduled_for', 'asc', 25),
            'filterOptions' => $this->itemFilterOptions($actor, $request),
            'history' => Inertia::optional(fn () => $this->history($list, $request->integer('history'))),
        ]);
    }

    /**
     * Rename the list, change its notes, or close or reopen it.
     */
    public function update(UpdateDisconnectionListRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->safe()->only(['title', 'notes']);

        if ($request->has('closed')) {
            $closing = $request->boolean('closed');
            $data += $closing
                ? ($disconnectionList->isClosed() ? [] : ['closed_at' => now(), 'closed_by' => $actor->id])
                : ['closed_at' => null, 'closed_by' => null];
        }

        $disconnectionList->update($data);
        $status = match (true) {
            $request->has('closed') && $request->boolean('closed') => 'disconnection-list-closed',
            $request->has('closed') => 'disconnection-list-reopened',
            default => 'disconnection-list-updated',
        };
        $actor->notify(new ActionCompleted($status, $disconnectionList->title));

        return back()->with('status', $status);
    }

    /**
     * The branch's subscriptions to pick from for the list: active ones, with
     * what they owe and whether they already wait on a list. What they owe is
     * shown and can be filtered on, but never decides who may be added.
     */
    public function candidates(Request $request, DisconnectionList $disconnectionList): InertiaResponse
    {
        $this->authorize('manage', $disconnectionList);

        $actor = $request->user();
        $list = $disconnectionList->load('branch');

        $subscriptions = $this->candidateQuery($list, $request)
            ->with(['meterBox.subArea', 'disconnectionItems' => fn ($items) => $items->where('status', DisconnectionItemStatus::Scheduled)->with('list:id,title')])
            ->paginate($this->dataTablePerPage($request, 25))
            ->withQueryString()
            ->through(function (Subscription $subscription) use ($list): array {
                $waiting = $subscription->disconnectionItems->first();

                return [
                    'id' => $subscription->id,
                    'name' => $subscription->displayName(),
                    'accountNumber' => $subscription->account_number,
                    'meterBoxNumber' => $subscription->meterBox?->box_number,
                    'meterBoxName' => $subscription->meterBox?->displayName(),
                    'subAreaName' => $subscription->meterBox?->subArea?->name,
                    'balance' => number_format((float) $subscription->balance, 2, '.', ''),
                    'waitingOn' => $waiting ? ['id' => $waiting->list->id, 'title' => $waiting->list->title, 'isThisList' => $waiting->list->id === $list->id] : null,
                ];
            });

        return Inertia::render('Disconnections/Candidates', [
            'list' => ['id' => $list->id, 'title' => $list->title, 'branchName' => $list->branch->name, 'isClosed' => $list->isClosed()],
            'subscriptions' => $subscriptions,
            'reasonOptions' => DisconnectionReason::options(),
            'today' => DailySeries::today()->toDateString(),
            'filters' => $this->dataTableState($request, 'display_name', 'asc', 25),
            'filterOptions' => [
                $this->filterGroup('listed', 'قوائم القطع', [
                    ['value' => 'none', 'label' => 'غير مدرج في قائمة'],
                    ['value' => 'listed', 'label' => 'مدرج في قائمة'],
                ]),
                $this->filterGroup('balance', 'الرصيد', [
                    ['value' => 'owing', 'label' => 'عليه رصيد مستحق'],
                    ['value' => 'clear', 'label' => 'لا رصيد عليه'],
                ]),
                $this->subAreaFilterGroup(SubArea::query()->visibleTo($actor)->orderBy('name')->get()),
                ...$this->meterBoxFilterGroupsFor($actor, $request),
            ],
        ]);
    }

    /**
     * The list's branch's active subscriptions matching the candidates page's
     * search and filters; shared with adding every match to the list.
     */
    public static function candidateQuery(DisconnectionList $list, Request $request): Builder
    {
        $filter = (array) $request->input('filter', []);
        $balance = '(select coalesce(sum(amount), 0) from subscription_transactions where subscription_transactions.subscription_id = subscriptions.id)';
        $search = trim((string) $request->input('search'));
        $minimumBalance = $filter['min_balance'] ?? null;

        $query = Subscription::query()
            ->select('subscriptions.*')
            ->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) as display_name")
            ->selectRaw("{$balance} as balance")
            ->where('branch_id', $list->branch_id)
            ->where('status', SubscriptionStatus::Active)
            ->when($search !== '', fn (Builder $subscriptions) => $subscriptions->matchingSearch($search))
            ->when(($filter['balance'] ?? null) === 'owing', fn (Builder $subscriptions) => $subscriptions->whereRaw("{$balance} > 0"))
            ->when(($filter['balance'] ?? null) === 'clear', fn (Builder $subscriptions) => $subscriptions->whereRaw("{$balance} <= 0"))
            ->when(is_numeric($minimumBalance), fn (Builder $subscriptions) => $subscriptions->whereRaw("{$balance} >= ?", [(float) $minimumBalance]))
            ->when(($filter['listed'] ?? null) === 'none', fn (Builder $subscriptions) => $subscriptions->whereDoesntHave('disconnectionItems', fn (Builder $items) => $items->where('status', DisconnectionItemStatus::Scheduled)))
            ->when(($filter['listed'] ?? null) === 'listed', fn (Builder $subscriptions) => $subscriptions->whereHas('disconnectionItems', fn (Builder $items) => $items->where('status', DisconnectionItemStatus::Scheduled)));

        if (is_string($filter['sub_area_id'] ?? null) && ctype_digit($filter['sub_area_id'])) {
            $query->whereHas('meterBox', fn (Builder $box) => $box->where('sub_area_id', (int) $filter['sub_area_id']));
        }

        if (is_string($filter['meter_box_name'] ?? null) && $filter['meter_box_name'] !== '') {
            $query->whereHas('meterBox', fn (Builder $box) => $box->where('name', $filter['meter_box_name']));
        }

        if (is_string($filter['meter_box_id'] ?? null) && ctype_digit($filter['meter_box_id'])) {
            $query->where('meter_box_id', (int) $filter['meter_box_id']);
        }

        $sort = (string) $request->string('sort');
        $direction = $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc';

        return match ($sort) {
            'balance' => $query->orderBy('balance', $direction)->orderBy('subscriptions.id'),
            'account_number' => $query->orderBy('account_number', $direction),
            default => $query->orderBy('display_name', $direction)->orderBy('subscriptions.id'),
        };
    }

    /**
     * Where the user's lists stand together: active lists, waiting and
     * overdue subscriptions, the week ahead, warnings, disconnections and the
     * fees they charged this month — of one branch when `$branchId` is given.
     *
     * @return array<string, int|string>
     */
    private function summary(User $actor, ?int $branchId = null): array
    {
        $today = DailySeries::today();
        $monthStart = $today->startOfMonth()->setTimezone('UTC');
        $items = fn (): Builder => DisconnectionListItem::query()->visibleTo($actor)->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId));
        $scheduled = fn (): Builder => $items()->where('status', DisconnectionItemStatus::Scheduled);

        return [
            'openLists' => DisconnectionList::query()->visibleTo($actor)->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))->whereNull('closed_at')->count(),
            'scheduled' => $scheduled()->count(),
            'overdue' => $scheduled()->whereDate('scheduled_for', '<', $today->toDateString())->count(),
            'upcoming' => $scheduled()->whereDate('scheduled_for', '>=', $today->toDateString())->whereDate('scheduled_for', '<=', $today->addDays(7)->toDateString())->count(),
            'warningsThisMonth' => DisconnectionEvent::query()
                ->where('type', DisconnectionEventType::Warned)
                ->where('created_at', '>=', $monthStart)
                ->whereHas('item', fn (Builder $item) => $item->visibleTo($actor)->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId)))
                ->count(),
            'disconnectedThisMonth' => $items()->where('status', DisconnectionItemStatus::Disconnected)->where('disconnected_at', '>=', $monthStart)->count(),
            'feesThisMonth' => number_format((float) $items()->where('status', DisconnectionItemStatus::Disconnected)->where('disconnected_at', '>=', $monthStart)->sum('fee_amount'), 2, '.', ''),
        ];
    }

    /**
     * Where one list's subscriptions stand, with the count of each reason.
     *
     * @return array<string, mixed>
     */
    private function listSummary(DisconnectionList $list): array
    {
        $today = DailySeries::today()->toDateString();
        $counts = $list->items()->toBase()
            ->selectRaw('status, count(*) as total, coalesce(sum(fee_amount), 0) as fees, coalesce(sum(warnings_count), 0) as warnings, coalesce(sum(extensions_count), 0) as extensions')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        $count = fn (DisconnectionItemStatus $status): int => (int) ($counts[$status->value]->total ?? 0);

        return [
            'total' => (int) $counts->sum('total'),
            'scheduled' => $count(DisconnectionItemStatus::Scheduled),
            'overdue' => $list->items()->overdue()->count(),
            'dueToday' => $list->items()->where('status', DisconnectionItemStatus::Scheduled)->whereDate('scheduled_for', $today)->count(),
            'disconnected' => $count(DisconnectionItemStatus::Disconnected),
            'removed' => $count(DisconnectionItemStatus::Removed),
            'warnings' => (int) $counts->sum('warnings'),
            'extensions' => (int) $counts->sum('extensions'),
            'feesTotal' => number_format((float) $counts->sum('fees'), 2, '.', ''),
            'reasons' => collect(DisconnectionReason::cases())->map(fn (DisconnectionReason $reason) => [
                'value' => $reason->value,
                'label' => __($reason->label()),
                'count' => $list->items()->where('reason', $reason)->count(),
            ])->all(),
        ];
    }

    /**
     * The list page's Filter menu.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemFilterOptions(User $actor, Request $request): array
    {
        return [
            $this->filterGroup('status', 'الحالة', DisconnectionItemStatus::options()),
            $this->filterGroup('timing', 'الموعد', [
                ['value' => 'overdue', 'label' => 'متأخر — بانتظار إجراء'],
                ['value' => 'today', 'label' => 'موعده اليوم'],
                ['value' => 'upcoming', 'label' => 'موعده لاحقًا'],
            ]),
            $this->filterGroup('reason', 'سبب القطع', DisconnectionReason::options()),
            $this->filterGroup('balance', 'الرصيد', [
                ['value' => 'owing', 'label' => 'عليه رصيد مستحق'],
                ['value' => 'paid', 'label' => 'سدّد كامل رصيده'],
                ['value' => 'reduced', 'label' => 'قلّ رصيده منذ الإدراج'],
            ]),
            $this->filterGroup('warned', 'الإنذارات', [
                ['value' => '1', 'label' => 'أُرسل له إنذار'],
                ['value' => '0', 'label' => 'لم يُنذر بعد'],
            ]),
            $this->subAreaFilterGroup(SubArea::query()->visibleTo($actor)->orderBy('name')->get()),
            ...$this->meterBoxFilterGroupsFor($actor, $request, $actor->isSuperAdmin() ? fn (MeterBox $box): string => $box->branch->name : null),
        ];
    }

    /**
     * One item's history, oldest first.
     *
     * @return array<string, mixed>|null
     */
    private function history(DisconnectionList $list, int $itemId): ?array
    {
        $item = $list->items()->with(['subscription', 'events.user', 'events.message'])->find($itemId);

        if ($item === null) {
            return null;
        }

        $timezone = config('app.business_timezone');

        return [
            'id' => $item->id,
            'name' => $item->subscription->displayName(),
            'accountNumber' => $item->subscription->account_number,
            'events' => $item->events->sortBy('id')->values()->map(fn (DisconnectionEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'typeLabel' => __($event->type->label()),
                'old' => $event->old_values,
                'new' => $event->new_values,
                'reason' => $event->reason,
                'userName' => $event->user?->name,
                'at' => $event->created_at->timezone($timezone)->format('Y-m-d H:i'),
                'message' => $event->message ? [
                    'body' => $event->message->body,
                    'phone' => $event->message->phone,
                    'status' => $event->message->status->value,
                    'statusLabel' => __($event->message->status->label()),
                    'error' => $event->message->error,
                ] : null,
            ])->all(),
        ];
    }
}
