<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BranchBoxPeriodSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_the_permission_does_not_grant_it_to_existing_branch_admins(): void
    {
        $migration = require database_path('migrations/2026_10_18_104321_add_manage_box_periods_permission.php');
        $migration->down();
        $manager = User::factory()->branchAdmin()->create();

        $migration->up();

        $permission = Permission::query()->where('key', PermissionKey::ManageBoxPeriods->value)->sole();
        $this->assertDatabaseMissing('permission_user', ['user_id' => $manager->id, 'permission_id' => $permission->id]);
    }

    public function test_the_payment_settings_no_longer_set_the_weekday_the_box_weeks_start_on(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ClosingSetting::company()->update(['week_starts_on' => 6]);

        $this->actingAs($admin)->get(route('settings.payments.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Payments')->missing('boxPeriod')->has('currencies')->has('banks')->where('can.managePaymentSettings', true));
        $this->put('/settings/payments', ['week_starts_on' => 2])->assertStatus(405);
        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => 6]);

        // The permission that set it opens nothing any more.
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $this->actingAs($manager)->get(route('settings.payments.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('can.managePaymentSettings', false));
    }

    public function test_auditors_browse_each_branch_using_its_own_week_start(): void
    {
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();
        ClosingSetting::startFor($first)->update(['week_starts_on' => 1]);
        ClosingSetting::startFor($second)->update(['week_starts_on' => 3]);
        foreach ([$first, $second] as $branch) {
            CashBox::factory()->create(['branch_id' => $branch->id, 'closing_id' => Closing::factory()->for($branch), 'status' => 'received', 'closed_at' => '2026-10-20 12:00:00']);
        }
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $first->id, 'week' => '2026-10-20']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', '2026-10-19')->where('weekStartsOn', 1)->has('boxes', 1)->where('periods.0.end', '2026-10-25'));
        $this->get(route('payments.cash-boxes', ['branch_id' => $second->id, 'week' => '2026-10-20']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('week', '2026-10-14')->where('weekStartsOn', 3)->has('boxes', 1)->where('periods.0.end', '2026-10-20'));
    }
}
