<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CashBoxType;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\PresentsCashBoxes;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\AuditTransferSearch;
use App\Support\BankTransferAuditing;
use App\Support\BranchBoxes;
use App\Support\BranchClosingAccess;
use App\Support\BranchClosingWorkflow;
use App\Support\BranchScope;
use App\Support\ClosingPeriods;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The financial boxes pages: the branch's boxes and open entries for those
 * who close them, a collector's own boxes, one box with its entries (or as a
 * spreadsheet), and the collector's answer to an audit note.
 */
class CashBoxController extends Controller
{
    use FiltersDataTable, PresentsCashBoxes;

    public function __construct(private BranchClosingWorkflow $workflow, private BranchBoxes $branchBoxes) {}

    /**
     * The financial boxes: for those who close the branch's boxes, every box
     * of the branch in one table, their own among them; for a collector,
     * every box they had, filtered by `?type=` and `?state=`, each with its
     * totals, dates, who closed it and its report.
     */
    public function cashBoxes(Request $request): InertiaResponse
    {
        $actor = $request->user();
        if (BranchClosingAccess::view($actor)) {
            return $this->branchCashBoxes($request);
        }
        $this->authorize('viewOwnCashBoxes', Subscription::class);

        $started = $this->boxesStarted($actor);
        if ($started) {
            $this->workflow->refresh($actor->workBranchId());
        }
        $type = in_array($request->query('type'), array_column(CashBoxType::cases(), 'value'), true) ? $request->query('type') : '';
        $state = in_array($request->query('state'), ['open', 'closed'], true) ? $request->query('state') : '';
        $perPage = $this->dataTablePerPage($request);
        $boxes = $this->ownBoxQuery($actor)
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->when($state === 'open', fn (Builder $query) => $query->whereIn('status', ['open', 'pending']))
            ->when($state === 'closed', fn (Builder $query) => $query->where('status', 'received'))
            ->paginate($perPage)->withQueryString()->through(fn (CashBox $box): array => $this->cashBoxRow($box));

        return Inertia::render('Payments/CashBoxes', [
            'started' => $started,
            'branchName' => $actor->workBranch()?->name,
            'boxes' => $boxes,
            'filters' => ['type' => $type, 'state' => $state, 'per_page' => $perPage],
            ...$this->collectionCounts($actor),
            'collectionUrls' => $this->collectionUrls(),
        ]);
    }

    /**
     * The open entries tab of the financial boxes page, for those who close
     * the branch's boxes. A collector's open entries are on their summary,
     * where they are sent with their search and filters.
     */
    public function entries(Request $request): InertiaResponse|RedirectResponse
    {
        if (BranchClosingAccess::view($request->user())) {
            return $this->branchEntries($request);
        }

        return redirect()->route('payments.summary', $request->only(['search', 'filter', 'per_page']));
    }

    /**
     * The entries page that was: its tab on the financial boxes page for
     * those who close the branch's boxes, else the collector's summary,
     * with the search and filters asked for.
     */
    public function movedEntries(Request $request): RedirectResponse
    {
        $query = $request->only(['branch_id', 'search', 'filter', 'per_page']);

        return BranchClosingAccess::view($request->user())
            ? redirect()->route('payments.cash-boxes.entries', $query)
            : redirect()->route('payments.summary', array_diff_key($query, ['branch_id' => true]));
    }

    /**
     * Box tabs include every closed box regardless of historical sending.
     * Auditors see the closed tab; branch staff also see open boxes and entries.
     *
     * @param  EloquentCollection<int, Branch>  $branches
     * @return list<array{key: string, label: string, count: int, url: string}>
     */
    private function boxTabs(EloquentCollection $branches, ?Branch $branch, User $actor): array
    {
        $query = $branches->count() > 1 && $branch ? ['branch_id' => $branch->id] : [];
        $branchIds = $branch ? [$branch->id] : $branches->modelKeys();
        $counts = $this->branchBoxes->counts($branchIds);
        $openBranchIds = $branches->whereIn('id', $branchIds)->reject(fn (Branch $item): bool => BranchClosingAccess::closedOnly($actor, $item->id))->modelKeys();

        $closedTab = ['key' => 'closed', 'label' => 'الصناديق المغلقة', 'count' => $counts['closed'], 'url' => route('payments.cash-boxes', [...$query, 'place' => 'closed'])];
        if ($openBranchIds === []) {
            return [$closedTab];
        }
        $openCount = $openBranchIds === $branchIds ? $counts['open'] : $this->branchBoxes->counts($openBranchIds)['open'];

        return [
            ['key' => 'entries', 'label' => 'الحركات', 'count' => CashBoxPayment::query()->visibleInBox()->whereHas('box', fn (Builder $box) => $box->whereIn('branch_id', $openBranchIds)->whereIn('status', ['open', 'pending']))->count(), 'url' => route('payments.cash-boxes.entries', $query)],
            ['key' => 'open', 'label' => 'الصناديق المفتوحة', 'count' => $openCount, 'url' => route('payments.cash-boxes', [...$query, 'place' => 'open'])],
            $closedTab,
        ];
    }

    /**
     * Every entry of the branch's open boxes, for those who close them,
     * theirs among them: whose box each sits in, narrowed to one person's
     * boxes by `?filter[user]=` and to one type of box by
     * `?filter[type]=`, searched by subscriber, voucher, transfer reference
     * or box number, with a link that opens its box on the financial boxes
     * page, and the open boxes of each type the actor may close at once.
     */
    private function branchEntries(Request $request): InertiaResponse
    {
        $actor = $request->user();
        [$branches, $branch] = $this->branchBoxes->branch($request, $actor);
        $all = $branches->count() > 1 && (! $request->filled('branch_id') || $request->input('branch_id') === 'all');
        $chosen = $all ? $branches : new EloquentCollection([$branch]);
        $visible = $chosen->reject(fn (Branch $item): bool => BranchClosingAccess::closedOnly($actor, $item->id));
        abort_if($visible->isEmpty(), 403);
        $branchIds = $visible->modelKeys();
        $branch = $all ? null : $branch;
        $people = $this->branchBoxes->people($branchIds);
        $person = $people->firstWhere('id', $request->integer('filter.user'));
        $query = CashBoxPayment::query()->whereHas('box', fn (Builder $box) => $box->whereIn('branch_id', $branchIds)->whereIn('status', ['open', 'pending'])->when($person, fn (Builder $box) => $box->where('user_id', $person->id)));
        $list = $this->entryList($request, $query, fn (CashBox $box): string => route('payments.cash-boxes', ['branch_id' => $box->branch_id, 'box' => $box->id]));
        $list['filters']['filter']['user'] = $person ? (string) $person->id : '';
        $started = $visible->contains(fn (Branch $item): bool => ClosingSetting::forBranch($item->id)->workflow_started_at !== null);

        return Inertia::render('Payments/Entries', [
            'started' => $started,
            'branch' => $branch, 'branches' => $branches, 'people' => $people,
            // The general management reads, large above the boxes, which branch they are.
            'branchScope' => $branches->count() > 1 ? BranchScope::describe($visible) : null,
            'tabs' => $this->boxTabs($branches, $branch, $actor),
            ...$list,
            'closableBoxes' => $branch && $started && BranchClosingAccess::receive($actor, $branch->id) ? $this->closableBoxes($branch->id, $actor, $person?->id) : null,
            ...($actor->can('viewOwnCashBoxes', Subscription::class) ? $this->collectionCounts($actor) : ['pendingCount' => 0]),
            'collectionUrls' => $this->collectionUrls(),
            'urls' => ['index' => route('payments.cash-boxes.entries'), 'actions' => url('/payments/cash-boxes/actions')],
        ]);
    }

    /**
     * The open boxes of the branch the actor may confirm closed, one
     * person's when `$userId` is given, each with whose it is, how many
     * entries are still in effect, their totals, and those entries as the
     * box's summary groups them (by bank, service or currency paid), to
     * close them by type.
     *
     * @return list<array{id: int, number: string, type: string, person: string|null, count: int, totals: array<string, string>, entries: list<array<string, mixed>>}>
     */
    private function closableBoxes(int $branchId, User $actor, ?int $userId): array
    {
        return $this->workflow->closableBoxes($branchId, $actor, userId: $userId)->load(['user:id,name', 'payments.transaction:id,status,amount,currency,currency_amount,bank_name,notes'])
            ->map(function (CashBox $box): array {
                $active = $box->payments->filter(fn (CashBoxPayment $entry): bool => $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE);

                return [
                    'id' => $box->id, 'number' => $box->number, 'type' => $box->type->value, 'person' => $box->user?->name,
                    'count' => $active->count(),
                    'totals' => $box->totals(),
                    'entries' => $active->map(fn (CashBoxPayment $entry): array => [
                        'amount' => $entry->amount, 'bankName' => $entry->transaction->bank_name, 'service' => $entry->transaction->notes,
                        'currency' => $entry->transaction->currency->value, 'currencyLabel' => __($entry->transaction->currency->label()),
                        'paidAmount' => $entry->transaction->currency_amount ?? ltrim($entry->transaction->amount, '-'),
                    ])->values()->all(),
                ];
            })->all();
    }

    /**
     * The latest closing period of each visible branch, or their open boxes.
     *
     * @param  EloquentCollection<int, Branch>  $branches
     */
    private function allBranchCashBoxes(Request $request, User $actor, EloquentCollection $branches): InertiaResponse
    {
        $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $place = $request->query('place') === 'open' ? 'open' : 'closed';
        $visible = $place === 'open' ? $branches->reject(fn (Branch $item): bool => BranchClosingAccess::closedOnly($actor, $item->id)) : $branches;
        abort_if($visible->isEmpty(), 403);
        $boxes = collect();
        $started = false;
        foreach ($visible as $branch) {
            $branchStarted = ClosingSetting::forBranch($branch->id)->workflow_started_at !== null;
            if ($branchStarted) {
                $this->workflow->refresh($branch->id);
            }
            $started = $started || $branchStarted;
            $week = $request->filled('week')
                ? $this->branchBoxes->weekOf(ClosingPeriods::date($request->query('week')), $branch->id)
                : ($this->branchBoxes->periods($branch->id)->first()['start'] ?? $this->branchBoxes->weekOf(now(), $branch->id));
            $boxes->push(...$this->branchBoxes->rows($branch->id, $actor, $place, week: $week)
                ->map(fn (array $box): array => [...$box, 'branchName' => $branch->name]));
        }

        return Inertia::render('Payments/BranchCashBoxes', [
            'branch' => null, 'branches' => $branches,
            'branchScope' => BranchScope::describe($visible),
            'started' => $started || $boxes->isNotEmpty(), 'place' => $place,
            'tabs' => $this->boxTabs($branches, null, $actor),
            'boxes' => $boxes, 'periods' => [], 'week' => null, 'weekStartsOn' => null,
            'periodAudit' => null, 'canApprovePeriod' => false,
            'canSearchTransfers' => false, 'searchingTransfers' => false, 'transferSearch' => null,
            'access' => [],
            ...($actor->can('viewOwnCashBoxes', Subscription::class) ? $this->collectionCounts($actor) : ['pendingCount' => 0]),
            'collectionUrls' => $this->collectionUrls(),
            'urls' => ['index' => route('payments.cash-boxes'), 'actions' => url('/payments/cash-boxes/actions'), 'auditPeriod' => route('payments.period-audits.store')],
        ]);
    }

    /**
     * Open boxes for branch staff, or closed boxes in a chosen business week.
     * Links to a closed box select its closing week automatically.
     */
    private function branchCashBoxes(Request $request): InertiaResponse
    {
        $actor = $request->user();
        [$branches, $branch] = $this->branchBoxes->branch($request, $actor);
        if ($branches->count() > 1 && (! $request->filled('branch_id') || $request->input('branch_id') === 'all')) {
            return $this->allBranchCashBoxes($request, $actor, $branches);
        }
        $started = ClosingSetting::forBranch($branch->id)->workflow_started_at !== null;
        if ($started) {
            $this->workflow->refresh($branch->id);
        }
        $place = in_array($request->query('place'), BranchBoxes::PLACES, true) ? $request->query('place') : 'closed';
        if (BranchClosingAccess::closedOnly($actor, $branch->id)) {
            $place = 'closed';
        }
        $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
            'transfer_search' => ['nullable', 'string', 'max:100'],
            'transfer_state' => ['nullable', 'in:differences,unreviewed,draft,missing,short,over,matched'],
        ]);
        $canSearchTransfers = $actor->is_active && ($actor->isSuperAdmin() || $actor->hasFinancialAuditAccess());
        $searchingTransfers = $request->query('view') === 'transfers';
        abort_if($searchingTransfers && ! $canSearchTransfers, 403);
        if ($searchingTransfers) {
            $place = 'closed';
        }
        $periods = $this->branchBoxes->periods($branch->id);
        $week = $request->query('week')
            ? $this->branchBoxes->weekOf(ClosingPeriods::date($request->query('week')), $branch->id)
            : ($periods->first()['start'] ?? $this->branchBoxes->weekOf(now(), $branch->id));
        $linkedBox = $request->integer('box') ? CashBox::query()->where('branch_id', $branch->id)->where('status', 'received')->find($request->integer('box')) : null;
        if ($linkedBox) {
            $week = $this->branchBoxes->weekOf($linkedBox->closingWeekMoment(), $branch->id);
        }
        if (! $periods->contains('start', $week)) {
            $periods->push(['start' => $week, 'end' => ClosingPeriods::date($week)->addDays(6)->toDateString(), 'count' => 0]);
            $periods = $periods->sortByDesc('start')->values();
        }

        $boxes = $searchingTransfers ? collect() : $this->branchBoxes->rows($branch->id, $actor, $place, $request->integer('box') ?: null, $week);
        $canApprovePeriod = $place === 'closed' && BankTransferAuditing::allowed($actor, AuditAction::ApprovePeriods) && BankTransferAuditing::allowed($actor, AuditAction::ApproveBoxes);
        $periodPreview = fn (): array => $this->branchBoxes->periodAudit($searchingTransfers ? $this->branchBoxes->rows($branch->id, $actor, 'closed', week: $week) : $boxes, $branch->id, $week);

        return Inertia::render('Payments/BranchCashBoxes', [
            // The general management reads, large above the boxes, which branch they are.
            'branchScope' => $branches->count() > 1 && BranchScope::shownTo($actor) ? BranchScope::describe(collect([$branch])) : null,
            'branch' => $branch, 'branches' => $branches, 'started' => $started || $periods->contains(fn (array $period): bool => $period['count'] > 0),
            'place' => $place,
            'tabs' => $this->boxTabs($branches, $branch, $actor),
            'boxes' => $boxes,
            'periodAudit' => $canApprovePeriod ? ($searchingTransfers ? Inertia::optional($periodPreview) : $periodPreview()) : null,
            'canApprovePeriod' => $canApprovePeriod,
            'canSearchTransfers' => $canSearchTransfers,
            'searchingTransfers' => $searchingTransfers,
            'transferSearch' => $searchingTransfers ? app(AuditTransferSearch::class)->results($request, $actor, $branch->id, $week) : null,
            'periods' => $periods, 'week' => $week, 'weekStartsOn' => ClosingSetting::forBranch($branch->id)->week_starts_on,
            'access' => $this->branchBoxes->access($actor, $branch->id),
            ...($actor->can('viewOwnCashBoxes', Subscription::class) ? $this->collectionCounts($actor) : ['pendingCount' => 0]),
            'collectionUrls' => $this->collectionUrls(),
            'urls' => ['index' => route('payments.cash-boxes'), 'actions' => url('/payments/cash-boxes/actions'), 'auditPeriod' => route('payments.period-audits.store')],
        ]);
    }

    /**
     * One of the actor's boxes with its entries; `?format=csv` downloads the
     * box's details and every entry as a spreadsheet, and `?print_all=1`
     * lists every entry for the printout.
     */
    public function showCashBox(Request $request, CashBox $cashBox): InertiaResponse|StreamedResponse
    {
        $this->authorize('viewOwnCashBoxes', Subscription::class);
        $actor = $request->user();
        abort_unless($cashBox->user_id === $actor->id && $cashBox->branch_id === $actor->workBranchId(), 404);
        $this->workflow->refresh($cashBox->branch_id);
        $cashBox->refresh()->loadCount(['payments' => fn ($entries) => $entries->visibleInBox()]);
        $entryQuery = $cashBox->payments()->visibleInBox()->with(['transaction.subscription:id,full_name,subscription_name,account_number', 'transaction.recordedBy:id,name'])
            ->orderByDesc('occurred_at')->orderByDesc('id');

        if ($request->query('format') === 'csv') {
            return $this->exportCashBox($cashBox, $entryQuery->get());
        }

        $entries = $entryQuery->paginate($this->dataTablePerPage($request, 25))->withQueryString()->through(fn (CashBoxPayment $entry): array => $this->cashBoxEntry($entry, $actor->can('viewAny', Subscription::class)));

        return Inertia::render('Payments/CashBoxShow', [
            'box' => $this->cashBoxRow($cashBox), 'entries' => $entries,
            ...$this->collectionCounts($actor),
            'collectionUrls' => $this->collectionUrls(),
        ]);
    }

    /**
     * The box as a spreadsheet: its details on top, then every entry.
     *
     * @param  Collection<int, CashBoxPayment>  $entries
     */
    private function exportCashBox(CashBox $box, Collection $entries): StreamedResponse
    {
        $row = $this->cashBoxRow($box);
        $local = fn (?CarbonInterface $time): string => $time ? $time->copy()->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') : '';
        $statuses = ['missing' => 'غير موجودة', 'rejected' => 'مرفوضة'];
        $types = ['cash' => 'نقدي', 'bank_transfer' => 'حوالات بنكية', 'clearing' => 'مقاصات'];
        $stages = ['open' => 'مفتوح', 'awaiting_confirmation' => 'مُغلق', 'completed' => 'مُغلق'];
        $safe = fn (array $cells): array => array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+@\-\t\r]/u', $cell) ? "'".$cell : $cell, $cells);

        return response()->streamDownload(function () use ($box, $row, $entries, $local, $statuses, $types, $stages, $safe): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            foreach ([
                ['رقم الصندوق', $box->number],
                ['النوع', $types[$box->type->value]],
                ['المحصّل', $box->user?->name ?? ''],
                ['الحالة', $stages[$row['stage']]],
                ['تاريخ الفتح', $local($box->opened_at)],
                ['تاريخ الإغلاق', $local($box->closed_at)],
                ['أغلقه', $row['closedBy'] ?? ''],
                ['الإجمالي ₪', (float) $row['totals']['ILS']],
                ['المبلغ المستلم ₪', (float) $row['receivedAmounts']['ILS']],
                [],
            ] as $cells) {
                fputcsv($file, $safe($cells));
            }
            fputcsv($file, ['#', 'التاريخ والوقت', 'المشترك', 'رقم الحساب', 'رقم السند', 'البنك المستلم', 'البنك المرسل', 'رقم الحوالة', 'الخدمة', 'العملة', 'المبلغ', 'الحالة', 'تاريخ وصول الحوالة إلى البنك']);
            foreach ($entries->values() as $index => $entry) {
                $cancelled = $entry->transaction->status !== SubscriptionTransaction::STATUS_ACTIVE;
                fputcsv($file, $safe([
                    $index + 1, $local($entry->occurred_at), $entry->transaction->subscription->displayName(), $entry->transaction->subscription->account_number,
                    $entry->transaction->manual_voucher_number ?? $entry->transaction->voucher_number ?? '', $entry->transaction->bank_name ?? '', $entry->transaction->sender_bank_name ?? '',
                    $entry->transaction->reference_number ?? '', $entry->method === 'clearing' ? ($entry->transaction->notes ?? '') : '', $entry->currency, (float) $entry->amount,
                    $cancelled ? 'ملغاة' : ($statuses[$entry->status] ?? 'سارية'),
                    $entry->transaction->bank_received_on?->toDateString() ?? '',
                ]));
            }
            fclose($file);
        }, 'cash-box-'.$box->number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The collector's answer to an audit note the branch sent them.
     */
    public function replyToAuditNote(Request $request, int $auditNote): RedirectResponse
    {
        $this->authorize('viewOwnCashBoxes', Subscription::class);
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $actor = $request->user();
        abort_unless($actor->workBranchId(), 403);

        $this->workflow->locked($actor->workBranchId(), function () use ($actor, $auditNote, $data): void {
            $this->workflow->collectorReply($this->waitingAuditNotes($actor)->findOrFail($auditNote), $actor, $data['body']);
        });

        return back()->with('status', 'audit-note-replied');
    }
}
