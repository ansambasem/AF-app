<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\CurrencyRate;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_branch_admins_need_an_explicit_grant_and_do_not_receive_it_by_default(): void
    {
        $manager = User::factory()->branchAdmin()->create();

        $this->actingAs($manager)->get(route('settings.payments.index'))->assertForbidden();
        $this->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertForbidden();

        $this->assertFalse($manager->hasPermission(PermissionKey::ManageBoxPeriods));
        $this->assertDatabaseMissing('closing_settings', ['branch_id' => $manager->branch_id]);
    }

    public function test_the_super_admin_can_grant_and_revoke_the_permission_through_the_permissions_page(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $manager = User::factory()->branchAdmin()->create();
        $permissionId = Permission::idsFor([PermissionKey::ManageBoxPeriods])[0];

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$manager->id => [$permissionId]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('permission_user', ['user_id' => $manager->id, 'permission_id' => $permissionId]);
        $this->actingAs($manager->fresh())->get(route('settings.payments.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Payments')->where('boxPeriod.branch.id', $manager->branch_id)->where('can.managePaymentSettings', true));

        $this->actingAs($admin)->put(route('settings.permissions.update'), ['permissions' => [$manager->id => []]])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('permission_user', ['user_id' => $manager->id, 'permission_id' => $permissionId]);
        $this->actingAs($manager->fresh())->get(route('settings.payments.index'))->assertForbidden();
        $this->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertForbidden();
    }

    public function test_an_authorized_branch_admin_only_updates_their_own_start_day_and_preserves_other_settings_and_records(): void
    {
        ClosingSetting::company()->update(['week_starts_on' => 6, 'cutoff_time' => '18:00']);
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $otherBranch = Branch::factory()->create();
        $otherSetting = ClosingSetting::startFor($otherBranch);
        $closed = CashBox::factory()->create(['branch_id' => $manager->branch_id, 'status' => 'received', 'closed_at' => now()->subWeek()]);
        $original = $closed->fresh()->getAttributes();

        $this->actingAs($manager)->put(route('settings.payments.update'), [
            'week_starts_on' => 1, 'cutoff_time' => '23:00', 'workflow_started_at' => now(), 'receivers' => [$manager->id],
        ])->assertRedirect(route('settings.payments.index', ['branch' => $manager->branch_id]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('closing_settings', ['branch_id' => $manager->branch_id, 'week_starts_on' => 1, 'cutoff_time' => '18:00', 'workflow_started_at' => null, 'updated_by' => $manager->id]);
        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => 6]);
        $this->assertSame(6, $otherSetting->fresh()->week_starts_on);
        $this->assertSame($original, $closed->fresh()->getAttributes());
        $this->assertNull(ClosingSetting::ownFor($manager->branch_id)->receivers);
    }

    public function test_an_authorized_branch_admin_cannot_read_or_write_another_branchs_settings(): void
    {
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $other = Branch::factory()->create();

        $this->actingAs($manager)->get(route('settings.payments.index', ['branch' => $other->id]))->assertNotFound();
        $this->put(route('settings.payments.update'), ['branch_id' => $other->id, 'week_starts_on' => 1])->assertNotFound();

        $this->assertDatabaseMissing('closing_settings', ['branch_id' => $other->id]);
    }

    public function test_a_branch_admin_cannot_delegate_the_permission_even_when_they_hold_it(): void
    {
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $staff = User::factory()->accountant()->for($manager->branch)->create();
        $permissionId = Permission::idsFor([PermissionKey::ManageBoxPeriods])[0];

        $this->actingAs($manager)->get(route('settings.permissions.edit', ['selected' => $staff->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissionGroups', fn ($groups): bool => ! collect($groups)->flatMap(fn ($group) => $group['actions'])->contains('action', 'manage_box_periods')));
        $this->put(route('settings.permissions.update'), ['permissions' => [$staff->id => [$permissionId]]])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('permission_user', ['user_id' => $staff->id, 'permission_id' => $permissionId]);
    }

    public function test_period_access_does_not_grant_currency_or_bank_management(): void
    {
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $rate = CurrencyRate::query()->where('currency', 'USD')->sole();
        $bank = Bank::query()->firstOrFail();

        $this->actingAs($manager)->get(route('settings.payments.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManagePaymentCatalog', false)->has('currencies', 0)->has('banks', 0)->where('storeUrl', null)
            ->where('boxPeriod.canSelectBranch', false)->has('boxPeriod.branches', 0));
        $this->put(route('settings.currencies.update', $rate), ['rate' => 4])->assertForbidden();
        $this->put(route('settings.banks.update', $bank), ['name' => 'تغيير'])->assertForbidden();
    }

    public function test_the_super_admin_can_set_each_branchs_start_day_independently_from_the_company_default(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        ClosingSetting::company()->update(['week_starts_on' => 6]);

        $this->actingAs($admin)->put(route('settings.payments.update'), ['branch_id' => $branch->id, 'week_starts_on' => 2])
            ->assertRedirect(route('settings.payments.index', ['branch' => $branch->id]));
        $this->get(route('settings.payments.index', ['branch' => $branch->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boxPeriod.branch.id', $branch->id)->where('boxPeriod.week_starts_on', 2)->where('boxPeriod.canSelectBranch', true)->where('canManagePaymentCatalog', true));

        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => 6]);
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

    #[TestWith(['accountant'])]
    #[TestWith(['financialAuditor'])]
    #[TestWith(['collector'])]
    public function test_the_individual_grant_allows_any_employee_to_change_only_their_branch_period(string $role): void
    {
        $user = User::factory()->{$role}()->withPermissions([PermissionKey::ManageBoxPeriods])->create();

        $this->actingAs($user)->get(route('settings.payments.index'))->assertOk();
        $this->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('closing_settings', ['branch_id' => $user->branch_id, 'week_starts_on' => 1]);
        $this->put(route('settings.payments.update'), ['week_starts_on' => 2, 'branch_id' => Branch::factory()->create()->id])->assertNotFound();
    }

    public function test_a_period_override_saved_before_the_first_payment_survives_automatic_box_startup(): void
    {
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManageBoxPeriods])->create();
        $this->actingAs($manager)->put(route('settings.payments.update'), ['week_starts_on' => 1])->assertSessionHasNoErrors();

        SubscriptionTransaction::recordPayment(Subscription::factory()->for($manager->branch)->create(), $manager, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->assertDatabaseHas('closing_settings', ['branch_id' => $manager->branch_id, 'week_starts_on' => 1]);
        $this->assertNotNull(ClosingSetting::ownFor($manager->branch_id)->workflow_started_at);
        $this->assertSame(1, Closing::query()->where('branch_id', $manager->branch_id)->sole()->workflow_settings['week_starts_on']);
    }
}
