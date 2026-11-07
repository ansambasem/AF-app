<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\CashTransfer;
use App\Models\Closing;
use App\Models\Permission;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class RemovedFinancialFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_and_company_cashbox_endpoints_return_404_and_preserve_existing_records(): void
    {
        $closing = Closing::factory()->approved()->create();
        $transfer = CashTransfer::factory()->create(['closing_id' => $closing->id]);
        $transaction = SubscriptionTransaction::factory()->create(['type' => SubscriptionTransaction::TYPE_PAYMENT, 'amount' => '-50.00', 'payment_method' => 'cash']);
        $records = [$closing, $transfer, $transaction];
        $before = array_map(fn ($record): array => $record->fresh()->getAttributes(), $records);
        $this->actingAs(User::factory()->superAdmin()->create());

        foreach ([
            ['get', '/closings'], ['get', '/closings/register.csv'],
            ['get', '/settings/closing-schedule'], ['put', '/settings/closing-schedule'], ['post', '/settings/closing-schedule/open'],
            ['put', "/closings/{$closing->id}/count"], ['put', "/closings/{$closing->id}/lines/1"],
            ['post', "/closings/{$closing->id}/submit"], ['post', "/closings/{$closing->id}/return"], ['post', "/closings/{$closing->id}/approve"],
            ['post', "/closings/{$closing->id}/transfers"], ['post', "/cash-transfers/{$transfer->id}/receive"], ['get', "/cash-transfers/{$transfer->id}/proof"],
            ['post', '/period-closings'], ['get', '/branch-closings'], ['post', '/branch-closings'],
            ['get', '/branch-closings/period'], ['get', '/branch-closings/manual'],
            ['get', "/branch-closings/{$closing->id}/prepare"], ['get', "/branch-closings/{$closing->id}/review"],
            ['get', "/branch-closings/{$closing->id}"], ['put', "/branch-closings/{$closing->id}"],
        ] as [$method, $path]) {
            $this->{$method}($path)->assertNotFound();
        }

        foreach ($records as $index => $record) {
            $this->assertSame($before[$index], $record->fresh()->getAttributes());
        }
    }

    public function test_the_closing_command_and_schedule_are_removed_while_backups_remain(): void
    {
        $this->assertArrayNotHasKey('closings:open', Artisan::all());
        $commands = collect(Schedule::events())->pluck('command')->filter()->all();

        $this->assertFalse(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'closings:open')));
        $this->assertTrue(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'backup:run')));
    }

    public function test_retired_permission_grants_stay_stored_but_are_hidden_and_cannot_be_granted(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $holder = User::factory()->accountant()->create();
        $target = User::factory()->dataEntry()->create();
        $retired = Permission::idsFor([PermissionKey::PrepareClosings, PermissionKey::ViewOwnClosings]);
        $holder->permissions()->syncWithoutDetaching($retired);

        $this->actingAs($actor)->get(route('settings.permissions.edit', ['selected' => $holder->id]))
            ->assertInertia(fn ($page) => $page
                ->missing('can.viewClosings')
                ->missing('can.manageClosingSchedule')
                ->where('permissionGroups', fn ($groups): bool => ! collect($groups)->contains('key', 'closings'))
                ->where('selectedUser.permissionIds', fn ($ids): bool => array_intersect($retired, collect($ids)->all()) === [])
                ->where('selectedUser.lockedPermissions', []));

        $this->put(route('settings.permissions.update'), ['permissions' => [$holder->id => [], $target->id => $retired]])
            ->assertSessionHasNoErrors();

        $this->assertSame($retired, $holder->permissions()->whereIn('permissions.id', $retired)->pluck('permissions.id')->all());
        $this->assertSame([], $target->permissions()->whereIn('permissions.id', $retired)->pluck('permissions.id')->all());
    }

    public function test_report_permissions_preserve_branch_scope_without_exposing_closing_controls(): void
    {
        $own = User::factory()->accountant()->create();
        $other = User::factory()->accountant()->create();
        $own->permissions()->sync(Permission::idsFor([PermissionKey::ViewFinancialReports]));

        $this->actingAs($own)->get(route('reports.index', ['branch' => $other->branch_id]))
            ->assertInertia(fn ($page) => $page
                ->where('filters.branch', $own->branch_id)
                ->where('can.viewReports', true)
                ->missing('can.viewClosings')
                ->missing('check'));

        $this->get(route('reports.export'))->assertForbidden();
    }

    public function test_report_permission_catalog_preserves_existing_definitions_and_grants(): void
    {
        $holder = User::factory()->accountant()->create();
        $holder->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewOwnClosings]));
        $permissions = DB::table('permissions')->orderBy('id')->get()->toArray();
        $grants = DB::table('permission_user')->orderBy('user_id')->orderBy('permission_id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_10_142909_add_financial_report_permissions.php');

        $migration->up();

        $this->assertEquals($permissions, DB::table('permissions')->orderBy('id')->get()->toArray());
        $this->assertEquals($grants, DB::table('permission_user')->orderBy('user_id')->orderBy('permission_id')->get()->toArray());
        $this->assertDatabaseHas('permissions', ['key' => 'reports.view']);
        $this->assertDatabaseHas('permissions', ['key' => 'reports.view_all']);
    }
}
