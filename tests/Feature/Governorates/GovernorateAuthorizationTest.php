<?php

namespace Tests\Feature\Governorates;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernorateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_governorate_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('governorates.index'))
            ->assertOk();
    }

    public function test_super_admin_can_create_a_governorate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('governorates.store'), [
            'name' => 'Baghdad',
        ]);

        $governorate = Governorate::where('name', 'Baghdad')->firstOrFail();
        $response->assertRedirect(route('governorates.index', ['selected' => $governorate->id]));
    }

    public function test_a_governorate_can_be_saved_without_changing_its_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $governorate = Governorate::factory()->create(['name' => 'Baghdad']);

        $response = $this->actingAs($superAdmin)->put(route('governorates.update', $governorate), [
            'name' => 'Baghdad',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('governorates.index', ['selected' => $governorate->id]));
    }

    public function test_selecting_a_governorate_returns_its_areas(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $governorate = Governorate::factory()->create();
        $area = Area::factory()->create(['governorate_id' => $governorate->id, 'name' => 'Karrada']);
        SubArea::factory()->count(2)->create(['area_id' => $area->id]);
        Area::factory()->create(); // unrelated, different governorate

        $this->actingAs($superAdmin)
            ->get(route('governorates.index', ['selected' => $governorate->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedGovernorate.id', $governorate->id)
                ->has('selectedGovernorate.areas', 1)
                ->where('selectedGovernorate.areas.0.name', 'Karrada')
                ->where('selectedGovernorate.areas.0.subAreasCount', 2));
    }

    public function test_cannot_create_two_governorates_with_the_same_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Governorate::factory()->create(['name' => 'Baghdad']);

        $this->actingAs($superAdmin)->post(route('governorates.store'), [
            'name' => 'Baghdad',
        ])->assertSessionHasErrors('name');
    }

    public function test_branch_admin_sees_only_their_branch_location_on_the_governorates_page(): void
    {
        $governorate = Governorate::factory()->create(['name' => 'Baghdad']);
        $branchArea = Area::factory()->create(['governorate_id' => $governorate->id, 'name' => 'Karrada']);
        $neighbourArea = Area::factory()->create(['governorate_id' => $governorate->id, 'name' => 'Mansour']);
        Governorate::factory()->create(['name' => 'Basra']);
        SubArea::factory()->create(['area_id' => $branchArea->id, 'name' => 'Block 7']);
        SubArea::factory()->create(['area_id' => $neighbourArea->id, 'name' => 'Block 9']);
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($branchArea))->create();

        $response = $this->actingAs($branchAdmin)->get(route('governorates.index', ['selectedArea' => $neighbourArea->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('scopedToBranch', true)
            ->has('governorates.data', 1)
            ->where('governorates.data.0.name', 'Baghdad')
            ->has('selectedGovernorate.areas', 1)
            ->where('selectedGovernorate.areas.0.name', 'Karrada')
            ->where('selectedArea.name', 'Karrada')
            ->where('selectedArea.canCreateSubArea', true)
            ->has('selectedArea.subAreas', 1)
            ->where('selectedArea.subAreas.0.name', 'Block 7')
            ->where('selectedArea.subAreas.0.canUpdate', true));
    }

    public function test_branch_admin_cannot_create_a_governorate(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->get(route('governorates.create'))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->post(route('governorates.store'), ['name' => 'Baghdad'])
            ->assertForbidden();
    }

    public function test_collector_cannot_view_governorates(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('governorates.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('governorates.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_view_only_user_is_not_offered_add_or_edit_for_governorates_and_areas(): void
    {
        $this->seed(PermissionSeeder::class);
        $governorate = Governorate::factory()->create();
        Area::factory()->create(['governorate_id' => $governorate->id]);
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewGovernorates->value)->firstOrFail());

        $response = $this->actingAs($collector)->get(route('governorates.index', ['selected' => $governorate->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreateGovernorate', false)
            ->where('governorates.data.0.canUpdate', false)
            ->where('selectedGovernorate.canCreateArea', false)
            ->where('selectedGovernorate.areas.0.canUpdate', false));
    }

    public function test_super_admin_is_offered_add_and_edit_for_governorates_and_areas(): void
    {
        $governorate = Governorate::factory()->create();
        Area::factory()->create(['governorate_id' => $governorate->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('governorates.index', ['selected' => $governorate->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreateGovernorate', true)
            ->where('governorates.data.0.canUpdate', true)
            ->where('selectedGovernorate.canCreateArea', true)
            ->where('selectedGovernorate.areas.0.canUpdate', true));
    }
}
