<?php

namespace Database\Factories;

use App\Models\PrintTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrintTemplate> */
class PrintTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page' => '/meter-readings',
            'name' => fake()->unique()->words(3, true),
            'layout' => ['paper' => 'A4', 'orientation' => 'portrait', 'columns' => []],
            'is_default' => false,
        ];
    }
}
