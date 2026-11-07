<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:40 on 30 August in Gaza (UTC+3), the business's time zone.
        $this->travelTo('2026-08-30 09:40:00');
        $this->branch = Branch::factory()->create(['name' => 'فرع الكرادة']);
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id, 'name' => 'Mohammed']);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $payment = $this->recordPayment();

        $this->get(route('subscriptions.payments.receipt', [$this->subscription, $payment]))->assertRedirect(route('login'));
    }

    public function test_the_receipt_shows_the_voucher_the_payer_the_amount_and_the_balance_it_left(): void
    {
        SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '100', null);
        $payment = $this->recordPayment(['amount' => '20', 'currency' => 'USD', 'exchange_rate' => '3.7', 'cash_box' => '3', 'manual_voucher_number' => '4471']);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.payments.receipt', [$this->subscription, $payment]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/PaymentReceipt')
                ->where('subscription.fullName', 'Ahmad')
                ->where('subscription.accountNumber', $this->subscription->account_number)
                ->where('subscription.branchName', 'فرع الكرادة')
                ->where('receipt.voucherNumber', '4471')
                ->where('receipt.systemVoucherNumber', '000001')
                ->where('receipt.date', '2026-08-30')
                ->where('receipt.time', '12:40')
                ->where('receipt.amount', '20')
                ->where('receipt.currencyLabel', __('Dollar'))
                ->where('receipt.isShekel', false)
                ->where('receipt.inShekels', '74')
                ->where('receipt.exchangeRate', '3.7')
                ->where('receipt.cashBox', null)
                ->where('receipt.recordedByName', 'Mohammed')
                ->where('receipt.balanceAfter', '26.00')
                ->where('receipt.cancellation', null)
                ->where('printedBy', 'Mohammed'));
    }

    public function test_a_cancelled_payment_prints_marked_as_cancelled(): void
    {
        $payment = $this->recordPayment();
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'مكررة'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.payments.receipt', [$this->subscription, $payment]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receipt.cancellation.reasonLabel', __(CorrectionReason::Duplicate->label()))
                ->where('receipt.cancellation.byName', 'Mohammed')
                ->where('receipt.cancellation.date', '2026-08-30'));
    }

    public function test_staff_of_another_branch_cannot_open_the_receipt(): void
    {
        $payment = $this->recordPayment();
        $otherAdmin = User::factory()->branchAdmin()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($otherAdmin)->get(route('subscriptions.payments.receipt', [$this->subscription, $payment]))->assertForbidden();
    }

    public function test_only_a_payment_of_the_same_subscription_has_a_receipt(): void
    {
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '100', null);
        $otherSubscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $otherPayment = SubscriptionTransaction::recordPayment($otherSubscription, $this->branchAdmin, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)->get(route('subscriptions.payments.receipt', [$this->subscription, $charge]))->assertNotFound();
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.payments.receipt', [$this->subscription, $otherPayment]))->assertNotFound();
    }

    public function test_a_recorded_payment_hands_its_form_the_receipt_to_print(): void
    {
        $this->followingRedirects()
            ->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->post(route('subscriptions.payments.store', $this->subscription), ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->hasFlash(
                'recordedPayment.receiptUrl',
                route('subscriptions.payments.receipt', [$this->subscription, SubscriptionTransaction::sole()]),
            ));
    }

    public function test_the_statement_offers_a_receipt_on_payment_lines_only(): void
    {
        SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '100', null);
        $payment = $this->recordPayment();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.receiptUrl', null)
                ->where('entries.1.receiptUrl', route('subscriptions.payments.receipt', [$this->subscription, $payment])));
    }

    /**
     * @param  array<string, string>  $details
     */
    private function recordPayment(array $details = []): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, [
            'amount' => '25',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            ...$details,
        ]);
    }
}
