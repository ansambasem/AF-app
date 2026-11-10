<?php

namespace Tests\Feature\MeterBoxes;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterBoxAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_meter_box_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('meter-boxes.index'))
            ->assertOk();
    }

    public function test_super_admin_can_create_a_meter_box_for_any_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($superAdmin)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-100',
            'location' => 'Main St',
            'branch_id' => $branch->id,
        ]);

        $response->assertRedirect(route('meter-boxes.index'));
        $this->assertDatabaseHas('meter_boxes', [
            'box_number' => 'BOX-100',
            'branch_id' => $branch->id,
        ]);
    }

    public function test_super_admin_can_update_a_meter_box(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $meterBox = MeterBox::factory()->create();

        $response = $this->actingAs($superAdmin)->put(route('meter-boxes.update', $meterBox), [
            'name' => $meterBox->name,
            'box_number' => 'BOX-UPDATED',
            'branch_id' => $meterBox->branch_id,
        ]);

        $response->assertRedirect(route('meter-boxes.index'));
        $this->assertDatabaseHas('meter_boxes', [
            'id' => $meterBox->id,
            'box_number' => 'BOX-UPDATED',
        ]);
    }

    public function test_a_meter_box_can_be_saved_without_changing_its_box_number(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $meterBox = MeterBox::factory()->create(['box_number' => 'BOX-1']);

        $response = $this->actingAs($superAdmin)->put(route('meter-boxes.update', $meterBox), [
            'name' => 'Renamed box',
            'box_number' => 'BOX-1',
            'branch_id' => $meterBox->branch_id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meter_boxes', ['id' => $meterBox->id, 'name' => 'Renamed box']);
    }

    public function test_branch_admin_can_view_the_meter_box_index_without_a_grant(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)
            ->get(route('meter-boxes.index'))
            ->assertOk();
    }

    public function test_branch_admin_creates_meter_boxes_in_their_own_branch_without_a_grant(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $otherBranch = Branch::factory()->create();

        $this->actingAs($branchAdmin)
            ->post(route('meter-boxes.store'), ['name' => 'Street box', 'box_number' => 'BOX-1', 'branch_id' => $otherBranch->id])
            ->assertRedirect(route('meter-boxes.index'));

        $this->assertDatabaseHas('meter_boxes', ['box_number' => 'BOX-1', 'branch_id' => $branchAdmin->branch_id]);
    }

    public function test_branch_admin_can_update_their_own_branchs_meter_box_but_not_another_branchs(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $ownBox = MeterBox::factory()->create(['branch_id' => $branchAdmin->branch_id, 'box_number' => 'BOX-1']);
        $foreignBox = MeterBox::factory()->create(['box_number' => 'BOX-2', 'name' => 'Old name']);

        $this->actingAs($branchAdmin)
            ->put(route('meter-boxes.update', $ownBox), ['name' => 'Renamed box', 'box_number' => 'BOX-1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($branchAdmin)
            ->put(route('meter-boxes.update', $foreignBox), ['name' => 'Hijacked', 'box_number' => 'BOX-2'])
            ->assertForbidden();

        $this->assertDatabaseHas('meter_boxes', ['id' => $ownBox->id, 'name' => 'Renamed box']);
        $this->assertDatabaseHas('meter_boxes', ['id' => $foreignBox->id, 'name' => 'Old name']);
    }

    public function test_collector_cannot_view_meter_boxes(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('meter-boxes.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('meter-boxes.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_view_only_user_is_not_offered_add_or_edit_on_the_meter_boxes_page(): void
    {
        $this->seed(PermissionSeeder::class);
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewMeterBoxes->value)->firstOrFail());
        MeterBox::factory()->create(['branch_id' => $collector->branch_id]);

        $response = $this->actingAs($collector)->get(route('meter-boxes.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', false)
            ->where('meterBoxes.data.0.canUpdate', false));
    }

    public function test_branch_admin_is_offered_add_and_edit_on_the_meter_boxes_page(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        MeterBox::factory()->create(['branch_id' => $branchAdmin->branch_id]);

        $response = $this->actingAs($branchAdmin)->get(route('meter-boxes.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', true)
            ->where('meterBoxes.data.0.canUpdate', true));
    }
}
