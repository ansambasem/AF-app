<?php

namespace Database\Seeders;

use App\Enums\TariffCategory;
use App\Models\Tariff;
use Illuminate\Database\Seeder;

class TariffSeeder extends Seeder
{
    /**
     * Seed the two fixed categories with initial placeholder rates. Existing
     * prices remain under the administration's control when seeding again.
     */
    public function run(): void
    {
        foreach ([TariffCategory::Residential->value => 50, TariffCategory::Commercial->value => 120] as $category => $rate) {
            $tariff = Tariff::firstOrCreate(['category' => $category], ['rate' => $rate]);

            if ($tariff->rateChanges()->doesntExist()) {
                $tariff->rateChanges()->create(['rate' => $tariff->rate]);
            }
        }
    }
}
