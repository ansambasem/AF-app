<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentReferenceNormalizationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['FT-100', 'FT100'])]
    #[TestWith(['ft 100', 'FT100'])]
    #[TestWith(['FT/100', 'FT100'])]
    #[TestWith(['ft.100', 'FT100'])]
    #[TestWith([' f-t / 1.0.0 ', 'FT100'])]
    #[TestWith(['FT–100', 'FT100'])]
    #[TestWith(['---', null])]
    #[TestWith(['', null])]
    #[TestWith([null, null])]
    public function test_a_reference_is_compared_without_case_spaces_dashes_slashes_or_dots(?string $typed, ?string $normalized): void
    {
        $this->assertSame($normalized, SubscriptionTransaction::normalizeReference($typed));
    }

    public function test_the_same_transfer_written_differently_is_caught_as_a_duplicate(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $transfer = fn (string $reference) => SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => $reference,
        ]);

        $first = $transfer('FT-100');
        $this->assertSame('FT100', $first->active_reference);
        $this->assertSame('FT-100', $first->reference_number, 'the reference is shown as it was typed');

        foreach (['ft 100', 'FT/100', 'ft.100', 'Ft-100'] as $again) {
            try {
                $transfer($again);
                $this->fail("«{$again}» was accepted as another transfer.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reference_number', $exception->errors());
            }
        }

        $this->assertNotNull($transfer('FT-101'));
    }

    public function test_the_migration_brings_existing_references_to_the_new_form(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $transfer = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'FT-100',
        ]);
        // As an older version stored it.
        DB::table('subscription_transactions')->where('id', $transfer->id)->update(['active_reference' => 'FT-100']);
        $cancelled = SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'FT-200',
        ]);
        DB::table('subscription_transactions')->where('id', $cancelled->id)->update(['active_reference' => null]);

        (require database_path('migrations/2026_10_07_100614_normalize_active_references_on_subscription_transactions.php'))->up();

        $this->assertSame('FT100', $transfer->fresh()->active_reference);
        $this->assertNull($cancelled->fresh()->active_reference, 'a released reference stays released');
    }
}
