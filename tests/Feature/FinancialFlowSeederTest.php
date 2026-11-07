<?php

namespace Tests\Feature;

use App\Models\CashBox;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Database\Seeders\FinancialFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FinancialFlowSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_payments_produce_statements_and_boxes_at_each_workflow_stage(): void
    {
        $this->freezeTime();

        $this->seed(FinancialFlowSeeder::class);

        $this->assertDatabaseCount('subscription_transactions', 45);
        $this->assertDatabaseCount('cash_box_payments', 23);
        $this->assertDatabaseCount('cash_boxes', 9);
        $this->assertSame(3, CashBox::query()->where('status', 'open')->count());
        $this->assertSame(6, CashBox::query()->where('status', 'received')->whereDoesntHave('audit')->count());
        $this->assertSame(0, CashBox::query()->whereNotNull('sent_at')->count());
        $subscriptions = Subscription::query()->orderBy('id')->get();
        $this->assertSame(205.0, $subscriptions->first()->balance());
        $this->assertSame(-60.0, $subscriptions->last()->balance());
        $this->assertDatabaseHas('subscription_transactions', ['currency' => 'USD', 'currency_amount' => 20, 'amount' => -70]);
        $this->assertDatabaseHas('subscription_transactions', ['currency' => 'JOD', 'currency_amount' => 10, 'amount' => -50]);
    }

    public function test_rerunning_the_seeder_preserves_existing_payments_credentials_and_box_progress(): void
    {
        $this->freezeTime();
        $this->seed(FinancialFlowSeeder::class);
        $transaction = SubscriptionTransaction::query()->firstOrFail();
        $transaction->update(['notes' => 'Kept by the tester']);
        $user = User::query()->where('username', FinancialFlowSeeder::COLLECTOR_USERNAME)->sole();
        $user->update(['password' => 'changed-by-tester']);
        $password = $user->getRawOriginal('password');
        $boxes = CashBox::query()->get()->map->getRawOriginal()->all();

        $this->seed(FinancialFlowSeeder::class);

        $this->assertDatabaseCount('subscription_transactions', 45);
        $this->assertDatabaseCount('cash_boxes', 9);
        $this->assertSame('Kept by the tester', $transaction->fresh()->notes);
        $this->assertSame($password, $user->fresh()->getRawOriginal('password'));
        $this->assertSame($boxes, CashBox::query()->get()->map->getRawOriginal()->all());
    }

    public function test_seeded_closed_boxes_are_available_to_auditors_and_completed_audits_survive_reruns(): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        $this->seed(FinancialFlowSeeder::class);
        $collector = User::query()->where('username', FinancialFlowSeeder::COLLECTOR_USERNAME)->sole();
        $auditor = User::factory()->financialAuditor()->create();
        $bank = CashBox::query()->where('user_id', $collector->id)->where('type', 'bank_transfer')->where('status', 'received')->firstOrFail();
        $payment = $bank->payments()->firstOrFail();
        $original = $payment->transaction->getAttributes();
        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $collector->branch_id, 'place' => 'closed']))
            ->assertInertia(fn ($page) => $page->has('boxes', 6)->where('periodAudit.boxes', 6)->where('periods.0.reviewed', 0));
        $this->post(route('payments.bank-audits.store', $payment), ['bank_amount' => '50', 'reason' => 'نقص في الكشف التجريبي'])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $bank), ['confirmed' => true])->assertSessionHasNoErrors();
        $stamp = $bank->audit()->sole()->getAttributes();
        $decision = $payment->audit()->sole()->getAttributes();
        $adjustment = $payment->audit->adjustment->getAttributes();
        $balance = $payment->transaction->subscription->balance();

        $this->seed(FinancialFlowSeeder::class);

        $this->assertSame($stamp, $bank->audit()->sole()->getAttributes());
        $this->assertSame($decision, $payment->audit()->sole()->getAttributes());
        $this->assertSame($adjustment, $payment->audit->adjustment->fresh()->getAttributes());
        $this->assertSame($original, $payment->transaction->fresh()->getAttributes());
        $this->assertSame($balance, $payment->transaction->subscription->balance());
        $this->assertDatabaseCount('subscription_transactions', 46);
        $this->assertSame(3, CashBox::query()->where('status', 'open')->count());
    }

    public function test_financial_samples_cannot_be_seeded_in_production(): void
    {
        app()->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Financial sample data is only available locally or in tests.');

        app(FinancialFlowSeeder::class)->run();
    }

    public function test_missing_receipt_counter_is_restored_without_changing_existing_payments(): void
    {
        $this->freezeTime();
        $existing = SubscriptionTransaction::factory()->create([
            'type' => SubscriptionTransaction::TYPE_PAYMENT,
            'payment_method' => 'cash',
            'source_key' => 'payment:8',
            'voucher_number' => 8,
            'amount' => -100,
        ]);
        $original = $existing->fresh()->getRawOriginal();
        DB::table('payment_voucher_sequences')->delete();

        $this->seed(FinancialFlowSeeder::class);

        $this->assertSame($original, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('subscription_transactions', 46);
        $this->assertDatabaseHas('payment_voucher_sequences', ['id' => 1, 'last_number' => 21]);
        $this->assertSame(14, SubscriptionTransaction::query()->whereNotNull('voucher_number')->distinct()->count('voucher_number'));
    }
}
