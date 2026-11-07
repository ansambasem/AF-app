<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Models\Branch;
use App\Models\MobileAccessToken;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OverpaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * What a payment of this size needs against a debt of 250 shekels:
     * nothing up to double the debt, a confirmation beyond it.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function paymentsAgainstADebtOf250(): array
    {
        return [
            'less than the debt' => ['100', false],
            'exactly the debt' => ['250', false],
            'a little more than the debt' => ['300', false],
            'double the debt' => ['500', false],
            'over double the debt but only a little over it' => ['700', false],
            'over double the debt and 500 over it' => ['750', true],
            'twenty times the debt (a slip for 250?)' => ['5000', true],
        ];
    }

    #[DataProvider('paymentsAgainstADebtOf250')]
    public function test_a_payment_far_above_the_debt_needs_confirming_on_the_website(string $amount, bool $needsConfirmation): void
    {
        $this->owe('250');

        $response = $this->actingAs($this->admin)->post(route('subscriptions.payments.store', $this->subscription), $this->payment($amount));

        if ($needsConfirmation) {
            $response->assertSessionHasErrors('confirm_overpayment');
            $this->assertSame(0, SubscriptionTransaction::where('type', 'payment')->count());
            $this->post(route('subscriptions.payments.store', $this->subscription), [...$this->payment($amount), 'confirm_overpayment' => true])->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasNoErrors();
        }

        $this->assertSame(1, SubscriptionTransaction::where('type', 'payment')->count());
    }

    public function test_the_refusal_says_what_is_owed_and_what_would_be_left_in_credit(): void
    {
        $this->owe('250');

        $this->actingAs($this->admin)->post(route('subscriptions.payments.store', $this->subscription), $this->payment('5000'))
            ->assertSessionHasErrors(['confirm_overpayment' => 'المبلغ 5000 ₪ أكبر بكثير من المستحق على المشترك (250 ₪)، وسيبقى له رصيد دائن قدره 4750 ₪؛ تأكد من المبلغ ثم أكّد أنه صحيح.']);
    }

    public function test_a_small_excess_over_the_debt_never_needs_confirming(): void
    {
        $this->owe('20');

        // Twenty times the debt, but only 400 shekels over it.
        $this->actingAs($this->admin)->post(route('subscriptions.payments.store', $this->subscription), $this->payment('420'))->assertSessionHasNoErrors();
    }

    public function test_a_subscription_with_no_debt_can_pay_a_little_ahead_but_not_a_lot(): void
    {
        // A week's minimum runs up to 320 shekels, so a collector may well take a couple of weeks ahead.
        $this->actingAs($this->admin)->post(route('subscriptions.payments.store', $this->subscription), $this->payment('499'))->assertSessionHasNoErrors();
        $this->post(route('subscriptions.payments.store', $this->subscription), $this->payment('500'))->assertSessionHasErrors('confirm_overpayment');
    }

    public function test_correcting_a_payment_is_checked_against_what_is_owed_without_it(): void
    {
        $this->owe('250');
        $payment = SubscriptionTransaction::recordPayment($this->subscription, $this->admin, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $correct = fn (array $extra = []) => $this->actingAs($this->admin)->put(route('subscriptions.transactions.update', [$this->subscription, $payment]), [
            ...$this->payment('5000'), 'correction_reason' => 'wrong_amount', 'correction_notes' => 'x', ...$extra,
        ]);

        $correct()->assertSessionHasErrors('confirm_overpayment');
        $this->assertFalse($payment->fresh()->isCancelled());

        $correct(['confirm_overpayment' => true])->assertSessionHasNoErrors();
        $this->assertTrue($payment->fresh()->isCancelled());
    }

    public function test_the_app_gets_the_same_refusal_and_can_confirm(): void
    {
        $this->owe('250');
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $payload = [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $this->subscription->id,
            'amount' => '5000', 'currency' => 'ILS', 'payment_method' => 'cash', 'collector_confirmed' => true,
        ];

        $this->postJson(route('mobile.collections.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('confirm_overpayment');
        $this->assertSame(0, SubscriptionTransaction::where('type', 'payment')->count());

        $this->postJson(route('mobile.collections.store'), [...$payload, 'confirm_overpayment' => true])->assertCreated();
        // A retry of the recorded operation is simply answered again.
        $this->postJson(route('mobile.collections.store'), $payload)->assertCreated();
        $this->assertSame(1, SubscriptionTransaction::where('type', 'payment')->count());
    }

    public function test_an_ordinary_payment_in_the_app_needs_nothing_extra(): void
    {
        $this->owe('250');
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => Str::uuid()->toString(), 'subscription_id' => $this->subscription->id,
            'amount' => '300', 'currency' => 'ILS', 'payment_method' => 'cash', 'collector_confirmed' => true,
        ])->assertCreated();
    }

    private function owe(string $amount): void
    {
        SubscriptionTransaction::recordCharge($this->subscription, $this->admin, ChargeType::Penalty, $amount, 'غرامة');
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $amount): array
    {
        return ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash'];
    }
}
