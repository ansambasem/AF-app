<?php

namespace Tests\Feature\Tariffs;

use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_price_records_it_in_the_tariffs_history_with_who_changed_it(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Sami']);
        $tariff = Tariff::factory()->residential()->create(['rate' => 2.5]);

        $this->actingAs($superAdmin)->put(route('tariffs.update', $tariff), ['category' => 'residential', 'rate' => 3])->assertSessionHasNoErrors();
        $this->actingAs($superAdmin)->put(route('tariffs.update', $tariff), ['category' => 'residential', 'rate' => 3])->assertSessionHasNoErrors();

        $this->assertSame(['3.00'], $tariff->rateChanges()->pluck('rate')->all());

        $this->actingAs($superAdmin)
            ->get(route('tariffs.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tariffs.0.rate', '3.00')
                ->where('tariffs.0.rateChangedBy', 'Sami')
                ->has('tariffs.0.history', 1)
                ->where('tariffs.0.history.0.rate', '3.00'));
    }

    public function test_a_card_shows_its_subscriptions_and_their_recent_average_weekly_consumption(): void
    {
        $tariff = Tariff::factory()->residential()->create();
        $subscriptions = Subscription::factory()->count(2)->create(['tariff_id' => $tariff->id]);
        MeterReading::factory()->approved()->create(['subscription_id' => $subscriptions[0]->id, 'branch_id' => $subscriptions[0]->branch_id, 'consumption' => 30]);
        MeterReading::factory()->approved()->create(['subscription_id' => $subscriptions[1]->id, 'branch_id' => $subscriptions[1]->branch_id, 'consumption' => 45]);
        MeterReading::factory()->create(['subscription_id' => $subscriptions[1]->id, 'branch_id' => $subscriptions[1]->branch_id, 'consumption' => 900, 'week_start' => now()->subWeek()->startOfWeek()]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('tariffs.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tariffs.0.subscriptionsCount', 2)
                ->where('tariffs.0.averageConsumption', 37.5)
                ->where('canCreate', true)
                ->where('categoryOptions', [['value' => 'commercial', 'label' => 'تجاري']]));
    }
}
