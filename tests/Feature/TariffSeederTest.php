<?php

namespace Tests\Feature;

use App\Enums\TariffCategory;
use App\Models\Tariff;
use App\Models\TariffRateChange;
use Database\Seeders\TariffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_missing_tariff_categories_and_initial_price_history_without_duplicates(): void
    {
        $this->seed(TariffSeeder::class);
        $this->seed(TariffSeeder::class);

        $this->assertDatabaseCount('tariffs', 2);
        $this->assertDatabaseCount('tariff_rate_changes', 2);
        $this->assertDatabaseHas('tariffs', ['category' => TariffCategory::Residential->value, 'rate' => 50]);
        $this->assertDatabaseHas('tariffs', ['category' => TariffCategory::Commercial->value, 'rate' => 120]);
    }

    public function test_repeated_seeding_preserves_the_administrations_prices_and_their_history(): void
    {
        $residential = Tariff::factory()->residential()->create(['rate' => 30]);
        $commercial = Tariff::factory()->create(['category' => TariffCategory::Commercial, 'rate' => 45]);
        $residential->rateChanges()->create(['rate' => 30]);
        $commercial->rateChanges()->create(['rate' => 45]);
        $before = TariffRateChange::query()->orderBy('id')->get()->toJson();

        $this->seed(TariffSeeder::class);

        $this->assertDatabaseHas('tariffs', ['id' => $residential->id, 'rate' => 30]);
        $this->assertDatabaseHas('tariffs', ['id' => $commercial->id, 'rate' => 45]);
        $this->assertSame($before, TariffRateChange::query()->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('tariffs', 2);
    }

    public function test_an_existing_tariff_without_history_records_its_current_price(): void
    {
        $residential = Tariff::factory()->residential()->create(['rate' => 30]);

        $this->seed(TariffSeeder::class);

        $this->assertDatabaseHas('tariffs', ['id' => $residential->id, 'rate' => 30]);
        $this->assertDatabaseHas('tariff_rate_changes', ['tariff_id' => $residential->id, 'rate' => 30]);
    }
}
