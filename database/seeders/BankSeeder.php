<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (config('powercollect.sender_banks') as $name) {
            Bank::query()->firstOrCreate(['name' => $name], ['is_receiver' => in_array($name, config('powercollect.transfer_banks'), true), 'is_sender' => true, 'is_active' => true]);
        }
    }
}
