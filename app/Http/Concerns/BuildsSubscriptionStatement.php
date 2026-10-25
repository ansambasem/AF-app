<?php

namespace App\Http\Concerns;

use App\Enums\AuditAction;
use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\Currency;
use App\Enums\DiscountMethod;
use App\Enums\PaymentMethod;
use App\Enums\PermissionKey;
use App\Models\Bank;
use App\Models\MeterReading;
use App\Models\StandingDiscount;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A subscription's account statement — every charge, payment and discount,
 * oldest first, each with the balance it left — with what the payment,
 * charge and discount forms need. A corrected or deleted line is followed
 * by its reversal and the line that replaced it. Shared by the statement
 * page and the statement window on the subscriptions list. Transactions stay
 * in their original chronological order, including reversals and corrections.
 */
trait BuildsSubscriptionStatement
{
    /**
     * The statement shown in a window over a list, for the subscription named
     * by `?statement=` — kept in the address so the window stays open after
     * a payment, charge or discount is saved in it. Null when none is asked
     * for, or the subscription isn't one the actor may see.
     *
     * @return array<string, mixed>|null
     */
    protected function requestedStatement(Request $request, User $actor): ?array
    {
        $subscriptionId = $request->query('statement');

        if (! is_string($subscriptionId) || ! ctype_digit($subscriptionId)) {
            return null;
        }

        $subscription = Subscription::query()->readableTo($actor)->find($subscriptionId);

        return $subscription && $actor->can('view', $subscription) ? $this->subscriptionStatement($actor, $subscription) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionStatement(User $actor, Subscription $subscription): array
    {
        $subscription->loadMissing(['profile', 'branch', 'tariff', 'tariffSegment', 'meterBox', 'circuitBreaker', 'standingDiscount.grantedBy', 'latestMeterReading']);

        $transactions = $subscription->transactions()
            ->with([
                'recordedBy',
                'meterReading',
                'cancelledBy',
                'reverses.closingLine.closing',
                'reverses.boxEntry.box:id,number,status',
                'reverses.splitPayment',
                'boxEntry.box:id,number,status',
                'referenceTransaction.closingLine.closing',
                'linkedReversals',
                'corrects.linkedReversals',
                'correction',
                'amendments.user',
                'closingLine.closing',
                'splitPayment',
                'bankTransferAudit',
            ])
            ->oldest()
            ->orderBy('id')
            ->get()
            ->each(fn (SubscriptionTransaction $transaction) => $transaction->setRelation('subscription', $subscription));

        $firstLineIds = $this->firstLineIds($transactions);
        $lineNumbers = $transactions->values()->mapWithKeys(fn (SubscriptionTransaction $transaction, int $index): array => [$transaction->id => $index + 1])->all();
        $balanceInCents = 0;
        $lastTransactionId = $transactions->last()?->id;
        $previousTransactionId = $transactions->count() > 1 ? $transactions->values()->get($transactions->count() - 2)->id : null;
        $lastGroupId = $transactions->whereNull('reverses_id')->last()?->id;
        $lastLegacyLineId = $transactions
            ->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->id === $lastGroupId || $transaction->reverses_id === $lastGroupId)
            ->last()?->id;
        $entries = $transactions
            ->map(function (SubscriptionTransaction $transaction) use (&$balanceInCents, $actor, $firstLineIds, $lineNumbers, $lastTransactionId, $previousTransactionId, $lastLegacyLineId, $transactions): array {
                $balanceInCents += $this->cents($transaction->amount);
                $hasPayment = $transactions->contains(fn (SubscriptionTransaction $candidate): bool => $candidate->reference_transaction_id === $transaction->id
                    && in_array($candidate->type, SubscriptionTransaction::PAYMENT_LIKE_TYPES, true));
                // A standing weekly reading's own standing discount, which cancelling the reading cancels too.
                $readingDiscount = $transaction->type === SubscriptionTransaction::TYPE_METER_READING && $transaction->meter_reading_id !== null && ! $transaction->isCancelled()
                    ? $transactions->first(fn (SubscriptionTransaction $candidate): bool => $candidate->meter_reading_id === $transaction->meter_reading_id
                        && $candidate->type === SubscriptionTransaction::TYPE_READING_DISCOUNT
                        && ! $candidate->isCancelled())
                    : null;

                // A reading followed only by its own standing discount is the last bill: the two are deleted together.
                $isLast = $transaction->id === $lastTransactionId
                    || ($transaction->id === $previousTransactionId && $readingDiscount?->id === $lastTransactionId);
                $entry = $this->statementEntry(
                    $transaction,
                    $balanceInCents,
                    $actor,
                    $firstLineIds[$transaction->id],
                    $lineNumbers,
                    $isLast,
                    $hasPayment,
                    $transaction->id === $lastLegacyLineId,
                );

                if ($readingDiscount !== null) {
                    $entry['actionEffects']['delete'] = $this->money($this->cents($transaction->amount) + $this->cents($readingDiscount->amount));
                }
                if ($transaction->split_payment_id !== null) {
                    $partIds = $transactions->where('split_payment_id', $transaction->split_payment_id)->modelKeys();
                    $effect = $transactions->filter(fn (SubscriptionTransaction $line): bool => in_array($line->id, $partIds, true)
                        || ($line->isReversal() && in_array($line->reverses_id, $partIds, true)))
                        ->sum(fn (SubscriptionTransaction $line): int => $this->cents($line->amount));
                    $entry['actionEffects']['delete'] = $this->money($effect);
                    $entry['actionEffects']['delete_tree'] = $this->money($effect);
                }

                return [...$entry, 'readingDiscount' => $readingDiscount ? SubscriptionTransaction::formatAmount(abs((float) $readingDiscount->amount)) : null];
            })
            ->values();

        // Cancelled lines and their reversals cancel each other out, so the totals leave both out.
        $counted = $transactions->reject(fn (SubscriptionTransaction $transaction): bool => $transaction->isCancelled() || $transaction->isReversal());
        $payments = $counted->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->isPayment());
        $paymentAdjustments = $counted->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT);
        $discounts = $counted->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->isDiscount());
        $clearings = $counted->filter(fn (SubscriptionTransaction $transaction): bool => $transaction->isClearing());
        $sumOf = fn ($lines): int => $lines->sum(fn (SubscriptionTransaction $transaction): int => $this->cents($transaction->amount));
        $canAddDiscount = $actor->can('addDiscount', $subscription);

        return [
            'subscription' => [
                'canPrint' => (! $actor->hasFinancialAuditAccess()) || $actor->canAuditAction(AuditAction::PrintReports),
                'id' => $subscription->id,
                'fullName' => $subscription->displayName(),
                'accountNumber' => $subscription->account_number,
                'subscriberNumber' => $subscription->profile?->subscriber_number,
                'phone' => $subscription->contactPhone(),
                'branchName' => $subscription->branch->name,
                'tariffCategoryLabel' => __($subscription->tariff->category->label()),
                'tariffSegmentName' => $subscription->tariffSegment?->name,
                'meterBoxNumber' => $subscription->meterBox?->box_number,
                'kiloPrice' => $subscription->kilowattPrice(),
                'minimumPayment' => $subscription->weeklyMinimumPayment(),
                'subscriptionFee' => $subscription->subscription_fee,
                // The discount form's worked example uses the last week read.
                'lastConsumption' => $subscription->latestMeterReading?->consumption,
                'standingDiscount' => $this->statementStandingDiscount($subscription->standingDiscount),
                'status' => $subscription->status->value,
                'statusLabel' => $subscription->archived_at !== null ? 'مؤرشف' : __($subscription->status->label()),
                'archivedAt' => $subscription->archived_at?->toIso8601String(),
                'serviceEndedOn' => $subscription->service_ended_on?->format('Y-m-d'),
                'archiveReason' => $subscription->archive_reason,
                'archiveHistory' => $subscription->archive_history ?? [],
            ],
            'subscriptions' => $this->profileSubscriptions($actor, $subscription),
            'entries' => $entries,
            'summary' => [
                'balance' => $this->money($balanceInCents),
                'charged' => $this->money($sumOf($counted->reject(fn (SubscriptionTransaction $transaction): bool => $transaction->isCredit() || $transaction->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT))),
                'paid' => $this->money(-$sumOf($payments) - $sumOf($paymentAdjustments)),
                'paymentsCount' => $payments->count(),
                'discounted' => $this->money(-$sumOf($discounts)),
                'discountsCount' => $discounts->count(),
                'cleared' => $this->money(-$sumOf($clearings)),
                'clearingsCount' => $clearings->count(),
            ],
            'canRecordPayment' => $actor->can('recordPayment', $subscription),
            'canRecordLatePayment' => $actor->can('recordLatePayment', $subscription),
            'paymentToday' => now(config('app.business_timezone'))->toDateString(),
            'canRecordSplitPayment' => $actor->can('recordSplitPayment', Subscription::class) && $actor->can('recordPayment', $subscription),
            'canAdjustBalance' => $actor->can('adjustBalance', $subscription),
            'canAddCharge' => $actor->can('addCharge', $subscription),
            'canAddDiscount' => $canAddDiscount,
            'canAddClearing' => $actor->can('addClearing', $subscription),
            // Whether the subscriber's own details may be edited (the window's "edit" button, where a page offers it).
            'canUpdate' => $actor->can('update', $subscription),
            'currencies' => Currency::options(),
            'paymentMethods' => PaymentMethod::options(PaymentMethod::offered()),
            // The ways this user may take a payment: what the payment form offers.
            'payableMethods' => PaymentMethod::options($actor->allowedPaymentMethods()),
            'transferBanks' => Bank::receiverNames(),
            'senderBanks' => Bank::senderNames(),
            'chargeTypes' => ChargeType::formOptions(),
            'discountMethods' => DiscountMethod::options(),
            // Offered while typing a standing discount's customer segment.
            'discountSegments' => $canAddDiscount ? StandingDiscount::segmentSuggestions() : [],
            'transactionTypes' => collect(SubscriptionTransaction::typeLabels())
                ->map(fn (string $label, string $type): array => ['value' => $type, 'label' => $label])
                ->values(),
            'correctionReasons' => [
                'payment' => CorrectionReason::options(CorrectionReason::forPaymentCorrection()),
                'adjustment' => CorrectionReason::options(CorrectionReason::forAdjustmentCorrection()),
            ],
        ];
    }

    /**
     * Every subscription of the same person that the actor may see, the
     * open one among them, each with its balance and what the statement
     * window's header shows, so the statement can switch between them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function profileSubscriptions(User $actor, Subscription $subscription): array
    {
        if ($subscription->subscriber_profile_id === null) {
            return [];
        }

        return Subscription::query()
            ->readableTo($actor)
            ->when(! $actor->can('viewArchived', Subscription::class), fn ($query) => $query->whereNull('archived_at'))
            ->where('subscriber_profile_id', $subscription->subscriber_profile_id)
            ->with(['branch.area', 'tariff', 'tariffSegment', 'meterBox.subArea'])
            ->withSum('transactions as balance', 'amount')
            ->orderBy('account_number')
            ->get()
            ->map(fn (Subscription $sibling): array => [
                'id' => $sibling->id,
                'fullName' => $sibling->displayName(),
                'accountNumber' => $sibling->account_number,
                'branchName' => $sibling->branch->name,
                'tariffCategoryLabel' => __($sibling->tariff->category->label()),
                'tariffSegmentName' => $sibling->tariffSegment?->name,
                'meterBoxNumber' => $sibling->meterBox?->box_number,
                'meterBoxName' => $sibling->meterBox?->displayName(),
                'areaName' => $sibling->branch->area?->name,
                'subAreaName' => $sibling->meterBox?->subArea?->name,
                'status' => $sibling->status->value,
                'statusLabel' => $sibling->archived_at !== null ? 'مؤرشف' : __($sibling->status->label()),
                'balance' => $this->money($this->cents((string) ($sibling->balance ?? '0'))),
            ])
            ->all();
    }

    /**
     * Each line's foldable group: the line itself, or for a reversal the
     * line it cancels. This relation does not change chronological display
     * order; it only lets the user manually fold an audit pair.
     *
     * @param  Collection<int, SubscriptionTransaction>  $transactions  oldest first
     * @return array<int, int>
     */
    private function firstLineIds(Collection $transactions): array
    {
        $byId = $transactions->keyBy('id');

        return $transactions
            ->mapWithKeys(fn (SubscriptionTransaction $line): array => [
                $line->id => $line->reverses_id && $byId->has($line->reverses_id) ? (int) $line->reverses_id : $line->id,
            ])
            ->all();
    }

    /**
     * The subscription's standing discount as the statement shows it, or null
     * when they have none.
     *
     * @return array{method: string, value: string, terms: string, segment: ?string, notes: ?string, grantedByName: ?string, grantedAt: string}|null
     */
    private function statementStandingDiscount(?StandingDiscount $discount): ?array
    {
        if ($discount === null) {
            return null;
        }

        return [
            'method' => $discount->method->value,
            'value' => $discount->value,
            'terms' => $discount->terms(),
            'segment' => $discount->segment,
            'notes' => $discount->notes,
            'grantedByName' => $discount->grantedBy?->name,
            'grantedAt' => $this->businessTime($discount->updated_at, 'Y-m-d'),
        ];
    }

    /**
     * One statement line. `balance` is what the subscription owes after it
     * (negative when they are in credit); `amount` is what was charged,
     * handed over or discounted, in the line's own currency. `details` is
     * what the user wrote about it (a reading's notes for a weekly reading).
     * `groupId` is the first line of the chain of corrections it belongs to.
     * A reversal shows the voucher, cash box, bank and reference of the line
     * it cancels, which it has none of its own.
     *
     * @return array<string, mixed>
     */
    private function statementEntry(
        SubscriptionTransaction $transaction,
        int $balanceInCents,
        User $actor,
        int $groupId,
        array $lineNumbers,
        bool $isLastTransaction,
        bool $hasPayment,
        bool $isLastLegacyLine,
    ): array {
        $receipt = $transaction->isReversal() && $transaction->reverses ? $transaction->reverses : $transaction;
        $hasEditPermission = $actor->hasPermission(PermissionKey::CorrectTransactions);
        $hasAmendPermission = $actor->hasPermission(PermissionKey::AmendTransactionDetails);
        $hasDeletePermission = $actor->hasPermission($transaction->isPayment() ? PermissionKey::RefundPayments : PermissionKey::DeleteTransactions);
        $hasForceDeletePermission = $actor->hasPermission(PermissionKey::ForceDeleteTransactions);
        $canAmend = $actor->can('amend', $transaction);
        $canCorrect = $actor->can('update', $transaction);
        $canDelete = $actor->can('delete', $transaction);
        $canForceDelete = $isLastLegacyLine && $actor->can('forceDelete', $transaction);
        $mayCancel = $hasDeletePermission;
        $eraseTarget = $transaction->isReversal() ? $transaction->reverses : $transaction;
        $availableActions = $transaction->availableActions($actor, $isLastTransaction, $hasPayment);
        $linkedReversals = $transaction->linkedReversals
            ->whereIn('type', SubscriptionTransaction::REVERSAL_TYPES)
            ->values();
        $linkedReversal = $linkedReversals->last();
        $treeEffectInCents = $this->cents($transaction->amount)
            + $linkedReversals->sum(fn (SubscriptionTransaction $reversal): int => $this->cents($reversal->amount));
        $refundedInCents = $linkedReversals
            ->where('type', SubscriptionTransaction::TYPE_REFUND)
            ->sum(fn (SubscriptionTransaction $refund): int => abs($this->cents($refund->amount)));
        // Deleting what a correction recorded takes the correction back: the reversal that cancelled the corrected line goes too.
        $correctionReversalInCents = $transaction->corrects_id !== null && ! $transaction->isReversal()
            ? $transaction->corrects?->linkedReversals
                ->where('type', SubscriptionTransaction::TYPE_REVERSAL)
                ->sum(fn (SubscriptionTransaction $reversal): int => $this->cents($reversal->amount)) ?? 0
            : 0;

        return [
            'id' => $transaction->id,
            // A subscriptions row is the individual subscription of a person.
            'subscription_id' => $transaction->subscription_id,
            'branch_id' => $transaction->branch_id,
            'employee_id' => $transaction->employee_id,
            'lineNumber' => $lineNumbers[$transaction->id],
            'groupId' => $groupId,
            'date' => $this->businessTime($transaction->created_at),
            'paidOn' => $transaction->paid_on?->toDateString(),
            'bankReceivedOn' => $transaction->bank_received_on?->toDateString(),
            'lateReason' => $transaction->late_reason,
            'voucherNumber' => $receipt->displayVoucherNumber(),
            'systemVoucherNumber' => $receipt->printedVoucherNumber(),
            'manualVoucherNumber' => $receipt->manual_voucher_number,
            'description' => $transaction->description(),
            'type' => $transaction->type,
            'typeLabel' => $transaction->typeLabel(),
            'status' => $transaction->status,
            'reference_transaction_id' => $transaction->reference_transaction_id,
            'auditOriginal' => $transaction->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT ? ['id' => $transaction->reference_transaction_id, 'lineNumber' => $lineNumbers[$transaction->reference_transaction_id] ?? null] : null,
            'balance_after' => $transaction->balance_after ?? $this->money($balanceInCents),
            'available_actions' => $availableActions,
            'actionEffects' => [
                'delete' => $this->money($this->cents($transaction->amount) + $correctionReversalInCents),
                'delete_reversal' => $transaction->amount,
                'delete_tree' => $this->money($treeEffectInCents),
            ],
            'refundableAmount' => $transaction->isPayment()
                ? $this->money(max(0, abs($this->cents($transaction->amount)) - $refundedInCents))
                : null,
            'isCredit' => $transaction->isCredit(),
            'amount' => $transaction->currency_amount ?? ltrim($transaction->amount, '-'),
            'currencyLabel' => __($transaction->currency->label()),
            'exchangeRate' => rtrim(rtrim($transaction->exchange_rate, '0'), '.'),
            'balance' => $this->money($balanceInCents),
            'paymentMethod' => $transaction->payment_method?->value,
            'paymentMethodLabel' => $transaction->payment_method ? __($transaction->payment_method->label()) : null,
            'bankName' => $receipt->bank_name,
            'senderBankName' => $receipt->sender_bank_name,
            'senderName' => $receipt->sender_name,
            'referenceNumber' => $receipt->reference_number,
            // The bank transfer this payment is one part of, if it is.
            'splitPayment' => $receipt->splitPayment?->badge(),
            // Open membership is provisional; only closure gives the movement its final box number.
            'cashBox' => $receipt->cashBoxNumber(),
            'recordedByName' => $transaction->recordedBy?->name,
            // The weekly reading the line was billed from, which a reading and its standing discount share.
            'meterReadingId' => $transaction->meter_reading_id,
            // The weekly reading a standing line was billed from, to correct from the line's menu;
            // a reading's discount has no menu of its own, as it goes with its reading.
            'reading' => $transaction->type === SubscriptionTransaction::TYPE_READING_DISCOUNT ? null : $this->correctableReading($transaction, $actor),
            // A payment's receipt, to print or reprint from the line's menu.
            'receiptUrl' => (($transaction->isPayment() || $transaction->isClearing()) && (! $actor->hasFinancialAuditAccess() || $actor->canAuditAction(AuditAction::PrintReports))) ? route('subscriptions.payments.receipt', [$transaction->subscription_id, $transaction->id]) : null,
            'details' => match ($transaction->type) {
                SubscriptionTransaction::TYPE_METER_READING => $transaction->notes ?? $transaction->meterReading?->notes,
                // Its customer segment is already in the description.
                SubscriptionTransaction::TYPE_READING_DISCOUNT => null,
                default => $transaction->notes,
            },
            // A reversal, shown indented under the line it cancels.
            'isFollowUp' => $transaction->reverses_id !== null,
            'isReversal' => $transaction->isReversal(),
            'isCorrection' => $transaction->corrects_id !== null,
            'reverses' => $transaction->reverses ? [
                'id' => $transaction->reverses->id,
                'lineNumber' => $lineNumbers[$transaction->reverses->id] ?? null,
            ] : null,
            'linkedReversal' => $linkedReversal ? [
                'id' => $linkedReversal->id,
                'lineNumber' => $lineNumbers[$linkedReversal->id] ?? null,
                'type' => $linkedReversal->type,
            ] : null,
            'linkedReversals' => $linkedReversals->map(fn (SubscriptionTransaction $reversal): array => [
                'id' => $reversal->id,
                'lineNumber' => $lineNumbers[$reversal->id] ?? null,
                'type' => $reversal->type,
                'typeLabel' => $reversal->typeLabel(),
                'description' => $reversal->description(),
                'amount' => SubscriptionTransaction::formatAmount(abs((float) $reversal->amount)),
            ])->all(),
            // The line a replacement corrects, which stays further up the statement.
            'corrects' => $transaction->corrects ? [
                'id' => $transaction->corrects->id,
                'lineNumber' => $lineNumbers[$transaction->corrects->id] ?? null,
                'date' => $this->businessTime($transaction->corrects->created_at),
            ] : null,
            'cancellation' => $transaction->isCancelled() ? [
                'wasCorrected' => $transaction->correction !== null,
                'correctionId' => $transaction->correction?->id,
                'correctionLineNumber' => $transaction->correction ? ($lineNumbers[$transaction->correction->id] ?? null) : null,
                'reasonLabel' => $transaction->cancellation_reason ? __($transaction->cancellation_reason->label()) : 'تصحيح الحركة',
                'notes' => $transaction->cancellation_notes,
                'byName' => $transaction->cancelledBy?->name,
                'at' => $this->businessTime($transaction->cancelled_at),
            ] : null,
            'isAmended' => $transaction->amendments->isNotEmpty(),
            'amendments' => $transaction->amendments->map(fn ($amendment): array => [
                'id' => $amendment->id,
                'userName' => $amendment->user?->name,
                'at' => $this->businessTime($amendment->created_at),
                'reason' => $amendment->reason,
                'changes' => collect($amendment->changes)->map(fn (array $values, string $field): array => [
                    'field' => $field,
                    'label' => $this->amendmentFieldLabel($field),
                    'from' => $values[0] ?? null,
                    'to' => $values[1] ?? null,
                ])->values()->all(),
            ])->values()->all(),
            'canAmend' => $canAmend,
            'canCorrect' => $canCorrect,
            'canDelete' => $canDelete,
            'canForceDelete' => $canForceDelete,
            'amendUnavailableReason' => $hasAmendPermission && ! $canAmend ? ($transaction->amendmentUnavailableReason() ?? 'غير متاح الآن') : null,
            'correctUnavailableReason' => $hasEditPermission && ! $canCorrect
                ? match (true) {
                    $transaction->isCancelled() || $transaction->isReversal() => 'الحركة ملغاة',
                    $transaction->isPayment() && $transaction->isInClosedDay() => 'بعد إغلاق اليوم',
                    default => 'لا ينطبق على هذه الحركة',
                }
                : null,
            'deleteUnavailableReason' => $mayCancel && ! $canDelete
                ? match (true) {
                    $transaction->isCancelled() || $transaction->isReversal() => 'الحركة ملغاة',
                    $transaction->isPayment() && $transaction->isInClosedDay() => 'بعد إغلاق اليوم',
                    default => 'لا يمكن إلغاؤها الآن',
                }
                : null,
            'forceDeleteUnavailableReason' => $hasForceDeletePermission && ! $canForceDelete
                ? match (true) {
                    ! $isLastLegacyLine => 'ليست آخر حركة',
                    $eraseTarget?->closingLine !== null => 'ضمن إغلاق مالي',
                    default => 'لا يمكن حذفها نهائيًا',
                }
                : null,
            // What erasing it takes off the balance: nothing for a reversal or a cancelled line, which go together.
            'eraseEffect' => $transaction->isCancelled() || $transaction->isReversal() ? '0.00' : $transaction->amount,
            'deletionReasons' => $transaction->isCancellable() ? CorrectionReason::options(CorrectionReason::forDeletionOf($transaction)) : [],
            // What the correction form starts from: the line as it was recorded.
            'recorded' => $transaction->isCorrectable()
                ? $this->recordedFields($transaction)
                : ($transaction->isCancellable() ? ['kind' => $transaction->type, 'effect' => $transaction->amount] : null),
        ];
    }

    /**
     * The weekly reading a standing reading or standing-discount line was
     * billed from, as the reading form edits it, for users who record
     * readings. Correcting it is how such a line's amount changes: the line
     * is cancelled with a reversal, and the reading goes back for approval
     * and is billed again (MeterReading::correct). Only the subscription's
     * latest reading, in a week still open to the user, can be corrected.
     *
     * @return array{id: int, weekStart: string, weekEnd: string, previous_reading: float, current_reading: float, consumption: float, notes: ?string, status: string, subscriptionName: string, accountNumber: ?string, canCorrect: bool, canApprove: bool, correctUnavailableReason: ?string}|null
     */
    private function correctableReading(SubscriptionTransaction $transaction, User $actor): ?array
    {
        $reading = $transaction->meterReading;

        if ($reading === null || $transaction->isCancelled() || ! $actor->hasPermission(PermissionKey::RecordMeterReadings)) {
            return null;
        }

        $isLatest = $transaction->subscription->latestMeterReading?->is($reading) ?? false;
        $canCorrect = $isLatest && $actor->can('update', $reading);

        return [
            'id' => $reading->id,
            'weekStart' => $reading->week_start->toDateString(),
            'weekEnd' => $reading->week_end->toDateString(),
            'previous_reading' => $reading->previous_reading,
            'current_reading' => $reading->requires_reentry ? null : $reading->current_reading,
            'requiresReentry' => $reading->requires_reentry,
            'consumption' => $reading->consumption,
            'notes' => $reading->notes,
            'status' => $reading->status->value,
            'subscriptionName' => $transaction->subscription->displayName(),
            'accountNumber' => $transaction->subscription->account_number,
            'canCorrect' => $canCorrect,
            // May approve the corrected reading at once, instead of sending it back for approval.
            'canApprove' => $actor->can('approveAny', MeterReading::class) && ($actor->isSuperAdmin() || $reading->branch_id === $actor->workBranchId()),
            'correctUnavailableReason' => match (true) {
                $canCorrect => null,
                ! $isLatest => 'توجد قراءة لأسبوع لاحق',
                default => 'انتهت فترة تعديل قراءة هذا الأسبوع',
            },
        ];
    }

    private function amendmentFieldLabel(string $field): string
    {
        return match ($field) {
            'amount' => 'المبلغ',
            'bank_name' => 'البنك المستلم',
            'bank_received_on' => 'تاريخ وصول الحوالة إلى البنك',
            'sender_bank_name' => 'البنك المرسل',
            'sender_name' => 'اسم المرسل',
            'reference_number' => 'الرقم المرجعي',
            'notes' => 'الملاحظات',
            default => $field,
        };
    }

    /**
     * A line's own fields, as its payment, charge, discount or clearing
     * form names them, with `effect` — what it did to the balance, in
     * shekels.
     *
     * @return array<string, mixed>
     */
    private function recordedFields(SubscriptionTransaction $transaction): array
    {
        $fields = match (true) {
            $transaction->isPayment() => [
                'kind' => 'payment',
                'amount' => SubscriptionTransaction::formatAmount($transaction->currency_amount),
                'currency' => $transaction->currency->value,
                'exchange_rate' => $transaction->currency === Currency::Shekel ? '' : rtrim(rtrim($transaction->exchange_rate, '0'), '.'),
                'payment_method' => $transaction->payment_method?->value,
                'bank_name' => $transaction->bank_name ?? '',
                'bank_received_on' => $transaction->bank_received_on?->toDateString() ?? '',
                'sender_bank_name' => $transaction->sender_bank_name ?? '',
                'sender_name' => $transaction->sender_name ?? '',
                'reference_number' => $transaction->reference_number ?? '',
                'cash_box' => $transaction->cash_box ?? '',
                'manual_voucher_number' => $transaction->manual_voucher_number ?? '',
            ],
            $transaction->isDiscount() => [
                'kind' => 'discount',
                'method' => $transaction->discount_method?->value,
                'value' => $transaction->discount_value,
            ],
            $transaction->isClearing() => [
                'kind' => 'clearing',
                'amount' => SubscriptionTransaction::formatAmount(ltrim($transaction->amount, '-')),
                'manual_voucher_number' => $transaction->manual_voucher_number ?? '',
            ],
            default => [
                'kind' => 'charge',
                'type' => $transaction->type,
                'amount' => SubscriptionTransaction::formatAmount(ltrim($transaction->amount, '-')),
            ],
        };

        return [...$fields, 'notes' => $transaction->notes ?? '', 'effect' => $transaction->amount];
    }

    /**
     * A stored (UTC) moment as the business's clock shows it, as receipts,
     * the financial log and the audit log show it too.
     */
    private function businessTime(CarbonInterface $moment, string $format = 'Y-m-d H:i'): string
    {
        return $moment->copy()->setTimezone(config('app.business_timezone'))->format($format);
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
