<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\FiltersSubscriptionList;
use App\Http\Requests\ApplySubscriptionBulkChangeRequest;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionBulkChangeItem;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Changing many subscriptions at once from the subscriptions list — their
 * minimum charge or their status — for the ticked ones or every one
 * matching the list's filters. Each change is kept with every
 * subscription's value before and after, listed on its own page, and can be
 * undone: a subscription whose value was changed again since keeps the
 * newer value.
 */
class SubscriptionBulkChangeController extends Controller
{
    use FiltersDataTable, FiltersSubscriptionList;

    /**
     * The bulk changes made so far, newest first, with one's subscriptions
     * loaded on request (`?change=` with `only: ['details']`).
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $actor = $request->user();

        $changes = SubscriptionBulkChange::query()
            ->visibleTo($actor)
            ->with(['user', 'undoneBy', 'branch'])
            ->latest('id')
            ->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (SubscriptionBulkChange $change) => [
                'id' => $change->id,
                'field' => $change->field,
                'description' => $change->description,
                'changedCount' => $change->changed_count,
                'branchName' => $change->branch?->name ?? 'كل الفروع',
                'userName' => $change->user?->name,
                'createdAt' => $change->created_at->toIso8601String(),
                'undoneAt' => $change->undone_at?->toIso8601String(),
                'undoneBy' => $change->undoneBy?->name,
                'restoredCount' => $change->restored_count,
                'canUndo' => ! $change->isUndone() && $actor->can('bulkUpdate', [Subscription::class, $change->field]),
            ]);

        return Inertia::render('Subscriptions/BulkChanges', [
            'changes' => $changes,
            'filters' => $this->dataTableState($request, 'created_at', 'desc'),
            'details' => Inertia::optional(fn () => $this->details($request, $actor)),
        ]);
    }

    /**
     * Set the field for every chosen subscription whose value differs, and
     * keep the change for review and undo.
     */
    public function store(ApplySubscriptionBulkChangeRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $field = $request->validated('field');
        if ($field === 'meter_box_id') {
            return $this->moveMeterBoxes($request, $actor);
        }
        $byCircuitBreaker = $field === 'minimum_charge' && $request->validated('mode') === 'circuit_breaker';

        $subscriptions = $this->chosenSubscriptions($request, $actor)->with('circuitBreaker')->limit(ApplySubscriptionBulkChangeRequest::MAX_SUBSCRIPTIONS + 1)->get();

        if ($subscriptions->count() > ApplySubscriptionBulkChangeRequest::MAX_SUBSCRIPTIONS) {
            return back()->withErrors(['ids' => 'يمكن تعديل '.ApplySubscriptionBulkChangeRequest::MAX_SUBSCRIPTIONS.' مشترك على الأكثر في المرة الواحدة؛ ضيّق الفلاتر.']);
        }

        if ($request->has('expected_count') && $subscriptions->count() !== (int) $request->validated('expected_count')) {
            return back()->withErrors(['ids' => 'تغيّرت النتائج المطابقة منذ تحديدها. حدّث الصفحة وحدّد الاشتراكات مجددًا قبل التطبيق.']);
        }

        $changes = $subscriptions
            ->map(fn (Subscription $subscription) => [
                'subscription' => $subscription,
                'old' => $this->currentValue($subscription, $field),
                'new' => $this->normalize($field, $byCircuitBreaker ? $subscription->circuitBreaker?->minimum_payment : $request->validated('value')),
            ])
            ->filter(fn (array $change) => $change['new'] !== null && $change['new'] !== $change['old'])
            ->values();
        $backToWaiting = $changes->filter(fn (array $change) => $this->returnsToWaiting($field, $change));
        $changes = $changes->reject(fn (array $change) => $this->returnsToWaiting($field, $change))->values();
        $missingReading = $changes->filter(fn (array $change) => $this->activatesWithoutReading($field, $change));
        $changes = $changes->reject(fn (array $change) => $this->activatesWithoutReading($field, $change))->values();

        if ($changes->isEmpty()) {
            return back()->withErrors(['ids' => match (true) {
                $backToWaiting->isNotEmpty() => 'لا يمكن إعادة مشترك سبق تفعيله إلى «قيد الانتظار»؛ غيّر حالته إلى «مفصول».',
                $missingReading->isNotEmpty() => 'لا يمكن تفعيل مشترك قبل إدخال قراءته السابقة؛ أدخلها من «تعديل المشترك» أولًا.',
                default => 'لا يوجد بين المختارين من تتغير قيمته.',
            }]);
        }

        $bulkChange = DB::transaction(function () use ($actor, $field, $byCircuitBreaker, $request, $changes): SubscriptionBulkChange {
            $locked = Subscription::query()->whereKey($changes->pluck('subscription.id'))->orderBy('id')->lockForUpdate()->get();
            if ($locked->contains(fn (Subscription $subscription): bool => $subscription->archived_at !== null)) {
                throw ValidationException::withMessages(['ids' => 'تمت أرشفة أحد الاشتراكات المختارة؛ حدّث القائمة قبل إعادة المحاولة.']);
            }

            $branchIds = $changes->pluck('subscription.branch_id')->unique();
            $bulkChange = SubscriptionBulkChange::create([
                'field' => $field,
                'value' => $byCircuitBreaker ? null : $this->normalize($field, $request->validated('value')),
                'description' => $this->describe($field, $byCircuitBreaker, $request->validated('value')),
                'branch_id' => $actor->isSuperAdmin() ? ($branchIds->count() === 1 ? $branchIds->first() : null) : $actor->workBranchId(),
                'user_id' => $actor->id,
                'changed_count' => $changes->count(),
            ]);

            $items = [];

            foreach ($changes as $change) {
                $subscription = $change['subscription'];
                // Becoming active starts the subscription (or connects it again), as it does when the subscription is edited.
                $becomesActive = $field === 'status'
                    && $change['new'] === SubscriptionStatus::Active->value
                    && $change['old'] !== SubscriptionStatus::Active->value;
                $sideEffects = $becomesActive ? $this->activationEffects($subscription) : [];

                Subscription::query()->whereNull('archived_at')->whereKey($subscription->id)->update([
                    $field => $change['new'],
                    ...collect($sideEffects)->map(fn (array $effect): ?string => $effect['new'])->all(),
                ]);

                $items[] = [
                    'subscription_bulk_change_id' => $bulkChange->id,
                    'subscription_id' => $subscription->id,
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'side_effects' => $sideEffects === [] ? null : json_encode($sideEffects),
                ];
            }

            foreach (array_chunk($items, 250) as $chunk) {
                SubscriptionBulkChangeItem::insert($chunk);
            }

            return $bulkChange;
        });

        $actor->notify(new ActionCompleted('subscriptions-bulk-changed', $bulkChange->description.' — '.$bulkChange->changed_count));

        return back()->with('status', 'subscriptions-bulk-changed');
    }

    /**
     * Put back each subscription's value from before the change — unless it
     * was changed again since, which then stays.
     */
    public function undo(Request $request, SubscriptionBulkChange $change): RedirectResponse
    {
        $actor = $request->user();

        abort_unless(SubscriptionBulkChange::query()->visibleTo($actor)->whereKey($change->id)->exists(), 404);
        $this->authorize('bulkUpdate', [Subscription::class, $change->field]);

        if ($change->field === 'meter_box_id') {
            return $this->undoMeterBoxMove($change, $actor);
        }

        if ($change->isUndone()) {
            return back()->withErrors(['undo' => 'تم التراجع عن هذا التعديل من قبل.']);
        }

        DB::transaction(function () use ($change, $actor): void {
            $restored = 0;
            $change->items()->with('subscription')->lockForUpdate()->get()->each(function (SubscriptionBulkChangeItem $item) use ($change, &$restored): void {
                if ($item->subscription !== null && $item->subscription->archived_at === null && $this->currentValue($item->subscription, $change->field) === $item->new_value) {
                    $restored += Subscription::query()->whereNull('archived_at')->whereKey($item->subscription_id)->update([
                        $change->field => $item->old_value,
                        ...$this->effectsToPutBack($item),
                    ]);
                }
            });

            $change->update(['undone_at' => now(), 'undone_by' => $actor->id, 'restored_count' => $restored]);
        });

        $actor->notify(new ActionCompleted('subscriptions-bulk-undone', $change->description));

        return back()->with('status', 'subscriptions-bulk-undone');
    }

    /**
     * The subscriptions chosen in the list, among those the actor may see.
     */
    private function chosenSubscriptions(ApplySubscriptionBulkChangeRequest $request, User $actor): Builder
    {
        $query = Subscription::query()
            ->select('subscriptions.*')
            ->selectRaw("COALESCE(NULLIF(subscription_name, ''), full_name) as display_name")
            ->whereNull('subscriptions.archived_at')
            ->visibleTo($actor);

        return $request->boolean('all')
            ? $this->applySubscriptionListFilters($query, $request)
            : $query->whereIn('subscriptions.id', $request->validated('ids'));
    }

    private function moveMeterBoxes(ApplySubscriptionBulkChangeRequest $request, User $actor): RedirectResponse
    {
        $bulkChange = DB::transaction(function () use ($request, $actor): SubscriptionBulkChange {
            $target = MeterBox::query()->visibleTo($actor)->lockForUpdate()->find($request->validated('value'));
            if ($target === null) {
                throw ValidationException::withMessages(['value' => 'اختر طبلونًا متاحًا ضمن فرع الاشتراكات المحددة.']);
            }

            $subscriptions = $this->chosenSubscriptions($request, $actor)
                ->orderBy('subscriptions.id')->limit(ApplySubscriptionBulkChangeRequest::MAX_SUBSCRIPTIONS + 1)->lockForUpdate()->get();

            if ($subscriptions->isEmpty() || $subscriptions->count() > ApplySubscriptionBulkChangeRequest::MAX_SUBSCRIPTIONS
                || $subscriptions->count() !== (int) $request->validated('expected_count')
                || (! $request->boolean('all') && $subscriptions->count() !== count(array_unique($request->validated('ids'))))) {
                throw ValidationException::withMessages(['ids' => 'تغيّرت الاشتراكات المحددة أو تعذّر الوصول إليها. حدّد من 1 إلى 5,000 اشتراك متاح وأعد المراجعة.']);
            }

            if ($subscriptions->contains(fn (Subscription $subscription): bool => $subscription->branch_id !== $target->branch_id)) {
                throw ValidationException::withMessages(['value' => 'النقل متاح داخل الفرع نفسه فقط. اختر اشتراكات وطبلونًا من الفرع نفسه.']);
            }

            $changes = $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->meter_box_id !== $target->id);
            if ($changes->isEmpty()) {
                throw ValidationException::withMessages(['ids' => 'كل الاشتراكات المحددة موجودة في هذا الطبلون بالفعل.']);
            }

            $bulkChange = SubscriptionBulkChange::create([
                'field' => 'meter_box_id',
                'value' => (string) $target->id,
                'description' => 'نقل إلى الطبلون ← '.$target->label(),
                'branch_id' => $target->branch_id,
                'user_id' => $actor->id,
                'changed_count' => $changes->count(),
            ]);

            foreach ($changes->chunk(250) as $chunk) {
                SubscriptionBulkChangeItem::insert($chunk->map(fn (Subscription $subscription): array => [
                    'subscription_bulk_change_id' => $bulkChange->id,
                    'subscription_id' => $subscription->id,
                    'old_value' => $this->currentValue($subscription, 'meter_box_id'),
                    'new_value' => (string) $target->id,
                ])->values()->all());
                Subscription::query()->whereKey($chunk->modelKeys())->update(['meter_box_id' => $target->id]);
            }

            return $bulkChange;
        });

        $actor->notify(new ActionCompleted('subscriptions-bulk-changed', $bulkChange->description.' — '.$bulkChange->changed_count));

        return back()->with('status', 'subscriptions-bulk-changed');
    }

    private function undoMeterBoxMove(SubscriptionBulkChange $change, User $actor): RedirectResponse
    {
        DB::transaction(function () use ($change, $actor): void {
            $change->refreshForUpdate();
            if ($change->isUndone()) {
                throw ValidationException::withMessages(['undo' => 'تم التراجع عن هذا التعديل من قبل.']);
            }

            $items = $change->items()->get();
            $oldBoxes = MeterBox::query()->visibleTo($actor)->whereKey($items->pluck('old_value')->filter()->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subscriptions = Subscription::query()->visibleTo($actor)->whereNull('archived_at')->whereKey($items->pluck('subscription_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $restored = 0;

            foreach ($items as $item) {
                $subscription = $subscriptions->get($item->subscription_id);
                $oldBox = $oldBoxes->get($item->old_value);
                if ($subscription === null || $this->currentValue($subscription, 'meter_box_id') !== $item->new_value
                    || $subscription->branch_id !== $change->branch_id
                    || ($item->old_value !== null && ($oldBox === null || $oldBox->branch_id !== $subscription->branch_id))) {
                    continue;
                }

                $subscription->update(['meter_box_id' => $item->old_value]);
                $restored++;
            }

            $change->update(['undone_at' => now(), 'undone_by' => $actor->id, 'restored_count' => $restored]);
        });

        $actor->notify(new ActionCompleted('subscriptions-bulk-undone', $change->description));

        return back()->with('status', 'subscriptions-bulk-undone');
    }

    /**
     * One bulk change's subscriptions with their value before and after,
     * and what each has now.
     *
     * @return array{id: int, field: string, items: Collection<int, array<string, mixed>>}|null
     */
    private function details(Request $request, User $actor): ?array
    {
        $change = SubscriptionBulkChange::query()->visibleTo($actor)->find($request->integer('change'));

        if ($change === null) {
            return null;
        }

        $items = $change->items()->with('subscription')->get();
        $meterBoxLabels = $change->field === 'meter_box_id'
            ? MeterBox::query()->visibleTo($actor)->whereKey($items->flatMap(fn (SubscriptionBulkChangeItem $item): array => [$item->old_value, $item->new_value, $item->subscription?->meter_box_id])->filter()->unique())->get()->mapWithKeys(fn (MeterBox $box): array => [$box->id => $box->label()])->all()
            : [];

        return [
            'id' => $change->id,
            'field' => $change->field,
            'items' => $items->map(fn (SubscriptionBulkChangeItem $item) => [
                'id' => $item->id,
                'name' => $item->subscription?->displayName() ?? 'مشترك محذوف',
                'accountNumber' => $item->subscription?->account_number,
                'old' => $this->display($change->field, $item->old_value, $meterBoxLabels),
                'new' => $this->display($change->field, $item->new_value, $meterBoxLabels),
                'now' => $item->subscription ? $this->display($change->field, $this->currentValue($item->subscription, $change->field), $meterBoxLabels) : '—',
            ]),
        ];
    }

    /**
     * Whether the change would put a subscription who has been active back to waiting.
     *
     * @param  array{subscription: Subscription, old: ?string, new: ?string}  $change
     */
    private function returnsToWaiting(string $field, array $change): bool
    {
        return $field === 'status'
            && $change['new'] === SubscriptionStatus::Suspended->value
            && ($change['old'] === SubscriptionStatus::Active->value || $change['subscription']->activated_at !== null);
    }

    /**
     * What activating a subscription also sets on them (the dates), with each
     * field's value before and after, so that an undo can put them back.
     *
     * @return array<string, array{old: ?string, new: string}>
     */
    private function activationEffects(Subscription $subscription): array
    {
        $effects = collect($subscription->datesWhenActivated())
            ->map(fn (string $date, string $field): array => ['old' => $subscription->getRawOriginal($field), 'new' => $date]);

        return $subscription->activated_at === null
            ? $effects->put('activated_at', ['old' => null, 'new' => now()->toDateTimeString()])->all()
            : $effects->all();
    }

    /**
     * The dates an activation set that are still as it left them, with the
     * value they had before; one edited since stays as it is.
     *
     * @return array<string, ?string>
     */
    private function effectsToPutBack(SubscriptionBulkChangeItem $item): array
    {
        return collect($item->side_effects ?? [])
            ->filter(fn (array $effect, string $field): bool => $this->moment($item->subscription->getRawOriginal($field)) === $this->moment($effect['new']))
            ->map(fn (array $effect): ?string => $effect['old'])
            ->all();
    }

    /** A stored date or time in one form, whatever form the database kept it in. */
    private function moment(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * Whether the change would make a subscription active before their
     * starting reading has been entered.
     *
     * @param  array{subscription: Subscription, old: ?string, new: ?string}  $change
     */
    private function activatesWithoutReading(string $field, array $change): bool
    {
        return $field === 'status' && $change['new'] === SubscriptionStatus::Active->value && $change['subscription']->initial_reading === null;
    }

    private function currentValue(Subscription $subscription, string $field): ?string
    {
        return $this->normalize($field, $subscription->getRawOriginal($field));
    }

    /** A value as kept: amounts with two decimals, statuses as their key. */
    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $field === 'minimum_charge' ? number_format((float) $value, 2, '.', '') : (string) $value;
    }

    /** A kept value as people read it. */
    private function display(string $field, ?string $value, array $meterBoxLabels = []): string
    {
        if ($field === 'meter_box_id') {
            return $value === null ? 'بدون طبلون' : ($meterBoxLabels[$value] ?? 'طبلون غير متاح (#'.$value.')');
        }
        if ($value === null) {
            return '—';
        }

        return $field === 'status'
            ? __(SubscriptionStatus::from($value)->label())
            : rtrim(rtrim($value, '0'), '.').' شيكل';
    }

    private function describe(string $field, bool $byCircuitBreaker, mixed $value): string
    {
        return match (true) {
            $field === 'status' => 'الحالة ← '.$this->display('status', (string) $value),
            $byCircuitBreaker => 'الحد الأدنى ← حد القاطع لكل مشترك',
            default => 'الحد الأدنى ← '.$this->display('minimum_charge', $this->normalize('minimum_charge', $value)),
        };
    }
}
