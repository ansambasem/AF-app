<?php

namespace Database\Seeders;

use App\Models\TariffSegment;
use Illuminate\Database\Seeder;

class CustomerSegmentSeeder extends Seeder
{
    /**
     * The customer segments (تصنيف الزبائن), in the order they are listed.
     *
     * @var array<int, string>
     */
    private const SEGMENTS = [
        'مساجد',
        'مكتب السوارحة',
        'مجاني',
        'مجاني مولد',
        'موظفين الشركة',
        'موظفين بلدية',
        'امن بنك فلسطين',
        'موظفي البنك',
        'مولد دريم',
        'اصحاب الارض',
        'موظفين شركة الكهرب',
        'مبالغ معلقة',
    ];

    /**
     * Seed the customer segments. Safe to run again: one that exists is kept.
     */
    public function run(): void
    {
        foreach (self::SEGMENTS as $name) {
            TariffSegment::query()->firstOrCreate(['name' => $name]);
        }
    }
}
