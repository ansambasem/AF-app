<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Models\CompanyFormTemplate;
use App\Models\Permission;
use App\Support\CompanyForms;
use Illuminate\Database\Seeder;

class CompanyFormTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Permission::idsFor([PermissionKey::PrintCompanyForms]);
        CompanyFormTemplate::firstOrCreate(['name' => 'طلب اشتراك جديد', 'category' => 'طلبات الاشتراك'], ['layout' => CompanyForms::defaultLayout()]);
    }
}
