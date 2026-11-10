<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One subscription followed from registration to a change of branch, and
 * one brought in from the old system and collected from.
 */
class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subscription_goes_from_registration_to_reading_bill_payment_statement_and_another_branch(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create();
        $otherBranch = Branch::factory()->inArea($area)->create();
        $tariff = Tariff::factory()->residential()->create(['rate' => '30.00']);
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        // Register it, accounted monthly.
        $this->actingAs($admin)->post(route('subscriptions.store'), [
            'full_name' => 'Flow Customer',
            'national_id' => '123456789',
            'phone' => '0599000000',
            'tariff_id' => $tariff->id,
            'status' => 'active',
            'accounting_type' => 'monthly',
            'minimum_charge' => 20,
            'initial_reading' => 1200,
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $subscription = Subscription::query()->sole();
        $this->assertSame($branch->id, $subscription->branch_id);
        $this->assertSame($admin->id, $subscription->registered_by);
        $this->assertSame('monthly', $subscription->accounting_type->value);
        $this->assertSame('2026'.'00001', $subscription->account_number);
        $this->assertNotNull($subscription->profile);

        // It is listed and can be found by name.
        $this->get(route('subscriptions.index', ['search' => 'Flow Customer']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriptions/Index')->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $subscription->id));

        // A reading is recorded and approved: 5 kilowatts at 30 are billed to the account.
        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-09-18', 'current_reading' => 1205,
        ])->assertSessionHasNoErrors();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$subscription->meterReadings()->sole()->id]])->assertSessionHasNoErrors();
        $this->assertSame(150.0, $subscription->balance());

        // A payment of 100 leaves 50 owing.
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(50.0, $subscription->balance());

        // The statement shows both lines and the balance.
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('subscription.id', $subscription->id)
            ->has('entries', 2)
            ->where('summary.balance', '50.00'));

        // The subscription moves to another branch: its balance and lines go with it.
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->put(route('subscriptions.update', $subscription), [
            'full_name' => 'Flow Customer',
            'national_id' => '123456789',
            'phone' => '0599000000',
            'tariff_id' => $tariff->id,
            'status' => 'active',
            'minimum_charge' => 20,
            'initial_reading' => 1200,
            'branch_id' => $otherBranch->id,
        ])->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame($otherBranch->id, $subscription->branch_id);
        $this->assertSame(50.0, $subscription->balance());
        $this->assertSame(2, $subscription->transactions()->count());
        // Lines keep the branch they were recorded in; reports for that branch do not change.
        $this->assertSame([$branch->id], $subscription->transactions()->pluck('branch_id')->unique()->values()->all());

        $newAdmin = User::factory()->branchAdmin()->create(['branch_id' => $otherBranch->id]);
        $this->actingAs($newAdmin)->post(route('subscriptions.payments.store', $subscription), ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $subscription->balance());
        $this->assertSame($otherBranch->id, $subscription->transactions()->latest('id')->first()->branch_id);

        // The old branch no longer sees it; the new one does.
        $this->actingAs($admin)->get(route('subscriptions.statement', $subscription))->assertForbidden();
        $this->actingAs($newAdmin)->get(route('subscriptions.statement', $subscription))->assertOk();
    }

    public function test_a_subscription_imported_from_the_old_system_opens_with_its_balance_and_can_be_collected_from(): void
    {
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create();
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        Tariff::factory()->residential()->create();
        $file = tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($file, "subscription_number,name,phone_number,balance,subscription_type,minimum_limit,area\n129600,اسامة فضل,0599013094,123.05,منزلي - أسبوعي,20,حميدة\n");

        $this->artisan('subscriptions:import', ['file' => $file, '--user' => $manager->username])->assertSuccessful();

        $subscription = Subscription::query()->where('legacy_number', '129600')->sole();
        $this->assertSame(123.05, $subscription->balance());

        // Found by the old number, then paid.
        $this->actingAs($manager)->get(route('subscriptions.index', ['search' => '129600']))
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $subscription->id));
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '23.05', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(100.0, $subscription->balance());
        $this->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->has('entries', 2)
            ->where('summary.balance', '100.00'));
        $this->assertSame(SubscriptionTransaction::TYPE_INVOICE, $subscription->transactions()->oldest('id')->first()->type);
    }
}
