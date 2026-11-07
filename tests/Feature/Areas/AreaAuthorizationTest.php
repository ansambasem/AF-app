<?php

namespace Tests\Feature\Areas;

use App\Models\Area;
use App\Models\Governorate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_create_area_form(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('areas.create'))
            ->assertOk();
    }

    public function test_super_admin_can_create_an_area_and_assign_it_to_a_governorate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $governorate = Governorate::factory()->create();

        $response = $this->actingAs($superAdmin)->post(route('areas.store'), [
            'name' => 'Downtown',
            'governorate_id' => $governorate->id,
        ]);

        $response->assertRedirect(route('governorates.index', ['selected' => $governorate->id]));
        $this->assertDatabaseHas('areas', ['name' => 'Downtown', 'governorate_id' => $governorate->id]);
    }

    public function test_super_admin_can_create_an_area_without_a_governorate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('areas.store'), [
            'name' => 'Downtown',
        ]);

        $response->assertRedirect(route('governorates.index'));
        $this->assertDatabaseHas('areas', ['name' => 'Downtown', 'governorate_id' => null]);
    }

    public function test_super_admin_can_update_an_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($superAdmin)->put(route('areas.update', $area), [
            'name' => 'New Name',
        ]);

        $response->assertRedirect(route('governorates.index'));
        $this->assertDatabaseHas('areas', ['id' => $area->id, 'name' => 'New Name']);
    }

    public function test_an_area_can_be_saved_without_changing_its_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create(['name' => 'Karrada']);

        $response = $this->actingAs($superAdmin)->put(route('areas.update', $area), [
            'name' => 'Karrada',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('governorates.index'));
    }

    public function test_super_admin_can_reassign_an_area_to_a_different_governorate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $oldGovernorate = Governorate::factory()->create();
        $newGovernorate = Governorate::factory()->create();
        $area = Area::factory()->create(['governorate_id' => $oldGovernorate->id]);

        $response = $this->actingAs($superAdmin)->put(route('areas.update', $area), [
            'name' => $area->name,
            'governorate_id' => $newGovernorate->id,
        ]);

        $response->assertRedirect(route('governorates.index', ['selected' => $newGovernorate->id, 'selectedArea' => $area->id]));
        $this->assertDatabaseHas('areas', ['id' => $area->id, 'governorate_id' => $newGovernorate->id]);
    }

    public function test_cannot_create_two_areas_with_the_same_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Area::factory()->create(['name' => 'Downtown']);

        $this->actingAs($superAdmin)->post(route('areas.store'), [
            'name' => 'Downtown',
        ])->assertSessionHasErrors('name');
    }

    public function test_branch_admin_cannot_create_an_area(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->get(route('areas.create'))
            ->assertForbidden();

        $this->actingAs($branchAdmin)
            ->post(route('areas.store'), ['name' => 'Downtown'])
            ->assertForbidden();
    }

    public function test_collector_cannot_create_an_area(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('areas.create'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('areas.create'))
            ->assertRedirect(route('login'));
    }
}
