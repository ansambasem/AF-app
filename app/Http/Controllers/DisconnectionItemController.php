<?php

namespace App\Http\Controllers;

use App\Enums\DisconnectionEventType;
use App\Enums\DisconnectionReason;
use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Http\Concerns\FiltersDisconnectionItems;
use App\Http\Concerns\PresentsDisconnectionItems;
use App\Http\Requests\AddDisconnectionItemsRequest;
use App\Http\Requests\ConfirmDisconnectionItemsRequest;
use App\Http\Requests\RemoveDisconnectionItemsRequest;
use App\Http\Requests\RescheduleDisconnectionItemsRequest;
use App\Http\Requests\WarnDisconnectionItemsRequest;
use App\Jobs\SendSubscriptionMessage;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use App\Notifications\ActionCompleted;
use App\Support\Messaging\MessageComposer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * What is done to the subscriptions on a disconnection list, one or many at
 * a time: adding them, moving their date, removing them, warning them and
 * confirming their disconnection. Every bulk action starts from a preview of
 * exactly which subscriptions it reaches, then acts on those, each on its
 * own, and reports how each one went.
 */
class DisconnectionItemController extends Controller
{
    use FiltersDisconnectionItems, PresentsDisconnectionItems;

    public function __construct(private MessageComposer $composer) {}

    /**
     * Put the chosen subscriptions on the list: the ticked ones, or every
     * match of the candidates page.
     */
    public function store(AddDisconnectionItemsRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        if ($disconnectionList->isClosed()) {
            return back()->withErrors(['ids' => 'القائمة مغلقة؛ أعد فتحها لإضافة مشتركين.']);
        }

        $query = $request->boolean('all')
            ? DisconnectionListController::candidateQuery($disconnectionList, $request)
            : Subscription::query()->where('branch_id', $disconnectionList->branch_id)->whereIn('id', $request->validated('ids'))->orderBy('id');
        $subscriptions = $query->limit(AddDisconnectionItemsRequest::MAX_SUBSCRIPTIONS + 1)->get();

        if ($subscriptions->count() > AddDisconnectionItemsRequest::MAX_SUBSCRIPTIONS) {
            return back()->withErrors(['ids' => 'يمكن إضافة '.AddDisconnectionItemsRequest::MAX_SUBSCRIPTIONS.' مشترك على الأكثر في المرة الواحدة؛ ضيّق الفلاتر.']);
        }

        $result = $disconnectionList->addSubscriptions(
            $subscriptions,
            DisconnectionReason::from($request->validated('reason')),
            $request->validated('note'),
            Carbon::parse($request->validated('scheduled_for')),
            $request->user(),
        );

        Inertia::flash('disconnectionResult', [
            'action' => 'add',
            'succeeded' => count($result['added']),
            'failed' => $result['skipped'],
        ]);

        if ($result['added'] === []) {
            return back()->withErrors(['ids' => 'لم يُضف أي مشترك؛ راجع الأسباب في التقرير.']);
        }

        $request->user()->notify(new ActionCompleted('disconnection-items-added', $disconnectionList->title.' — '.count($result['added'])));

        return back()->with('status', 'disconnection-items-added');
    }

    /**
     * Exactly which items an action would reach — the ticked ones, or every
     * match of the list's search and filters — with what the action needs to
     * show: their status, balance, fee and, for those who may warn, phone.
     */
    public function preview(Request $request, DisconnectionList $disconnectionList): JsonResponse
    {
        $this->authorize('view', $disconnectionList);

        $request->validate([
            'all' => ['boolean'],
            'ids' => ['exclude_if:all,true', 'required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $query = $request->boolean('all')
            ? $this->filteredDisconnectionItems($disconnectionList, $request)
            : $disconnectionList->items()->whereIn('id', array_map('intval', $request->input('ids')))->orderBy('id');
        $items = $query->with($this->disconnectionItemRelations())->limit(RescheduleDisconnectionItemsRequest::MAX_ITEMS + 1)->get();
        $withPhone = $request->user()->can('warn', $disconnectionList);

        return response()->json([
            'tooMany' => $items->count() > RescheduleDisconnectionItemsRequest::MAX_ITEMS,
            'max' => RescheduleDisconnectionItemsRequest::MAX_ITEMS,
            'items' => $items->take(RescheduleDisconnectionItemsRequest::MAX_ITEMS)->map(fn (DisconnectionListItem $item) => $this->disconnectionItemRow($item, $withPhone))->values(),
        ]);
    }

    /**
     * Move the chosen items' date — any number of times. Nothing is sent.
     */
    public function reschedule(RescheduleDisconnectionItemsRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        if ($disconnectionList->isClosed()) {
            return back()->withErrors(['ids' => 'القائمة مغلقة.']);
        }

        $date = Carbon::parse($request->validated('scheduled_for'));
        $reason = $request->validated('reason');

        return $this->eachItem($request, $disconnectionList, 'reschedule', 'disconnection-items-rescheduled', fn (DisconnectionListItem $item): ?string => $item->reschedule($date, $request->user(), $reason) ? null : 'لم يعد بانتظار القطع');
    }

    /**
     * Take the chosen items off the list; their history stays.
     */
    public function remove(RemoveDisconnectionItemsRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        if ($disconnectionList->isClosed()) {
            return back()->withErrors(['ids' => 'القائمة مغلقة.']);
        }

        $reason = $request->validated('reason');

        return $this->eachItem($request, $disconnectionList, 'remove', 'disconnection-items-removed', fn (DisconnectionListItem $item): ?string => $item->remove($request->user(), $reason) ? null : 'لم يعد بانتظار القطع');
    }

    /**
     * Warn the chosen subscriptions who wait on the list and have a phone
     * number: an SMS through the gateway, or WhatsApp messages the staff
     * member sends from the send's page. The same send (`operation_id`)
     * arriving again sends nothing more; a new warning to the same people
     * is a new send.
     */
    public function warn(WarnDisconnectionItemsRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        $actor = $request->user();
        $operationId = $request->validated('operation_id');

        if ($existing = MessageBatch::query()->where('operation_id', $operationId)->first()) {
            return redirect()->route('messages.show', $existing)->with('status', 'disconnection-warning-already-sent');
        }

        if ($disconnectionList->isClosed()) {
            return back()->withErrors(['ids' => 'القائمة مغلقة.']);
        }

        $channel = MessageChannel::from($request->validated('channel'));
        $body = $request->validated('body');
        $failed = [];
        $recipients = [];

        foreach ($request->items() as $item) {
            $phone = $item->subscription->contactPhone();

            if (! $item->isScheduled()) {
                $failed[] = $this->failure($item, 'لم يعد بانتظار القطع');
            } elseif (! filled($phone)) {
                $failed[] = $this->failure($item, 'لا يوجد رقم هاتف');
            } else {
                $recipients[] = $item;
            }
        }

        if ($recipients === []) {
            Inertia::flash('disconnectionResult', ['action' => 'warn', 'succeeded' => 0, 'failed' => $failed]);

            return back()->withErrors(['ids' => 'لا يوجد بين المختارين من ينتظر القطع ولديه رقم هاتف.']);
        }

        try {
            $batch = DB::transaction(function () use ($actor, $disconnectionList, $channel, $body, $operationId, $recipients): MessageBatch {
                $batch = MessageBatch::create([
                    'branch_id' => $disconnectionList->branch_id,
                    'kind' => MessageKind::DisconnectionWarning,
                    'channel' => $channel,
                    'body' => $body,
                    'created_by' => $actor->id,
                    'operation_id' => $operationId,
                ]);

                foreach ($recipients as $item) {
                    $subscription = Subscription::query()->withSum('transactions as balance', 'amount')->with(['branch', 'meterBox'])->findOrFail($item->subscription_id);
                    $message = $batch->messages()->create([
                        'subscription_id' => $subscription->id,
                        'branch_id' => $subscription->branch_id,
                        'phone' => $subscription->contactPhone(),
                        'body' => $this->composer->render($body, [
                            ...$this->composer->variables($subscription, MessageKind::DisconnectionWarning),
                            'موعد_القطع' => $item->scheduled_for->format('d/m/Y'),
                        ]),
                        'status' => MessageStatus::Pending,
                    ]);

                    DisconnectionListItem::query()->whereKey($item->id)->update([
                        'warnings_count' => DB::raw('warnings_count + 1'),
                        'last_warned_at' => now(),
                    ]);
                    $item->recordEvent(DisconnectionEventType::Warned, $actor, null, ['channel' => $channel->value, 'message_batch_id' => $batch->id], null, $message->id);
                }

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // The same send arrived twice at once: the other request sent it.
            $existing = MessageBatch::query()->where('operation_id', $operationId)->firstOrFail();

            return redirect()->route('messages.show', $existing)->with('status', 'disconnection-warning-already-sent');
        }

        if ($channel === MessageChannel::Sms) {
            $batch->messages()->where('status', MessageStatus::Pending)->each(fn (SubscriptionMessage $message) => SendSubscriptionMessage::dispatch($message));
        }

        Inertia::flash('disconnectionResult', [
            'action' => 'warn',
            'succeeded' => count($recipients),
            'failed' => $failed,
            'batchId' => $batch->id,
            'channel' => $channel->value,
        ]);
        $actor->notify(new ActionCompleted('disconnection-warnings-created', $disconnectionList->title.' — '.count($recipients)));

        return back()->with('status', $channel === MessageChannel::Sms ? 'disconnection-warnings-queued' : 'disconnection-warnings-ready');
    }

    /**
     * Confirm that the chosen subscriptions have really been disconnected:
     * each is disconnected and charged the fee chosen for it on its own,
     * after checking again under lock that it still waits and its service is
     * still on. Each one's result is reported.
     */
    public function confirm(ConfirmDisconnectionItemsRequest $request, DisconnectionList $disconnectionList): RedirectResponse
    {
        $actor = $request->user();
        $succeeded = 0;
        $failed = [];
        $feesTotal = 0.0;

        foreach ($request->items() as $item) {
            $outcome = $item->confirmDisconnection($actor, $request->feeFor($item->id));

            if ($outcome === 'disconnected') {
                $succeeded++;
                $feesTotal += (float) $item->fee_amount;
            } else {
                $failed[] = $this->failure($item, DisconnectionListItem::OUTCOMES[$outcome]);
            }
        }

        $missing = count($request->validated('ids')) - $request->items()->count();

        if ($missing > 0) {
            $failed[] = ['id' => null, 'name' => $missing.' من المختارين', 'accountNumber' => null, 'reason' => 'ليسوا في هذه القائمة'];
        }

        Log::info('Disconnections confirmed', [
            'list_id' => $disconnectionList->id,
            'user_id' => $actor->id,
            'succeeded' => $succeeded,
            'failed' => count($failed),
            'fees_total' => $feesTotal,
        ]);

        Inertia::flash('disconnectionResult', [
            'action' => 'confirm',
            'succeeded' => $succeeded,
            'failed' => $failed,
            'feesTotal' => number_format($feesTotal, 2, '.', ''),
        ]);

        if ($succeeded === 0) {
            return back()->withErrors(['ids' => 'لم يُؤكَّد قطع أي مشترك؛ راجع الأسباب في التقرير.']);
        }

        $actor->notify(new ActionCompleted('disconnections-confirmed', $disconnectionList->title.' — '.$succeeded));

        return back()->with('status', $failed === [] ? 'disconnections-confirmed' : 'disconnections-confirmed-partly');
    }

    /**
     * Apply the action to each chosen item on its own and report the items
     * it could not be applied to.
     *
     * @param  callable(DisconnectionListItem): ?string  $apply  returns why it failed, or null
     */
    private function eachItem(RescheduleDisconnectionItemsRequest|RemoveDisconnectionItemsRequest $request, DisconnectionList $list, string $action, string $status, callable $apply): RedirectResponse
    {
        $succeeded = 0;
        $failed = [];

        foreach ($request->items() as $item) {
            $why = $apply($item);

            if ($why === null) {
                $succeeded++;
            } else {
                $failed[] = $this->failure($item, $why);
            }
        }

        Inertia::flash('disconnectionResult', ['action' => $action, 'succeeded' => $succeeded, 'failed' => $failed]);

        if ($succeeded === 0) {
            return back()->withErrors(['ids' => 'لم يُطبَّق الإجراء على أي مشترك؛ راجع الأسباب في التقرير.']);
        }

        $request->user()->notify(new ActionCompleted($status, $list->title.' — '.$succeeded));

        return back()->with('status', $status);
    }

    /**
     * An item an action could not be applied to, and why.
     *
     * @return array{id: int, name: string, accountNumber: ?string, reason: string}
     */
    private function failure(DisconnectionListItem $item, string $reason): array
    {
        return [
            'id' => $item->id,
            'name' => $item->subscription->displayName(),
            'accountNumber' => $item->subscription->account_number,
            'reason' => $reason,
        ];
    }
}
