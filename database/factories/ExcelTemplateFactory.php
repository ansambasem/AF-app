<?php

namespace Database\Factories;

use App\Models\ExcelTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExcelTemplate> */
class ExcelTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => 'reports',
            'name' => fake()->unique()->words(3, true),
            'settings' => ['theme' => 'graphite', 'zebra' => true, 'parts' => []],
            'is_default' => false,
        ];
    }
}
