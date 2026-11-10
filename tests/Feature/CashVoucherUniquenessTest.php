<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * A cash payment's paper voucher number is used once: the form says so while
 * it is typed, saving refuses it, and the database refuses a second standing
 * cash payment with it even when both are saved at the same moment.
 */
class CashVoucherUniquenessTest extends TestCase
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
        $this->line(['amount' => '500.00']);
    }

    public function test_a_second_cash_payment_with_the_same_voucher_is_refused_with_a_clear_message(): void
    {
        $this->pay(['manual_voucher_number' => '00412'])->assertSessionHasNoErrors();

        $other = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->pay(['manual_voucher_number' => '00412'], $other)
            ->assertSessionHasErrors(['manual_voucher_number' => 'رقم السند 00412 مستخدم من قبل على دفعة نقدية أخرى للمشترك Ahmad بتاريخ '.now(config('app.business_timezone'))->toDateString().'. لكل دفعة نقدية سند واحد ولا يتكرر رقمه؛ تأكد من رقم السند.']);

        $this->assertSame(1, SubscriptionTransaction::query()->where('type', 'payment')->count());
    }

    public function test_the_same_number_written_with_spaces_or_arabic_digits_is_the_same_voucher(): void
    {
        $this->pay(['manual_voucher_number' => '412'])->assertSessionHasNoErrors();

        $this->pay(['manual_voucher_number' => '  412 '])->assertSessionHasErrors('manual_voucher_number');
        $this->pay(['manual_voucher_number' => '٤١٢'])->assertSessionHasErrors('manual_voucher_number');
        $this->assertSame(['412'], SubscriptionTransaction::query()->where('type', 'payment')->pluck('manual_voucher_number')->all());
    }

    public function test_a_cancelled_payments_voucher_is_never_used_again(): void
    {
        $payment = $this->recordPayment(['manual_voucher_number' => '77']);
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'سُجّلت مرتين'])
            ->assertSessionHasNoErrors();

        $this->pay(['manual_voucher_number' => '77'])
            ->assertSessionHasErrors(['manual_voucher_number' => 'رقم السند 77 مستخدم من قبل على دفعة نقدية أخرى للمشترك Ahmad بتاريخ '.now(config('app.business_timezone'))->toDateString().' (دفعة ملغاة). لكل دفعة نقدية سند واحد ولا يتكرر رقمه؛ تأكد من رقم السند.']);
    }

    public function test_a_corrected_payment_keeps_its_voucher_however_often_it_is_corrected(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'manual_voucher_number' => '900']);

        $this->correct($payment, '100')->assertSessionHasNoErrors();
        $replacement = $payment->refresh()->correction;
        $this->assertSame('900', $replacement->manual_voucher_number);

        $this->correct($replacement, '120')->assertSessionHasNoErrors();
        $this->assertSame('900', $replacement->refresh()->correction->manual_voucher_number);

        // Still nobody else may take it.
        $this->pay(['manual_voucher_number' => '900'])->assertSessionHasErrors('manual_voucher_number');
    }

    public function test_a_correction_may_not_take_another_payments_voucher(): void
    {
        $this->recordPayment(['manual_voucher_number' => '10']);
        $payment = $this->recordPayment(['manual_voucher_number' => '11']);

        $this->correct($payment, '60', '10')->assertSessionHasErrors('manual_voucher_number');
        $this->assertFalse($payment->refresh()->isCancelled());
    }

    public function test_payments_without_a_paper_voucher_and_transfers_are_not_affected(): void
    {
        $this->pay(['manual_voucher_number' => ''])->assertSessionHasNoErrors();
        $this->pay([])->assertSessionHasNoErrors();
        $transfer = ['payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad'];
        $this->pay([...$transfer, 'manual_voucher_number' => '5'])->assertSessionHasNoErrors();
        $this->pay([...$transfer, 'manual_voucher_number' => '5'])->assertSessionHasNoErrors();
        $this->pay(['manual_voucher_number' => '5'])->assertSessionHasNoErrors();

        $this->assertSame(5, SubscriptionTransaction::query()->where('type', 'payment')->count());
    }

    public function test_another_branch_may_use_the_same_voucher_number(): void
    {
        $other = Branch::factory()->create();
        $elsewhere = Subscription::factory()->create(['branch_id' => $other->id]);
        SubscriptionTransaction::recordPayment($elsewhere, $this->branchAdmin, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => '3']);

        $this->pay(['manual_voucher_number' => '3'])->assertSessionHasNoErrors();
        $this->assertSame(2, SubscriptionTransaction::query()->withoutGlobalScopes()->where('manual_voucher_number', '3')->count());
    }

    public function test_the_database_refuses_two_standing_cash_payments_with_one_voucher_saved_together(): void
    {
        $line = ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => 'R-1'];
        $this->line($line);

        // A second save that never ran the app's check, as a payment racing the first would.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->line($line);
    }

    public function test_a_payment_losing_the_race_for_a_voucher_gets_the_same_message(): void
    {
        $line = ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => 'R-9'];
        $this->line($line);
        $save = new \ReflectionMethod(SubscriptionTransaction::class, 'refusingTakenVoucher');

        try {
            // The check passed before the other payment was saved; the database refuses the second one.
            $save->invoke(null, 'R-9', fn () => $this->line($line));
            $this->fail('The second payment was saved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['manual_voucher_number' => ['رقم السند R-9 مستخدم من قبل على دفعة نقدية أخرى. لكل دفعة نقدية سند واحد ولا يتكرر رقمه؛ تأكد من رقم السند.']], $exception->errors());
        }
    }

    public function test_the_database_lets_a_cancelled_payment_and_its_correction_share_the_voucher(): void
    {
        $line = ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => 'R-2'];
        $this->line([...$line, 'status' => 'cancelled', 'cancelled_at' => now()]);
        $this->line($line);
        $this->line([...$line, 'payment_method' => 'bank_transfer']);

        $this->assertSame(['R-2'], SubscriptionTransaction::query()->whereNotNull('cash_voucher_key')->pluck('cash_voucher_key')->all());
    }

    public function test_the_payment_form_hears_a_taken_voucher_while_it_is_typed(): void
    {
        $payment = $this->recordPayment(['manual_voucher_number' => '55']);
        $status = fn (array $query) => $this->actingAs($this->branchAdmin)->getJson(route('subscriptions.payments.voucher-status', [$this->subscription, ...$query]))->assertOk();

        $status(['manual_voucher_number' => '55'])->assertJsonPath('available', false)->assertJsonPath('message', fn (string $message) => str_contains($message, 'رقم السند 55 مستخدم'));
        $status(['manual_voucher_number' => '56'])->assertJsonPath('available', true);
        $status(['manual_voucher_number' => '55', 'ignore_transaction_id' => $payment->id])->assertJsonPath('available', true);

        $collector = User::factory()->collector()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($collector)->getJson(route('subscriptions.payments.voucher-status', [$this->subscription, 'manual_voucher_number' => '55']))->assertForbidden();
    }

    public function test_the_migration_stops_without_changing_anything_when_vouchers_are_already_shared(): void
    {
        (require database_path('migrations/2026_11_10_083541_scope_cash_voucher_uniqueness_to_branch.php'))->down();
        $migration = require database_path('migrations/2026_11_09_090221_add_unique_cash_voucher_to_subscription_transactions.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('subscription_transactions', 'cash_voucher_key'));

        $line = ['type' => 'payment', 'payment_method' => 'cash', 'amount' => '-10.00', 'manual_voucher_number' => '42'];
        $first = $this->line($line);
        $second = $this->line([...$line, 'manual_voucher_number' => ' 42 ']);
        $before = SubscriptionTransaction::query()->orderBy('id')->get()->map->getAttributes()->all();

        try {
            $migration->up();
            $this->fail('The index was added over shared vouchers.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("voucher 42: 2 payments (ids {$first->id},{$second->id})", $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('subscription_transactions', 'cash_voucher_key'));
        $this->assertSame($before, SubscriptionTransaction::query()->orderBy('id')->get()->map->getAttributes()->all());

        // Once one of them is no longer a standing payment, the index goes on.
        $first->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('subscription_transactions', 'cash_voucher_key'));
    }

    /** A line saved straight to the table, as nothing in the app would: the database alone stands in its way. */
    private function line(array $attributes): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($this->subscription)->create(['source_key' => 'test:'.Str::ulid(), ...$attributes]);
    }

    /** @param  array<string, string>  $details */
    private function pay(array $details, ?Subscription $subscription = null): TestResponse
    {
        $subscription ??= $this->subscription;

        return $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $subscription))
            ->post(route('subscriptions.payments.store', $subscription), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', ...$details]);
    }

    /** @param  array<string, string>  $details */
    private function recordPayment(array $details): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash', ...$details]);
    }

    private function correct(SubscriptionTransaction $payment, string $amount, ?string $voucher = null): TestResponse
    {
        return $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->put(route('subscriptions.transactions.update', [$this->subscription, $payment]), [
                'amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'cash', 'manual_voucher_number' => $voucher ?? $payment->manual_voucher_number,
                'correction_reason' => 'wrong_amount', 'correction_notes' => 'المبلغ الصحيح',
            ]);
    }
}
