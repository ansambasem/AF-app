<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TariffCategory;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\TariffSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subscription_can_be_registered_with_a_meter_box_and_tariff(): void
    {
        $branch = Branch::factory()->create();
        $box = MeterBox::factory()->create(['branch_id' => $branch->id, 'box_number' => 'BOX-0001']);
        $tariff = Tariff::factory()->residential()->create();
        $registrar = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);

        $subscription = Subscription::factory()->create([
            'meter_box_id' => $box->id,
            'tariff_id' => $tariff->id,
            'branch_id' => $branch->id,
            'registered_by' => $registrar->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertTrue($subscription->meterBox->is($box));
        $this->assertTrue($subscription->tariff->is($tariff));
        $this->assertTrue($subscription->branch->is($branch));
        $this->assertTrue($subscription->registeredBy->is($registrar));
        $this->assertSame(TariffCategory::Residential, $subscription->tariff->category);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        $this->assertTrue($box->subscriptions->contains($subscription));
        $this->assertTrue($branch->subscriptions->contains($subscription));
        $this->assertTrue($registrar->registeredSubscriptions->contains($subscription));
    }

    public function test_a_meter_box_can_hold_more_than_one_subscription(): void
    {
        $box = MeterBox::factory()->create();
        $tariff = Tariff::factory()->residential()->create();

        Subscription::factory()->count(2)->create(['meter_box_id' => $box->id, 'tariff_id' => $tariff->id]);

        $this->assertCount(2, $box->fresh()->subscriptions);
    }

    public function test_a_subscription_can_be_registered_without_a_meter_box_yet(): void
    {
        $subscription = Subscription::factory()->create(['meter_box_id' => null]);

        $this->assertNull($subscription->meterBox);
    }

    public function test_a_subscription_can_be_found_by_the_number_from_the_old_system(): void
    {
        $imported = Subscription::factory()->create(['legacy_number' => '129600']);
        Subscription::factory()->create(['legacy_number' => '129601']);
        Subscription::factory()->create();

        $this->assertSame([$imported->id], Subscription::query()->matchingSearch('129600')->pluck('id')->all());
    }

    public function test_two_subscriptions_cannot_share_an_old_system_number(): void
    {
        Subscription::factory()->create(['legacy_number' => '129600']);

        $this->expectException(QueryException::class);

        Subscription::factory()->create(['legacy_number' => '129600']);
    }

    public function test_new_subscriptions_get_sequential_account_numbers_for_the_current_year(): void
    {
        $this->travelTo(now()->setDate(2026, 5, 1));

        $first = Subscription::factory()->create();
        $second = Subscription::factory()->create();

        $this->assertSame('202600001', $first->account_number);
        $this->assertSame('202600002', $second->account_number);
    }

    public function test_the_account_number_sequence_restarts_each_year(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31));
        Subscription::factory()->count(2)->create();

        $this->travelTo(now()->setDate(2027, 1, 1));

        $this->assertSame('202700001', Subscription::factory()->create()->account_number);
    }

    public function test_the_two_fixed_tariff_categories_are_seeded(): void
    {
        $this->seed(TariffSeeder::class);

        $this->assertDatabaseHas('tariffs', ['category' => TariffCategory::Residential->value]);
        $this->assertDatabaseHas('tariffs', ['category' => TariffCategory::Commercial->value]);
    }
}
