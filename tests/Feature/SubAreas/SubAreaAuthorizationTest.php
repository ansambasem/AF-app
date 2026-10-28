<?php

namespace Tests\Feature\SubAreas;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubAreaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_create_sub_area_form(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('sub-areas.create'))
            ->assertOk();
    }

    public function test_super_admin_can_create_a_sub_area_and_assign_it_to_an_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create();

        $response = $this->actingAs($superAdmin)->post(route('sub-areas.store'), [
            'name' => 'Downtown North',
            'area_id' => $area->id,
        ]);

        $response->assertRedirect(route('governorates.index', ['selectedArea' => $area->id]));
        $this->assertDatabaseHas('sub_areas', ['name' => 'Downtown North', 'area_id' => $area->id]);
    }

    public function test_super_admin_can_create_a_sub_area_without_an_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('sub-areas.store'), [
            'name' => 'Downtown North',
        ]);

        $response->assertRedirect(route('governorates.index'));
        $this->assertDatabaseHas('sub_areas', ['name' => 'Downtown North', 'area_id' => null]);
    }

    public function test_inline_creation_returns_the_saved_option_without_redirecting_the_meter_box_form(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $area = Area::factory()->create();

        $response = $this->actingAs($owner)->postJson(route('sub-areas.store'), [
            'name' => 'حي جديد', 'area_id' => $area->id,
        ])->assertCreated()->assertJsonStructure(['id', 'name', 'area_id'])
            ->assertJsonPath('name', 'حي جديد')->assertJsonPath('area_id', $area->id);

        $this->assertDatabaseHas('sub_areas', ['id' => $response->json('id'), 'name' => 'حي جديد', 'area_id' => $area->id]);
    }

    public function test_inline_creation_validates_names_and_the_branch_area(): void
    {
        $area = Area::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();
        $otherArea = Area::factory()->create();
        SubArea::factory()->for($area)->create(['name' => 'حي موجود']);

        $this->actingAs($branchAdmin)->postJson(route('sub-areas.store'), [
            'name' => 'حي جديد', 'area_id' => $area->id,
        ])->assertCreated()->assertJsonPath('area_id', $area->id);
        $this->assertDatabaseHas('sub_areas', ['name' => 'حي جديد', 'area_id' => $area->id]);

        $this->postJson(route('sub-areas.store'), ['name' => 'حي خارج الفرع', 'area_id' => $otherArea->id])
            ->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->assertDatabaseMissing('sub_areas', ['name' => 'حي خارج الفرع']);
        $this->postJson(route('sub-areas.store'), ['name' => 'حي موجود', 'area_id' => $area->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('sub-areas.store'), ['name' => '', 'area_id' => $area->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('sub_areas', 2);
    }

    public function test_inline_creation_requires_authentication_and_create_sub_area_permission(): void
    {
        $area = Area::factory()->create();
        $collector = User::factory()->collector()->withPermissions([PermissionKey::CreateMeterBoxes])->for(Branch::factory()->inArea($area))->create();
        $payload = ['name' => 'حي جديد', 'area_id' => $area->id];

        $this->postJson(route('sub-areas.store'), $payload)->assertUnauthorized();
        $this->actingAs($collector)->postJson(route('sub-areas.store'), $payload)->assertForbidden();
        $this->assertDatabaseMissing('sub_areas', ['name' => 'حي جديد']);
        $this->get(route('meter-boxes.create'))->assertInertia(fn ($page) => $page->where('canCreateSubArea', false));
    }

    public function test_super_admin_can_update_a_sub_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $subArea = SubArea::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($superAdmin)->put(route('sub-areas.update', $subArea), [
            'name' => 'New Name',
        ]);

        $response->assertRedirect(route('governorates.index'));
        $this->assertDatabaseHas('sub_areas', ['id' => $subArea->id, 'name' => 'New Name']);
    }

    public function test_a_sub_area_can_be_saved_without_changing_its_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $subArea = SubArea::factory()->create(['name' => 'Block 7']);

        $response = $this->actingAs($superAdmin)->put(route('sub-areas.update', $subArea), [
            'name' => 'Block 7',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('governorates.index'));
    }

    public function test_super_admin_can_reassign_a_sub_area_to_a_different_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $oldArea = Area::factory()->create();
        $newArea = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $oldArea->id]);

        $response = $this->actingAs($superAdmin)->put(route('sub-areas.update', $subArea), [
            'name' => $subArea->name,
            'area_id' => $newArea->id,
        ]);

        $response->assertRedirect(route('governorates.index', ['selectedArea' => $newArea->id]));
        $this->assertDatabaseHas('sub_areas', ['id' => $subArea->id, 'area_id' => $newArea->id]);
    }

    public function test_cannot_create_two_sub_areas_with_the_same_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        SubArea::factory()->create(['name' => 'Downtown North']);

        $this->actingAs($superAdmin)->post(route('sub-areas.store'), [
            'name' => 'Downtown North',
        ])->assertSessionHasErrors('name');
    }

    public function test_branch_admin_can_create_a_sub_area_in_their_branchs_area(): void
    {
        $area = Area::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();

        $response = $this->actingAs($branchAdmin)->post(route('sub-areas.store'), [
            'name' => 'Block 12',
            'area_id' => $area->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sub_areas', ['name' => 'Block 12', 'area_id' => $area->id]);
    }

    public function test_branch_admin_cannot_create_a_sub_area_in_another_area(): void
    {
        $branchArea = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($branchArea))->create();

        $response = $this->actingAs($branchAdmin)->post(route('sub-areas.store'), [
            'name' => 'Block 12',
            'area_id' => $otherArea->id,
        ]);

        $response->assertSessionHasErrors(['area_id' => 'يمكنك إضافة منطقة 2 داخل منطقة فرعك فقط.']);
        $this->assertDatabaseMissing('sub_areas', ['name' => 'Block 12']);
    }

    public function test_branch_admin_can_rename_a_sub_area_in_their_branchs_area(): void
    {
        $area = Area::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();
        $subArea = SubArea::factory()->create(['name' => 'Old Name', 'area_id' => $area->id]);

        $response = $this->actingAs($branchAdmin)->put(route('sub-areas.update', $subArea), [
            'name' => 'New Name',
            'area_id' => $area->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sub_areas', ['id' => $subArea->id, 'name' => 'New Name']);
    }

    public function test_branch_admin_cannot_edit_a_sub_area_outside_their_branchs_area(): void
    {
        $branchArea = Area::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($branchArea))->create();
        $foreignSubArea = SubArea::factory()->create(['name' => 'Old Name', 'area_id' => Area::factory()]);

        $this->actingAs($branchAdmin)
            ->get(route('sub-areas.edit', $foreignSubArea))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->put(route('sub-areas.update', $foreignSubArea), ['name' => 'New Name', 'area_id' => $branchArea->id])
            ->assertForbidden();
        $this->assertDatabaseHas('sub_areas', ['id' => $foreignSubArea->id, 'name' => 'Old Name']);
    }

    public function test_branch_admin_whose_branch_has_no_area_cannot_create_a_sub_area(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->get(route('sub-areas.create'))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->post(route('sub-areas.store'), ['name' => 'Downtown North'])
            ->assertForbidden();
    }

    public function test_staff_granted_create_sub_areas_can_only_add_them_in_their_branchs_area(): void
    {
        $this->seed(PermissionSeeder::class);
        $branchArea = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $collector = User::factory()->collector()->for(Branch::factory()->inArea($branchArea))->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::CreateSubAreas->value)->firstOrFail());

        $this->actingAs($collector)
            ->post(route('sub-areas.store'), ['name' => 'Inside', 'area_id' => $branchArea->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($collector)
            ->post(route('sub-areas.store'), ['name' => 'Outside', 'area_id' => $otherArea->id])
            ->assertSessionHasErrors('area_id');

        $this->assertDatabaseHas('sub_areas', ['name' => 'Inside', 'area_id' => $branchArea->id]);
        $this->assertDatabaseMissing('sub_areas', ['name' => 'Outside']);
    }

    public function test_collector_cannot_create_a_sub_area(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('sub-areas.create'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('sub-areas.create'))
            ->assertRedirect(route('login'));
    }
}
