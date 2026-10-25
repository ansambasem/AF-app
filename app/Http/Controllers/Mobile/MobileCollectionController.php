<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\AccountingType;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileCollectionRequest;
use App\Models\Closing;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Support\ArabicSearch;
use App\Support\BranchClosingWorkflow;
use App\Support\ClosingPeriods;
use App\Support\Messaging\SmsGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileCollectionController extends Controller
{
    /** How many of the latest statement lines the subscription page shows. */
    private const RECENT_TRANSACTIONS = 10;

    public function subscriptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('recordAnyPayment', Subscription::class)
            || $request->user()->can('viewAny', Subscription::class)
            || $request->user()->hasAnyPermission(PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings), 403);

        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['search'] ?? '');
        // Any subscription of the branch can pay, as on the website: a
        // suspended or disconnected one may still be settling their debt.
        $subscriptions = Subscription::query()
            ->whereNull('subscriptions.archived_at')
            ->visibleTo($request->user())
            ->with('meterBox:id,box_number')
            ->withSum('transactions as balance', 'amount')
            ->matchingSearch($search)
            // The account typed in full comes first, ahead of longer numbers containing it.
            ->when($search !== '', fn ($query) => $query->orderByRaw('case when account_number = ? then 0 else 1 end', [ArabicSearch::normalize($search)]))
            ->orderByDesc('balance')
            ->orderBy('id')
            ->paginate(25);

        return response()->json([
            'data' => $subscriptions->getCollection()->map(fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'full_name' => $subscription->displayName(),
                'account_number' => $subscription->account_number,
                'meter_box_number' => $subscription->meterBox?->box_number,
                'balance' => $subscription->balance ?? '0.00',
                'status' => $subscription->status->value,
                'status_label' => __($subscription->status->label()),
            ])->all(),
            'current_page' => $subscriptions->currentPage(),
            'last_page' => $subscriptions->lastPage(),
        ]);
    }

    /**
     * One subscription's account as the collector sees it before taking a
     * payment: who they are, what they owe, their last payment and the
     * latest lines of their statement, newest first.
     */
    public function show(Request $request, string $subscription): JsonResponse
    {
        abort_unless($request->user()->can('recordAnyPayment', Subscription::class)
            || $request->user()->can('viewAny', Subscription::class)
            || $request->user()->hasAnyPermission(PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings), 403);

        $subscription = Subscription::query()
            ->whereNull('subscriptions.archived_at')
            ->visibleTo($request->user())
            ->with(['branch.area', 'meterBox.subArea', 'tariff', 'circuitBreaker', 'latestMeterReading'])
            ->findOrFail($subscription);
        $lastReading = $subscription->latestMeterReading;
        $balanceInCents = Closing::cents($subscription->balance());
        $canViewHistory = $request->user()->can('view', $subscription);
        $lastPayment = $canViewHistory ? $subscription->transactions()
            ->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->whereNull('cancelled_at')
            ->latest()
            ->latest('id')
            ->first() : null;
        $recentTransactions = $canViewHistory ? $subscription->transactions()
            ->with(['meterReading', 'referenceTransaction', 'bankTransferAudit'])
            ->latest()
            ->latest('id')
            ->limit(self::RECENT_TRANSACTIONS)
            ->get() : collect();

        // Walk back from the current balance, so each line shows the balance it left.
        $balanceAfterInCents = $balanceInCents;
        $transactions = $recentTransactions->map(function (SubscriptionTransaction $transaction) use (&$balanceAfterInCents): array {
            return $this->statementLine($transaction, $balanceAfterInCents);
        });

        return response()->json([
            'subscription' => [
                'id' => $subscription->id,
                'full_name' => $subscription->displayName(),
                'subscriber_name' => $subscription->full_name,
                'subscription_name' => $subscription->subscription_name,
                'national_id' => $subscription->national_id,
                'personal_phone' => $subscription->phone,
                'account_number' => $subscription->account_number,
                'meter_box_number' => $subscription->meterBox?->box_number,
                'meter_box_name' => $subscription->meterBox?->displayName(),
                'meter_box_location' => $subscription->meterBox?->location,
                'phone' => $subscription->contactPhone(),
                'address' => $subscription->address,
                'area_name' => $subscription->branch?->area?->name,
                'sub_area_name' => $subscription->meterBox?->subArea?->name,
                'balance' => Closing::money($balanceInCents),
                'status' => $subscription->status->value,
                'status_label' => __($subscription->status->label()),
                'accounting_type' => $subscription->accounting_type->value,
                'accounting_type_label' => match ($subscription->accounting_type) {
                    AccountingType::Weekly => 'أسبوعي',
                    AccountingType::Monthly => 'شهري',
                },
                'subscription_date' => $subscription->subscription_date?->toDateString(),
                'tariff_name' => __($subscription->tariff->category->label()),
                'kilowatt_price' => $subscription->kilowattPrice(),
                'minimum_payment' => number_format((float) $subscription->weeklyMinimumPayment(), 2, '.', ''),
                'circuit_breaker_ampere' => $subscription->circuitBreaker?->ampere,
                'initial_reading' => $subscription->initial_reading,
            ],
            'last_reading' => $lastReading ? [
                'id' => $lastReading->id,
                'week_start' => $lastReading->week_start->toDateString(),
                'week_end' => $lastReading->week_end->toDateString(),
                'previous_reading' => $lastReading->previous_reading,
                'current_reading' => $lastReading->current_reading,
                'consumption' => $lastReading->consumption,
                'status' => $lastReading->status->value,
                'status_label' => __($lastReading->status->label()),
            ] : null,
            'last_payment' => $lastPayment ? $this->collectionData($lastPayment->setRelation('subscription', $subscription)) : null,
            'transactions' => $transactions->all(),
            'permissions' => [
                'view_history' => $canViewHistory,
                'record_payment' => $request->user()->can('recordPayment', $subscription),
                'record_split_payment' => $request->user()->can('recordSplitPayment', Subscription::class) && $request->user()->can('recordPayment', $subscription),
                'add_charge' => $request->user()->can('addCharge', $subscription),
                'add_discount' => $request->user()->can('addDiscount', $subscription),
                'add_clearing' => $request->user()->can('addClearing', $subscription),
                'payment_methods' => array_map(fn (PaymentMethod $method): string => $method->value, $request->user()->allowedPaymentMethods()),
            ],
            'charge_types' => $request->user()->can('addCharge', $subscription) ? ChargeType::formOptions() : [],
            'messaging' => [
                'can_send' => $request->user()->can('create', MessageBatch::class),
                'can_view_history' => $request->user()->can('viewAny', MessageBatch::class),
                'sms_available' => app(SmsGateway::class)->deliversMessages(),
            ],
        ]);
    }

    public function transactions(Request $request, string $subscription): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $subscription = Subscription::query()->visibleTo($request->user())->findOrFail($subscription);
        $this->authorize('view', $subscription);
        $transactions = $subscription->transactions()
            ->with(['meterReading', 'referenceTransaction', 'bankTransferAudit'])
            ->latest()->latest('id')->paginate(25);
        $first = $transactions->first();
        $balanceAfterInCents = $first ? Closing::cents($subscription->transactions()
            ->where(fn ($query) => $query->where('created_at', '<', $first->created_at)
                ->orWhere(fn ($sameTime) => $sameTime->where('created_at', $first->created_at)->where('id', '<=', $first->id)))
            ->sum('amount')) : 0;
        $lines = $transactions->getCollection()->map(function (SubscriptionTransaction $transaction) use (&$balanceAfterInCents): array {
            return $this->statementLine($transaction, $balanceAfterInCents);
        });

        return response()->json([
            'data' => $lines->all(),
            'balance' => Closing::money(Closing::cents($subscription->balance())),
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
            'total' => $transactions->total(),
        ]);
    }

    /** @return array<string, mixed> */
    private function statementLine(SubscriptionTransaction $transaction, int &$balanceAfterInCents): array
    {
        $entry = [
            'id' => $transaction->id,
            'date' => $transaction->created_at->copy()->setTimezone(config('app.business_timezone'))->toDateString(),
            'recorded_at' => $transaction->created_at->copy()->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i'),
            'bank_received_on' => $transaction->bank_received_on?->toDateString(),
            'type' => $transaction->type,
            'type_label' => $transaction->typeLabel(),
            'description' => $transaction->description(),
            'notes' => $transaction->notes,
            'voucher_number' => $transaction->displayVoucherNumber(),
            'reference_number' => $transaction->reference_number,
            'amount' => Closing::money(abs(Closing::cents($transaction->amount))),
            'is_credit' => (float) $transaction->amount < 0,
            'is_cancelled' => $transaction->isCancelled(),
            'balance_after' => Closing::money($balanceAfterInCents),
        ];
        $balanceAfterInCents -= Closing::cents($transaction->amount);

        return $entry;
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionKey::RecordCollections), 403);

        // Today is the business day of the user's branch (in the business's time zone, up to its closing cut-off), not the UTC date.
        $periods = ClosingPeriods::for($request->user()->branch_id);
        $day = $periods->dayOf(now());
        [$from, $until] = $periods->utcRange($day, $day);

        $collections = SubscriptionTransaction::query()
            ->where('recorded_by', $request->user()->id)
            ->where('type', SubscriptionTransaction::TYPE_PAYMENT)
            ->whereNull('cancelled_at')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->with('subscription:id,full_name,subscription_name,account_number')
            ->latest()
            ->latest('id')
            ->get();

        // Totals are in shekels, as the account is, whatever currency each payment came in.
        $methods = $request->user()->allowedPaymentMethods();

        return response()->json([
            'total' => $collections->sum(fn (SubscriptionTransaction $transaction): float => -(float) $transaction->amount),
            'payment_methods' => array_map(fn (PaymentMethod $method): string => $method->value, $methods),
            ...(in_array(PaymentMethod::Cash, $methods, true) ? ['cash_total' => $collections->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->payment_method === PaymentMethod::Cash)
                ->sum(fn (SubscriptionTransaction $transaction): float => -(float) $transaction->amount)] : []),
            ...(in_array(PaymentMethod::BankTransfer, $methods, true) ? ['bank_transfer_total' => $collections->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->payment_method === PaymentMethod::BankTransfer)
                ->sum(fn (SubscriptionTransaction $transaction): float => -(float) $transaction->amount)] : []),
            'data' => $collections->map(fn (SubscriptionTransaction $transaction): array => $this->collectionData($transaction))->all(),
        ]);
    }

    /**
     * Whether a transfer's reference is free or already on a payment, for the
     * app to say as it is typed. A repeated one is still recorded once the
     * collector confirms it (`confirm_duplicate_reference`).
     */
    public function referenceStatus(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionKey::RecordCollections), 403);

        $validated = $request->validate(['reference_number' => ['required', 'string', 'max:100']]);
        $conflict = SubscriptionTransaction::activeReferenceConflict($validated['reference_number']);

        return response()->json([
            'available' => $conflict === null,
            'message' => $conflict === null ? 'الرقم المرجعي متاح.' : 'الرقم المرجعي مكرر: مسجَّل مسبقًا على دفعة أخرى.',
            'conflict' => $conflict ? [
                'voucher_number' => $conflict->displayVoucherNumber(),
                'subscription_name' => $conflict->subscription->displayName(),
            ] : null,
        ]);
    }

    public function store(StoreMobileCollectionRequest $request): JsonResponse
    {
        if ($existingTransaction = $request->existingTransaction()) {
            return response()->json($this->recordedCollectionData($existingTransaction), 201);
        }

        $subscription = Subscription::query()->visibleTo($request->user())->findOrFail($request->integer('subscription_id'));
        $this->authorize('recordPayment', $subscription);
        $validated = $request->validated();

        try {
            $transaction = DB::transaction(function () use ($subscription, $request, $validated): SubscriptionTransaction {
                $transaction = SubscriptionTransaction::recordPayment($subscription, $request->user(), [
                    'mobile_operation_id' => $validated['mobile_operation_id'],
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'exchange_rate' => $validated['exchange_rate'] ?? null,
                    'payment_method' => $validated['payment_method'],
                    'bank_name' => $validated['bank_name'] ?? null,
                    'bank_received_on' => $validated['bank_received_on'] ?? null,
                    'sender_bank_name' => $validated['sender_bank_name'] ?? null,
                    'sender_name' => $validated['sender_name'] ?? null,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'confirm_duplicate_reference' => (bool) ($validated['confirm_duplicate_reference'] ?? false),
                    'manual_voucher_number' => $validated['manual_voucher_number'] ?? null,
                    'cash_box' => $validated['cash_box'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);
                if (filled($validated['collected_at'] ?? null)) {
                    app(BranchClosingWorkflow::class)->syncOriginalTime($transaction, CarbonImmutable::parse($validated['collected_at']));
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $transaction = $request->existingTransaction();

            if (! $transaction) {
                throw $exception;
            }

            abort_unless($transaction->recorded_by === $request->user()->id, 403);
        }

        return response()->json($this->recordedCollectionData($transaction), 201);
    }

    /**
     * A payment just recorded, with the subscription's balance after it, as
     * the website's receipt shows it.
     *
     * @return array<string, mixed>
     */
    private function recordedCollectionData(SubscriptionTransaction $transaction): array
    {
        $transaction->load('subscription');

        return [
            ...$this->collectionData($transaction),
            'balance_after' => number_format($transaction->subscription->balance(), 2, '.', ''),
        ];
    }

    /**
     * The amount is in the currency it was paid in; `amount_in_shekels` is
     * what it took off the balance.
     *
     * @return array{id: int, subscription: string, amount: string, currency: string, exchange_rate: ?string, amount_in_shekels: string, payment_method: string, bank_name: ?string, sender_bank_name: ?string, status: string, recorded_at: string, voucher_number: ?string}
     */
    private function collectionData(SubscriptionTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'subscription' => $transaction->subscription->displayName(),
            'amount' => $transaction->currency_amount,
            'currency' => $transaction->currency->value,
            'exchange_rate' => $transaction->exchange_rate,
            'amount_in_shekels' => number_format(-(float) $transaction->amount, 2, '.', ''),
            'payment_method' => $transaction->payment_method->value,
            'bank_name' => $transaction->bank_name,
            'bank_received_on' => $transaction->bank_received_on?->toDateString(),
            'sender_bank_name' => $transaction->sender_bank_name,
            'status' => 'recorded',
            'recorded_at' => $transaction->created_at->toIso8601String(),
            'voucher_number' => $transaction->printedVoucherNumber(),
        ];
    }
}
