<?php

namespace Database\Seeders;

use App\Models\UserType;
use Illuminate\Database\Seeder;

class UserTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['كهربائي', 'محصل'] as $name) {
            UserType::firstOrCreate(['name' => $name]);
        }
    }
}
