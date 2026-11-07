<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionChargesAndDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
    }

    public function test_a_charge_raises_the_balance_and_shows_its_type_and_details_on_the_statement(): void
    {
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), ['type' => 'penalty', 'amount' => '50', 'notes' => 'تأخر في السداد'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'charge-recorded')
            ->assertRedirect(route('subscriptions.statement', $this->subscription));

        $charge = SubscriptionTransaction::sole();
        $this->assertSame(['penalty', '50.00', 'تأخر في السداد'], [$charge->type, $charge->amount, $charge->notes]);
        $this->assertSame(
            ['action' => 'charge-recorded', 'subject' => 'Ahmad — غرامة مالية 50 شيكل'],
            $this->branchAdmin->notifications()->sole()->data,
        );

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.typeLabel', 'غرامة مالية')
                ->where('entries.0.description', 'غرامة مالية')
                ->where('entries.0.details', 'تأخر في السداد')
                ->where('entries.0.isCredit', false)
                ->where('summary.balance', '50.00')
                ->where('canAdjustBalance', true));
    }

    public function test_a_penalty_must_say_why_and_the_form_suggests_the_usual_disconnection_fee(): void
    {
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), ['type' => 'penalty', 'amount' => '50', 'notes' => ''])
            ->assertSessionHasErrors(['notes' => 'اكتب سبب الغرامة؛ يظهر في كشف حساب المشترك.']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), ['type' => 'disconnection_fee', 'amount' => '50'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->has('chargeTypes', 4)
                ->where('chargeTypes.0', ['value' => 'penalty', 'label' => 'غرامة مالية', 'usualAmount' => null, 'needsReason' => true])
                ->where('chargeTypes.1', ['value' => 'disconnection_fee', 'label' => 'رسوم قطع الخدمة', 'usualAmount' => 20, 'needsReason' => false])
                ->where('chargeTypes.2', ['value' => 'subscription_fee', 'label' => 'رسوم اشتراك', 'usualAmount' => null, 'needsReason' => false])
                ->where('chargeTypes.3', ['value' => 'minimum_charge', 'label' => 'تحميل الحد الأدنى على المشترك', 'usualAmount' => null, 'needsReason' => false]));
    }

    public function test_the_subscribers_minimum_is_recorded_as_a_separate_charge_with_its_own_statement_label(): void
    {
        $this->subscription->update(['minimum_charge' => '12.50']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '12.5', 'notes' => 'تحميل يدوي بطلب الإدارة',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'charge-recorded');

        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $this->subscription->id, 'recorded_by' => $this->branchAdmin->id,
            'type' => 'minimum_charge', 'amount' => '12.50', 'notes' => 'تحميل يدوي بطلب الإدارة',
        ]);
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertSame(12.5, $this->subscription->fresh()->minimum_charge);
        $this->assertSame('charge-recorded', $this->branchAdmin->notifications()->sole()->data['action']);
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.statement', $this->subscription))->assertInertia(fn ($page) => $page
            ->where('entries.0.typeLabel', 'تحميل الحد الأدنى على المشترك')
            ->where('entries.0.isCredit', false)->where('summary.balance', '12.50')->where('summary.charged', '12.50')
            ->where('subscription.minimumPayment', '12.5'));
    }

    public function test_a_minimum_charge_uses_the_breakers_minimum_when_no_subscriber_override_exists(): void
    {
        $breaker = CircuitBreaker::factory()->create(['minimum_payment' => '18.75']);
        $this->subscription->update(['minimum_charge' => null, 'circuit_breaker_id' => $breaker->id]);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '18.75',
        ])->assertSessionHasNoErrors();
        $this->assertSame('18.75', $this->latestTransaction()->amount);
    }

    public function test_a_different_or_stale_minimum_amount_is_refused_without_creating_a_charge(): void
    {
        $this->subscription->update(['minimum_charge' => '25']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '20',
        ])->assertSessionHasErrors(['amount' => 'يجب أن يساوي المبلغ الحد الأدنى الحالي للمشترك. حدّث الصفحة وحاول مجددًا.']);
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertSame(0, $this->branchAdmin->notifications()->count());
    }

    public function test_a_subscriber_with_no_positive_minimum_cannot_receive_a_minimum_charge(): void
    {
        $this->subscription->update(['minimum_charge' => '0']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '10',
        ])->assertSessionHasErrors(['amount' => 'لا يوجد حد أدنى موجب محدد لهذا المشترك.']);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_unlocking_allows_a_custom_charge_amount_without_changing_the_saved_minimum(): void
    {
        $this->subscription->update(['minimum_charge' => '25']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '18.50', 'override_minimum_amount' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'charge-recorded');

        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $this->subscription->id, 'type' => 'minimum_charge', 'amount' => '18.50',
        ]);
        $this->assertDatabaseHas('subscriptions', ['id' => $this->subscription->id, 'minimum_charge' => 25]);
        $this->assertSame(18.5, $this->subscription->balance());
        $this->assertSame('charge-recorded', $this->branchAdmin->notifications()->sole()->data['action']);
    }

    public function test_a_locked_minimum_charge_refuses_a_custom_amount_and_an_invalid_unlock_value(): void
    {
        $this->subscription->update(['minimum_charge' => '25']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '18.50', 'override_minimum_amount' => false,
        ])->assertSessionHasErrors('amount');
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '18.50', 'override_minimum_amount' => 'invalid',
        ])->assertSessionHasErrors('override_minimum_amount');
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_unlocking_still_requires_charge_permission_and_a_positive_amount(): void
    {
        $this->subscription->update(['minimum_charge' => '25']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '-10', 'override_minimum_amount' => true,
        ])->assertSessionHasErrors('amount');
        $dataEntry = User::factory()->dataEntry()->for($this->branch)->create();
        $this->postAs($dataEntry, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '18.50', 'override_minimum_amount' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_the_minimum_charge_keeps_existing_charge_permissions_and_branch_restrictions(): void
    {
        $this->subscription->update(['minimum_charge' => '20']);
        $dataEntry = User::factory()->dataEntry()->for($this->branch)->create();
        $foreign = User::factory()->branchAdmin()->create();
        $payload = ['type' => 'minimum_charge', 'amount' => '20'];
        $this->postAs($dataEntry, route('subscriptions.charges.store', $this->subscription), $payload)->assertForbidden();
        $this->postAs($foreign, route('subscriptions.charges.store', $this->subscription), $payload)->assertForbidden();
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_a_minimum_charge_can_be_cancelled_using_the_existing_charge_workflow(): void
    {
        $this->subscription->update(['minimum_charge' => '20']);
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'minimum_charge', 'amount' => '20',
        ])->assertSessionHasNoErrors();
        $charge = $this->latestTransaction();
        $this->postAs($this->branchAdmin, route('subscriptions.charges.store', $this->subscription), [
            'type' => 'disconnection_fee', 'amount' => '10',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->branchAdmin)->post(route('subscriptions.transactions.actions.store', [$this->subscription, $charge]), [
            'action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'تحميل مكرر',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $charge->fresh()->status);
        $this->assertSame(10.0, $this->subscription->balance());
    }

    public function test_a_clearing_takes_the_value_of_the_subscriptions_service_off_what_they_owe(): void
    {
        $this->subscriptionOwes('100.00');

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '40', 'notes' => 'صيانة المولد'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'clearing-recorded');

        $clearing = $this->latestTransaction();
        $this->assertSame(['clearing', '-40.00', 'صيانة المولد'], [$clearing->type, $clearing->amount, $clearing->notes]);
        $this->assertSame(
            ['action' => 'clearing-recorded', 'subject' => 'Ahmad — مقاصة 40 شيكل'],
            $this->branchAdmin->notifications()->sole()->data,
        );

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.typeLabel', 'مقاصة')
                ->where('entries.1.description', 'مقاصة مقابل خدمة للشركة')
                ->where('entries.1.details', 'صيانة المولد')
                ->where('entries.1.isCredit', true)
                ->where('entries.1.recorded.kind', 'clearing')
                ->where('summary.balance', '60.00')
                ->where('summary.charged', '100.00')
                ->where('summary.paid', '0.00')
                ->where('summary.cleared', '40.00')
                ->where('summary.clearingsCount', 1));
    }

    public function test_a_clearing_gets_a_voucher_like_a_cash_payment_and_keeps_its_paper_voucher(): void
    {
        $this->subscriptionOwes('100.00');
        $payment = SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '40', 'notes' => 'صيانة المولد', 'manual_voucher_number' => 'M-77'])
            ->assertSessionHasNoErrors();

        $clearing = $this->latestTransaction();
        $this->assertSame($payment->voucher_number + 1, $clearing->voucher_number);
        $this->assertSame('M-77', $clearing->manual_voucher_number);
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.payments.receipt', [$this->subscription, $clearing]))
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/PaymentReceipt')
                ->where('receipt.isClearing', true)
                ->where('receipt.voucherNumber', 'M-77')
                ->where('receipt.systemVoucherNumber', $clearing->printedVoucherNumber()));
    }

    public function test_a_clearing_worth_more_than_the_subscription_owes_is_saved_only_once_confirmed(): void
    {
        $this->subscriptionOwes('30.00');

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '50', 'notes' => 'تأجير السطح'])
            ->assertSessionHasErrors(['confirm_over_balance' => 'قيمة المقاصة (50 شيكل) أكبر من الرصيد المستحق (30 شيكل)، فيصبح للمشترك رصيد لصالحه قدره 20 شيكل. أكّد إذا كنت تريد فعلًا إضافة المقاصة لهذا المشترك.']);
        $this->assertDatabaseMissing('subscription_transactions', ['type' => 'clearing']);

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '50', 'notes' => 'تأجير السطح', 'confirm_over_balance' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(-20.0, $this->subscription->balance());
    }

    public function test_a_large_clearing_for_a_subscription_who_owes_nothing_is_allowed_once_confirmed(): void
    {
        $this->subscriptionOwes('30.00');

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '30', 'notes' => 'تأجير السطح'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->subscription->balance());

        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '2000', 'notes' => 'تأجير السطح لسنة'])
            ->assertSessionHasErrors('confirm_over_balance');
        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '2000', 'notes' => 'تأجير السطح لسنة', 'confirm_over_balance' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(-2000.0, $this->subscription->balance());
    }

    public function test_a_clearing_must_name_the_service_and_its_value(): void
    {
        $this->postAs($this->branchAdmin, route('subscriptions.clearings.store', $this->subscription), ['amount' => '0', 'notes' => ''])
            ->assertSessionHasErrors(['amount', 'notes' => 'اكتب الخدمة التي قدّمها المشترك؛ تظهر في كشف حسابه.']);

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_a_shekel_discount_lowers_the_balance_and_is_listed_as_a_discount(): void
    {
        $this->subscriptionOwes('100.00');

        $this->discount('shekel', '30')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'discount-recorded');

        $this->assertSame('-30.00', $this->latestTransaction()->amount);
        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.typeLabel', 'خصم')
                ->where('entries.1.description', 'خصم لمرة واحدة · مبلغ ثابت')
                ->where('entries.1.isCredit', true)
                ->where('summary.balance', '70.00')
                ->where('summary.charged', '100.00')
                ->where('summary.discounted', '30.00'));
    }

    /**
     * Percentages and free kilowatts belong to the weekly readings discount.
     */
    #[TestWith(['percentage', '10'])]
    #[TestWith(['kilowatt', '25'])]
    public function test_a_one_off_discount_is_given_in_shekels_only(string $method, string $value): void
    {
        $this->subscriptionOwes('100.00');

        $this->discount($method, $value)
            ->assertSessionHasErrors(['method' => 'الخصم لمرة واحدة يكون بالشيكل فقط.']);

        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_a_discount_cannot_be_more_than_the_subscription_owes(): void
    {
        $this->discount('shekel', '5')
            ->assertSessionHasErrors(['value' => 'لا يوجد رصيد مستحق على المشترك ليُخصم منه.']);

        $this->subscriptionOwes('20.00');

        $this->discount('shekel', '30')
            ->assertSessionHasErrors(['value' => 'لا يمكن أن يزيد الخصم (30 شيكل) عن الرصيد المستحق (20 شيكل).']);
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    /**
     * @param  array<string, string>  $input
     */
    #[TestWith(['charges', ['type' => 'bonus', 'amount' => '10'], 'type'])]
    #[TestWith(['charges', ['type' => 'settlement', 'amount' => '10'], 'type'])]
    #[TestWith(['charges', ['type' => 'disconnection_fee', 'amount' => '0'], 'amount'])]
    #[TestWith(['discounts', ['method' => 'shekel', 'value' => '0'], 'value'])]
    #[TestWith(['discounts', ['method' => 'coupon', 'value' => '10'], 'method'])]
    public function test_an_invalid_charge_or_discount_is_rejected(string $kind, array $input, string $field): void
    {
        $this->subscriptionOwes('100.00');

        $this->postAs($this->branchAdmin, route("subscriptions.{$kind}.store", $this->subscription), $input)
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_charges_discounts_and_clearings_take_their_own_permission_within_the_branch(): void
    {
        $this->subscriptionOwes('100.00');
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $accountant = User::factory()->accountant()->create(['branch_id' => $this->branch->id]);
        $clearing = ['amount' => '10', 'notes' => 'صيانة'];

        $this->postAs($dataEntry, route('subscriptions.charges.store', $this->subscription), ['type' => 'penalty', 'amount' => '10', 'notes' => 'x'])->assertForbidden();
        $this->postAs($dataEntry, route('subscriptions.clearings.store', $this->subscription), $clearing)->assertForbidden();
        $this->postAs(User::factory()->branchAdmin()->create(), route('subscriptions.discounts.store', $this->subscription), ['method' => 'shekel', 'value' => '10'])
            ->assertForbidden();
        $this->postAs(User::factory()->branchAdmin()->create(), route('subscriptions.clearings.store', $this->subscription), $clearing)->assertForbidden();
        $this->postAs($accountant, route('subscriptions.charges.store', $this->subscription), ['type' => 'penalty', 'amount' => '10', 'notes' => 'x'])->assertSessionHasNoErrors();
        $this->postAs($accountant, route('subscriptions.clearings.store', $this->subscription), $clearing)->assertSessionHasNoErrors();

        $this->actingAs($dataEntry)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('canAdjustBalance', false));
        $this->assertDatabaseCount('subscription_transactions', 3);
    }

    /**
     * POST as the given user, coming from the subscription's statement.
     *
     * @param  array<string, mixed>  $data
     */
    private function postAs(User $user, string $uri, array $data): TestResponse
    {
        return $this->actingAs($user)->from(route('subscriptions.statement', $this->subscription))->post($uri, $data);
    }

    private function discount(string $method, string $value): TestResponse
    {
        return $this->postAs($this->branchAdmin, route('subscriptions.discounts.store', $this->subscription), ['method' => $method, 'value' => $value]);
    }

    private function subscriptionOwes(string $amount): void
    {
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => $amount]);
    }

    private function latestTransaction(): SubscriptionTransaction
    {
        return SubscriptionTransaction::latest('id')->first();
    }
}
