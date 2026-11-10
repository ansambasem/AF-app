<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A paper voucher number is text, kept exactly as the collector wrote it
 * from the voucher book: `5` and `000005` are two numbers. The same number
 * twice in one branch is refused, by the website, the Android app and the
 * database alike; another branch may use it. A payment with no number keeps
 * none: the system makes none up.
 */
class CashVoucherPerBranchTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $inA;

    private Subscription $inB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        [$branchA, $branchB] = Branch::factory()->count(2)->create();
        $this->adminA = User::factory()->branchAdmin()->create(['branch_id' => $branchA->id]);
        $this->adminB = User::factory()->branchAdmin()->create(['branch_id' => $branchB->id]);
        $this->inA = Subscription::factory()->create(['branch_id' => $branchA->id]);
        $this->inB = Subscription::factory()->create(['branch_id' => $branchB->id]);
    }

    public function test_5_and_000005_are_two_numbers_each_used_once_in_a_branch_and_again_in_another(): void
    {
        $this->pay($this->inA, '5')->assertSessionHasNoErrors();                                 // 1
        $this->pay($this->inA, '000005')->assertSessionHasNoErrors();                            // 2
        $this->pay($this->inA, '5')->assertSessionHasErrors('manual_voucher_number');            // 3
        $this->pay($this->inA, '000005')->assertSessionHasErrors(['manual_voucher_number' => $this->taken('000005')]); // 4
        $this->pay($this->inB, '5', $this->adminB)->assertSessionHasNoErrors();                  // 5

        $this->assertSame(['5', '000005'], $this->vouchers($this->inA));
        $this->assertSame(['5'], $this->vouchers($this->inB));
    }

    public function test_the_android_app_follows_the_same_rule(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->inA->branch_id]);
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments]));
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $collect = fn (string $voucher): TestResponse => $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true, 'subscription_id' => $this->inA->id,
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => $voucher,
        ]);

        $collect('000005')->assertCreated()->assertJsonPath('voucher_number', '000005');
        $collect('5')->assertCreated();
        $collect('000005')->assertUnprocessable()->assertJsonValidationErrors(['manual_voucher_number' => $this->taken('000005')]);
        $collect('5')->assertUnprocessable()->assertJsonValidationErrors('manual_voucher_number');

        $this->assertSame(['000005', '5'], $this->vouchers($this->inA));
    }

    public function test_000236_and_236_are_both_accepted_in_one_branch(): void
    {
        $this->pay($this->inA, '000236')->assertSessionHasNoErrors();
        $this->pay($this->inA, '236')->assertSessionHasNoErrors();

        $this->assertSame(['000236', '236'], $this->vouchers($this->inA));
    }

    public function test_payments_without_a_voucher_keep_none_and_any_number_of_them_are_accepted(): void
    {
        $this->pay($this->inA, '')->assertSessionHasNoErrors();
        $this->pay($this->inA, null)->assertSessionHasNoErrors();
        $this->pay($this->inA, '   ')->assertSessionHasNoErrors();

        $payments = SubscriptionTransaction::query()->where('subscription_id', $this->inA->id)->where('type', 'payment')->get();
        $this->assertCount(3, $payments);
        // The system makes up no voucher number: none is kept and none is shown.
        $this->assertSame([[null, null, null], [null, null, null], [null, null, null]],
            $payments->map(fn (SubscriptionTransaction $payment): array => [$payment->manual_voucher_number, $payment->voucher_number, $payment->displayVoucherNumber()])->all());
        $this->assertSame(0, SubscriptionTransaction::query()->whereNotNull('cash_voucher_key')->count());
    }

    public function test_the_number_is_kept_exactly_as_written(): void
    {
        $this->pay($this->inA, 'A5')->assertSessionHasNoErrors();
        $this->pay($this->inA, 'a5')->assertSessionHasNoErrors();
        $this->pay($this->inA, ' 0007 ')->assertSessionHasNoErrors();

        // Spaces around it are dropped; letters, case and leading zeros are kept.
        $this->assertSame(['A5', 'a5', '0007'], $this->vouchers($this->inA));
        $this->pay($this->inA, '0007')->assertSessionHasErrors('manual_voucher_number');
        $this->pay($this->inA, '7')->assertSessionHasNoErrors();
    }

    public function test_correcting_a_payment_keeps_its_own_voucher(): void
    {
        $payment = $this->record($this->inA, '000005');
        $this->record($this->inA, '5');

        $this->actingAs($this->adminA)->from(route('subscriptions.statement', $this->inA))
            ->put(route('subscriptions.transactions.update', [$this->inA, $payment]), [
                'amount' => '15', 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => '000005',
                'correction_reason' => 'wrong_amount', 'correction_notes' => 'المبلغ الصحيح',
            ])->assertSessionHasNoErrors();

        $replacement = $payment->refresh()->correction;
        $this->assertSame('000005', $replacement->manual_voucher_number);
        $this->assertSame('15.00', $replacement->currency_amount);
        $this->pay($this->inA, '000005')->assertSessionHasErrors('manual_voucher_number');
    }

    public function test_two_payments_saved_at_the_same_moment_cannot_share_a_number_in_a_branch(): void
    {
        $line = ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => '000005'];
        $this->line($this->inA, $line);
        // The same number in another branch, saved straight to the table, is allowed.
        $this->line($this->inB, $line);
        $this->line($this->inA, [...$line, 'manual_voucher_number' => '5']);

        // A second save that never ran the app's check, as a payment racing the first would.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->line($this->inA, $line);
    }

    public function test_a_payment_losing_the_race_gets_the_voucher_message(): void
    {
        $this->line($this->inA, ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => '000005']);
        $save = new \ReflectionMethod(SubscriptionTransaction::class, 'refusingTakenVoucher');

        try {
            $save->invoke(null, '000005', fn () => $this->line($this->inA, ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => '000005']));
            $this->fail('The second payment was saved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['manual_voucher_number' => [$this->taken('000005', withHolder: false)]], $exception->errors());
        }
    }

    public function test_the_form_hears_a_taken_number_only_in_its_own_branch(): void
    {
        $this->record($this->inA, '000005');
        $status = fn (Subscription $subscription, User $admin, string $voucher) => $this->actingAs($admin)
            ->getJson(route('subscriptions.payments.voucher-status', [$subscription, 'manual_voucher_number' => $voucher]))->assertOk();

        $status($this->inA, $this->adminA, '000005')->assertJsonPath('available', false);
        $status($this->inA, $this->adminA, '5')->assertJsonPath('available', true);
        $status($this->inB, $this->adminB, '000005')->assertJsonPath('available', true);
    }

    private function taken(string $voucher, bool $withHolder = true): string
    {
        $holder = $withHolder ? ' للمشترك '.$this->inA->displayName().' بتاريخ '.now(config('app.business_timezone'))->toDateString() : '';

        return 'رقم السند '.$voucher.' مستخدم من قبل على دفعة نقدية أخرى'.$holder.'. لكل دفعة نقدية سند واحد ولا يتكرر رقمه؛ تأكد من رقم السند.';
    }

    private function pay(Subscription $subscription, ?string $voucher, ?User $admin = null): TestResponse
    {
        return $this->actingAs($admin ?? $this->adminA)
            ->from(route('subscriptions.statement', $subscription))
            ->post(route('subscriptions.payments.store', $subscription), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => $voucher]);
    }

    private function record(Subscription $subscription, string $voucher): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($subscription, $this->adminA, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => $voucher]);
    }

    private function line(Subscription $subscription, array $attributes): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($subscription)->create(['source_key' => 'test:'.Str::ulid(), ...$attributes]);
    }

    /** @return list<?string> */
    private function vouchers(Subscription $subscription): array
    {
        return SubscriptionTransaction::query()->withoutGlobalScopes()->where('subscription_id', $subscription->id)->where('type', 'payment')->orderBy('id')->pluck('manual_voucher_number')->all();
    }
}
