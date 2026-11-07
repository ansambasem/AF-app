<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CompanyDocument;
use App\Support\CompanyForms;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CompanyDocument> */
class CompanyDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'branch_id' => Branch::factory(), 'mode' => 'blank', 'title' => 'طلب اشتراك', 'snapshot' => ['layout' => CompanyForms::defaultLayout(), 'values' => [], 'source' => null]];
    }
}
