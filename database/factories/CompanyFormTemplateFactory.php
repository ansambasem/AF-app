<?php

namespace Database\Factories;

use App\Models\CompanyFormTemplate;
use App\Support\CompanyForms;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyFormTemplate> */
class CompanyFormTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'category' => 'طلبات الاشتراك', 'layout' => CompanyForms::defaultLayout(), 'version' => 1];
    }
}
