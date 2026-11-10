<?php

namespace Tests\Feature\Branches;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\SubArea;
use App\Models\Governorate;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_branch_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('branches.index'))
            ->assertOk();
    }

    public function test_the_branch_form_no_longer_places_a_branch_in_a_governorate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $governorate = Governorate::factory()->create();

        $this->actingAs($superAdmin)->post(route('branches.store'), [
            'name' => 'Downtown Branch', 'governorate_id' => $governorate->id,
        ])->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['name' => 'Downtown Branch', 'governorate_id' => null]);
    }

    public function test_editing_a_branch_keeps_its_existing_area_container(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create(['name' => 'Old']);

        $this->actingAs($superAdmin)->put(route('branches.update', $branch), ['name' => 'New', 'is_active' => true])->assertSessionHasNoErrors();

        $this->assertSame([$area->id, 'New'], [$branch->fresh()->area_id, $branch->fresh()->name]);
    }

    public function test_super_admin_can_create_a_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('branches.store'), [
            'name' => 'Downtown Branch',
            'phone' => '555-1000',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('branches.index'));
        $this->assertDatabaseHas('branches', [
            'name' => 'Downtown Branch',
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_update_a_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create(['is_active' => true]);

        $response = $this->actingAs($superAdmin)->put(route('branches.update', $branch), [
            'name' => 'Renamed Branch',
            'phone' => $branch->phone,
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('branches.index'));
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Renamed Branch',
            'is_active' => false,
        ]);
    }

    public function test_branch_admin_cannot_view_branch_index(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)
            ->get(route('branches.index'))
            ->assertForbidden();
    }

    public function test_branch_admin_cannot_create_a_branch(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)
            ->get(route('branches.create'))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->post(route('branches.store'), ['name' => 'New Branch'])
            ->assertForbidden();
    }

    public function test_collector_cannot_view_branches(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)
            ->get(route('branches.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('branches.index'))
            ->assertRedirect(route('login'));
    }

    public function test_the_branches_page_counts_each_branchs_areas_and_offers_no_governorate_filter(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create();
        Branch::factory()->inArea($area)->create(['name' => 'A Branch']);
        SubArea::factory()->count(2)->for($area)->create();

        $this->actingAs($superAdmin)->get(route('branches.index'))->assertInertia(fn ($page) => $page
            ->where('branches.data.0.name', 'A Branch')
            ->where('branches.data.0.sub_areas_count', 2)
            ->where('filterOptions', fn ($groups): bool => collect($groups)->pluck('key')->intersect(['governorate_id', 'area_id'])->isEmpty()));
    }

    public function test_a_view_only_user_is_not_offered_add_or_edit_on_the_branches_page(): void
    {
        $this->seed(PermissionSeeder::class);
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewBranches->value)->firstOrFail());

        $response = $this->actingAs($collector)->get(route('branches.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', false)
            ->where('branches.data.0.canUpdate', false));
    }

    public function test_super_admin_is_offered_add_and_edit_on_the_branches_page(): void
    {
        Branch::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('branches.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', true)
            ->where('branches.data.0.canUpdate', true));
    }
}
