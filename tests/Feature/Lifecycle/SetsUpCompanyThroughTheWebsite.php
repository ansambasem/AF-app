<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\AuditAction;
use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\CurrencyRate;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use App\Models\UserType;
use Database\Seeders\CurrencyRateSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * A company set up on a fresh deployment the way its Super Admin does it:
 * through the website, from the locations down to the staff and what each may do.
 */
trait SetsUpCompanyThroughTheWebsite
{
    private const PASSWORD = 'Strong-Pass-2026';

    private User $superAdmin;

    /** What a deployment starts from (see DatabaseSeeder): one Super Admin and the permission list, nothing else. */
    private function startFreshDeployment(): void
    {
        $this->seed([PermissionSeeder::class, CurrencyRateSeeder::class]);
        $this->superAdmin = User::factory()->superAdmin()->create(['username' => 'owner']);
    }

    /**
     * Everything a company needs before its first subscriber, set up through the website by the Super Admin.
     *
     * @return array{governorate: Governorate, area: Area, blockA: SubArea, blockB: SubArea, branch: Branch, residential: Tariff, commercial: Tariff, segment: TariffSegment, breaker: CircuitBreaker, schools1: MeterBox, schools2: MeterBox, market: MeterBox, manager: User, collector: User, reader: User, auditor: User}
     */
    private function setUpCompany(): array
    {
        $this->actingAs($this->superAdmin);
        $this->post(route('governorates.store'), ['name' => 'المحافظة الوسطى'])->assertSessionHasNoErrors();
        $governorate = Governorate::query()->where('name', 'المحافظة الوسطى')->sole();
        $this->post(route('areas.store'), ['name' => 'مخيم النصيرات', 'governorate_id' => $governorate->id])->assertSessionHasNoErrors();
        $area = Area::query()->where('name', 'مخيم النصيرات')->sole();
        $this->post(route('sub-areas.store'), ['name' => 'البلوك أ', 'area_id' => $area->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'البلوك ب', 'area_id' => $area->id])->assertSessionHasNoErrors();
        [$blockA, $blockB] = SubArea::query()->where('area_id', $area->id)->orderBy('id')->get()->all();
        $this->put(route('settings.currencies.update', CurrencyRate::query()->where('currency', 'USD')->sole()), ['rate' => '3.7'])->assertSessionHasNoErrors();
        $this->post(route('tariffs.store'), ['category' => 'residential', 'rate' => '0.50'])->assertSessionHasNoErrors();
        $this->post(route('tariffs.store'), ['category' => 'commercial', 'rate' => '0.80'])->assertSessionHasNoErrors();
        $this->post(route('tariff-segments.store'), ['name' => 'أسر محتاجة'])->assertSessionHasNoErrors();
        $this->post(route('circuit-breakers.store'), ['ampere' => 10, 'minimum_payment' => '10'])->assertSessionHasNoErrors();

        $this->post(route('branches.store'), ['name' => 'فرع النصيرات', 'phone' => '0599000000', 'is_active' => true, 'governorate_id' => $governorate->id, 'area_id' => $area->id])->assertSessionHasNoErrors();
        $branch = Branch::query()->where('name', 'فرع النصيرات')->sole();
        foreach ([['المدارس', '1', 'T-101', $blockA], ['المدارس', '2', 'T-102', $blockB], ['السوق', null, 'T-103', $blockA]] as [$name, $suffix, $number, $block]) {
            $this->post(route('meter-boxes.store'), ['name' => $name, 'name_suffix' => $suffix, 'box_number' => $number, 'sub_area_id' => $block->id, 'branch_id' => $branch->id, 'location' => 'قرب '.$name])->assertSessionHasNoErrors();
        }
        [$schools1, $schools2, $market] = MeterBox::query()->orderBy('id')->get()->all();

        $staff = [];
        foreach (['manager' => ['مدير الفرع', 'branch-manager', 'مدير فرع'], 'collector' => ['محمد الجابي', 'collector-1', 'جابي'], 'reader' => ['قارئ العدادات', 'reader-1', 'قارئ'], 'auditor' => ['المدقق المالي', 'auditor-1', 'مدقق']] as $key => [$name, $username, $title]) {
            $this->post(route('user-types.store'), ['name' => $title])->assertSessionHasNoErrors();
            $this->post(route('users.store'), [
                'name' => $name, 'username' => $username, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
                'user_type_id' => UserType::query()->where('name', $title)->value('id'), 'branch_id' => $branch->id,
            ])->assertSessionHasNoErrors();
            $staff[$key] = User::query()->where('username', $username)->sole();
        }
        $this->put(route('settings.permissions.update'), [
            'permissions' => [
                $staff['manager']->id => Permission::idsFor(UserRole::BranchAdmin->starterPermissions()),
                $staff['collector']->id => Permission::idsFor([...UserRole::Collector->starterPermissions(), PermissionKey::AddClearings]),
                $staff['reader']->id => Permission::idsFor(UserRole::DataEntry->starterPermissions()),
                $staff['auditor']->id => Permission::idsFor(UserRole::FinancialAuditor->starterPermissions()),
            ],
            'audit_settings' => [$staff['auditor']->id => ['scope' => 'all', 'branch_ids' => [], 'actions' => array_column(AuditAction::cases(), 'value')]],
        ])->assertSessionHasNoErrors();

        return [
            'governorate' => $governorate, 'area' => $area, 'blockA' => $blockA, 'blockB' => $blockB, 'branch' => $branch,
            'residential' => Tariff::query()->where('category', 'residential')->sole(), 'commercial' => Tariff::query()->where('category', 'commercial')->sole(),
            'segment' => TariffSegment::sole(), 'breaker' => CircuitBreaker::sole(), 'schools1' => $schools1, 'schools2' => $schools2, 'market' => $market,
            ...array_map(fn (User $user): User => $user->fresh(), $staff),
        ];
    }

    /**
     * The new-subscriber form as the branch fills it in.
     *
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscriberForm(array $company, array $overrides = []): array
    {
        return [
            'full_name' => 'سالم عبد الله', 'national_id' => '401234567', 'phone' => '0599123456', 'address' => 'البلوك أ، قرب المدرسة',
            'meter_box_id' => $company['schools1']->id, 'tariff_id' => $company['residential']->id, 'circuit_breaker_id' => $company['breaker']->id,
            'status' => 'active', 'initial_reading' => 1000, 'minimum_charge' => 10, 'subscription_fee' => '100', 'charge_subscription_fee' => true,
            ...$overrides,
        ];
    }
}
