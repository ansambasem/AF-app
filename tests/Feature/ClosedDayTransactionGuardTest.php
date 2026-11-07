<?php

namespace Tests\Feature;

use App\Enums\CorrectionReason;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\ClosingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClosedDayTransactionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    private int $closingDays = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '200.00']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedClosingStates(): array
    {
        return ['submitted' => ['submitted'], 'approved' => ['approved']];
    }

    #[DataProvider('lockedClosingStates')]
    public function test_a_payment_in_a_submitted_or_approved_closing_cannot_be_corrected_through_the_legacy_route(string $state): void
    {
        $payment = $this->paymentInClosing($state);

        $this->actingAs($this->branchAdmin)
            ->put(route('subscriptions.transactions.update', [$this->subscription, $payment]), [
                'amount' => '1',
                'currency' => 'ILS',
                'payment_method' => 'cash',
                'correction_reason' => 'wrong_amount',
                'correction_notes' => 'x',
            ])
            ->assertForbidden();

        $this->assertStandingAt($payment, '-100.00');
    }

    #[DataProvider('lockedClosingStates')]
    public function test_a_payment_in_a_submitted_or_approved_closing_cannot_be_cancelled_through_the_legacy_route(string $state): void
    {
        $payment = $this->paymentInClosing($state);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'x'])
            ->assertForbidden();

        $this->assertStandingAt($payment, '-100.00');
    }

    #[DataProvider('lockedClosingStates')]
    public function test_the_model_refuses_to_cancel_or_correct_a_payment_in_a_locked_closing(string $state): void
    {
        $payment = $this->paymentInClosing($state);

        try {
            $payment->cancel($this->branchAdmin, CorrectionReason::Duplicate, 'x');
            $this->fail('Cancelling a payment in a locked closing should be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['reason'], array_keys($exception->errors()));
        }

        $this->assertStandingAt($payment, '-100.00');
    }

    public function test_the_statement_offers_no_correct_or_cancel_for_a_payment_in_a_locked_closing(): void
    {
        $locked = $this->paymentInClosing('approved');
        $open = $this->paymentInClosing('draft');

        $this->actingAs($this->branchAdmin)->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries', fn ($entries): bool => collect($entries)->firstWhere('id', $locked->id)['canCorrect'] === false
                    && collect($entries)->firstWhere('id', $locked->id)['canDelete'] === false
                    && collect($entries)->firstWhere('id', $open->id)['canCorrect'] === true
                    && collect($entries)->firstWhere('id', $open->id)['canDelete'] === true));
    }

    public function test_a_payment_in_a_draft_closing_can_still_be_cancelled_and_leaves_the_closing(): void
    {
        $payment = $this->paymentInClosing('draft');

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($payment->refresh()->isCancelled());
    }

    private function paymentInClosing(string $state): SubscriptionTransaction
    {
        $payment = SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, [
            'amount' => '100',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);
        $closing = ($state === 'draft' ? Closing::factory() : Closing::factory()->{$state}())
            ->forDay(now()->subDays(++$this->closingDays)->toDateString())
            ->create(['branch_id' => $this->branch->id]);
        ClosingPayment::create(['closing_id' => $closing->id, 'subscription_transaction_id' => $payment->id]);

        return $payment;
    }

    private function assertStandingAt(SubscriptionTransaction $payment, string $amount): void
    {
        $payment->refresh();

        $this->assertFalse($payment->isCancelled());
        $this->assertSame($amount, $payment->amount);
        $this->assertSame(0, SubscriptionTransaction::where('reverses_id', $payment->id)->count());
        $this->assertSame(1, SubscriptionTransaction::where('type', 'payment')->count());
    }
}
