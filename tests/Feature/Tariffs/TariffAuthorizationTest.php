<?php

namespace Tests\Feature\Tariffs;

use App\Enums\PermissionKey;
use App\Enums\TariffCategory;
use App\Models\Permission;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_tariff_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('tariffs.index'))
            ->assertOk();
    }

    public function test_super_admin_can_create_a_tariff(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('tariffs.store'), [
            'category' => TariffCategory::Residential->value,
            'rate' => 25.50,
        ]);

        $response->assertRedirect(route('tariffs.index'));
        $this->assertDatabaseHas('tariffs', [
            'category' => TariffCategory::Residential->value,
            'rate' => 25.50,
        ]);
    }

    public function test_super_admin_can_update_a_tariff(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $tariff = Tariff::factory()->residential()->create(['rate' => 10]);

        $response = $this->actingAs($superAdmin)->put(route('tariffs.update', $tariff), [
            'category' => TariffCategory::Residential->value,
            'rate' => 30,
        ]);

        $response->assertRedirect(route('tariffs.index'));
        $this->assertDatabaseHas('tariffs', [
            'id' => $tariff->id,
            'rate' => 30,
        ]);
    }

    public function test_cannot_create_two_tariffs_with_the_same_category(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Tariff::factory()->residential()->create();

        $this->actingAs($superAdmin)->post(route('tariffs.store'), [
            'category' => TariffCategory::Residential->value,
            'rate' => 15,
        ])->assertSessionHasErrors('category');
    }

    public function test_collector_cannot_view_tariffs(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('tariffs.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('tariffs.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_view_only_user_is_not_offered_add_or_edit_on_the_tariffs_page(): void
    {
        $this->seed(PermissionSeeder::class);
        Tariff::factory()->residential()->create();
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewTariffs->value)->firstOrFail());

        $response = $this->actingAs($collector)->get(route('tariffs.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', false)
            ->where('tariffs.0.canUpdate', false));
    }

    public function test_a_branch_admin_can_only_view_tariffs_by_default(): void
    {
        $tariff = Tariff::factory()->residential()->create(['rate' => 10]);
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)->get(route('tariffs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', false)
                ->where('tariffs.0.canUpdate', false));
        $this->post(route('tariffs.store'), ['category' => TariffCategory::Commercial->value, 'rate' => 15])->assertForbidden();
        $this->put(route('tariffs.update', $tariff), ['category' => TariffCategory::Residential->value, 'rate' => 30])->assertForbidden();

        $this->assertDatabaseMissing('tariffs', ['category' => TariffCategory::Commercial->value]);
        $this->assertDatabaseHas('tariffs', ['id' => $tariff->id, 'rate' => 10]);
    }

    public function test_a_branch_admin_given_add_and_edit_can_change_tariffs(): void
    {
        $tariff = Tariff::factory()->residential()->create(['rate' => 10]);
        $branchAdmin = User::factory()->branchAdmin()->create();
        $branchAdmin->permissions()->attach(Permission::idsFor([PermissionKey::CreateTariffs, PermissionKey::UpdateTariffs]));

        $this->actingAs($branchAdmin)->get(route('tariffs.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', true)
                ->where('tariffs.0.canUpdate', true));
        $this->post(route('tariffs.store'), ['category' => TariffCategory::Commercial->value, 'rate' => 15])->assertRedirect(route('tariffs.index'));
        $this->put(route('tariffs.update', $tariff), ['category' => TariffCategory::Residential->value, 'rate' => 30])->assertRedirect(route('tariffs.index'));

        $this->assertDatabaseHas('tariffs', ['category' => TariffCategory::Commercial->value, 'rate' => 15]);
        $this->assertDatabaseHas('tariffs', ['id' => $tariff->id, 'rate' => 30]);
    }
}
