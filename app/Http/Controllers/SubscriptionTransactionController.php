<?php

namespace App\Http\Controllers;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Enums\DiscountMethod;
use App\Http\Requests\AmendSubscriptionTransactionRequest;
use App\Http\Requests\ApplySubscriptionTransactionActionRequest;
use App\Http\Requests\CorrectSubscriptionTransactionRequest;
use App\Http\Requests\DeleteSubscriptionTransactionRequest;
use App\Http\Requests\ForceDeleteSubscriptionTransactionRequest;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Amending details, correcting money, cancelling or permanently erasing a
 * subscription transaction. Each action preserves the financial audit trail
 * appropriate to what changed.
 */
class SubscriptionTransactionController extends Controller
{
    /** Apply one of the five canonical ledger actions. */
    public function apply(
        ApplySubscriptionTransactionActionRequest $request,
        Subscription $subscription,
        SubscriptionTransaction $transaction,
    ): RedirectResponse {
        $action = $request->action();
        $summary = sprintf('%s — %s', $subscription->displayName(), $transaction->description());

        $transaction->applyAction($request->user(), $action, $request->validated());

        $request->user()->notify(new ActionCompleted('transaction-'.$action->value, $summary));

        return back()->with('status', 'transaction-'.$action->value);
    }

    /** Amend non-financial payment details in place and keep their history. */
    public function amend(AmendSubscriptionTransactionRequest $request, Subscription $subscription, SubscriptionTransaction $transaction): RedirectResponse
    {
        if ($transaction->split_payment_id !== null) {
            $transaction->splitPayment->revise($request->user(), $request->validated());
        } else {
            $transaction->amend(
                $request->user(),
                $request->safe()->except(['amendment_reason']),
                $request->validated('amendment_reason'),
            );
        }

        $request->user()->notify(new ActionCompleted(
            'transaction-amended',
            sprintf('%s — دفعة%s', $subscription->displayName(), $transaction->printedVoucherNumber() ? ' · السند '.$transaction->printedVoucherNumber() : ''),
        ));

        return back()->with('status', 'transaction-amended');
    }

    /**
     * Correct the line: cancel it and record the right one. A corrected
     * payment gets a new voucher number, shown on its receipt with the
     * balance it left.
     */
    public function update(CorrectSubscriptionTransactionRequest $request, Subscription $subscription, SubscriptionTransaction $transaction): RedirectResponse
    {
        $actor = $request->user();
        $details = $request->safe()->except(['correction_reason', 'correction_notes']);

        // A corrected part of a split transfer stays a part of it, so it still shares the transfer's reference.
        if ($transaction->isPayment() && $transaction->split_payment_id !== null
            && SubscriptionTransaction::normalizeReference($details['reference_number'] ?? null) === SubscriptionTransaction::normalizeReference($transaction->reference_number)) {
            $details['split_payment_id'] = $transaction->split_payment_id;
        }

        $replacement = $transaction->correct(
            $actor,
            CorrectionReason::from($request->validated('correction_reason')),
            $request->validated('correction_notes'),
            fn (Subscription $subscription): SubscriptionTransaction => match (true) {
                $transaction->isPayment() => SubscriptionTransaction::recordPayment($subscription, $actor, $details),
                $transaction->isDiscount() => SubscriptionTransaction::recordDiscount($subscription, $actor, DiscountMethod::from($details['method']), $details['value'], $details['notes'] ?? null),
                $transaction->isClearing() => SubscriptionTransaction::recordClearing($subscription, $actor, $details['amount'], $details['notes'], $details['manual_voucher_number'] ?? null),
                default => SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::from($details['type']), $details['amount'], $details['notes'] ?? null),
            },
        );

        $actor->notify(new ActionCompleted(
            'transaction-corrected',
            sprintf('%s — %s %s شيكل', $subscription->displayName(), $replacement->typeLabel(), SubscriptionTransaction::formatAmount(ltrim($replacement->amount, '-'))),
        ));

        if ($replacement->isPayment()) {
            Inertia::flash('recordedPayment', [
                'amount' => $replacement->currency_amount,
                'currency' => $replacement->currency->value,
                'inShekels' => ltrim($replacement->amount, '-'),
                'voucherNumber' => $replacement->printedVoucherNumber(),
                'balance' => number_format($subscription->balance(), 2, '.', ''),
                'receiptUrl' => route('subscriptions.payments.receipt', [$subscription, $replacement]),
            ]);
        }

        return back()->with('status', 'transaction-corrected');
    }

    /**
     * Cancel the line, and add the reversal that takes its
     * amount back off the balance.
     */
    public function destroy(DeleteSubscriptionTransactionRequest $request, Subscription $subscription, SubscriptionTransaction $transaction): RedirectResponse
    {
        $transaction->cancel(
            $request->user(),
            CorrectionReason::from($request->validated('correction_reason')),
            $request->validated('correction_notes'),
            $request->boolean('reopen_reading'),
        );

        $request->user()->notify(new ActionCompleted(
            'transaction-deleted',
            sprintf('%s — %s %s شيكل', $subscription->displayName(), $transaction->typeLabel(), SubscriptionTransaction::formatAmount(ltrim($transaction->amount, '-'))),
        ));

        return back()->with('status', 'transaction-deleted');
    }

    /**
     * Erase the line for good: nothing is kept on the statement, and the
     * balance is as if it was never recorded.
     */
    public function forceDestroy(ForceDeleteSubscriptionTransactionRequest $request, Subscription $subscription, SubscriptionTransaction $transaction): RedirectResponse
    {
        $summary = sprintf('%s — %s %s شيكل', $subscription->displayName(), $transaction->typeLabel(), SubscriptionTransaction::formatAmount(ltrim($transaction->amount, '-')));

        $transaction->erase($request->user(), $request->validated('correction_notes'));

        $request->user()->notify(new ActionCompleted('transaction-erased', $summary));

        return back()->with('status', 'transaction-erased');
    }
}
