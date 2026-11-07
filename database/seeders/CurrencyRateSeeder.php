<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Models\CurrencyRate;
use Illuminate\Database\Seeder;

class CurrencyRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Currency::cases() as $currency) {
            CurrencyRate::query()->firstOrCreate(['currency' => $currency->value], ['rate' => $currency === Currency::Shekel ? 1 : null]);
        }
    }
}
