<?php

namespace Tests\Feature\CircuitBreakers;

use App\Enums\PermissionKey;
use App\Models\CircuitBreaker;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CircuitBreakerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_circuit_breaker_index(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('circuit-breakers.index'))
            ->assertOk();
    }

    public function test_super_admin_can_create_a_circuit_breaker(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('circuit-breakers.store'), [
            'ampere' => 4,
            'minimum_payment' => 25.50,
        ]);

        $response->assertRedirect(route('circuit-breakers.index'));
        $this->assertDatabaseHas('circuit_breakers', [
            'ampere' => 4,
            'minimum_payment' => 25.50,
        ]);
    }

    public function test_super_admin_can_update_a_circuit_breaker(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $circuit_breaker = CircuitBreaker::factory()->create(['minimum_payment' => 10]);

        $response = $this->actingAs($superAdmin)->put(route('circuit-breakers.update', $circuit_breaker), [
            'ampere' => 4,
            'minimum_payment' => 30,
        ]);

        $response->assertRedirect(route('circuit-breakers.index'));
        $this->assertDatabaseHas('circuit_breakers', [
            'id' => $circuit_breaker->id,
            'minimum_payment' => 30,
        ]);
    }

    public function test_a_circuit_breaker_can_be_saved_without_changing_its_ampere(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 16, 'minimum_payment' => 10]);

        $response = $this->actingAs($superAdmin)->put(route('circuit-breakers.update', $circuitBreaker), [
            'ampere' => 16,
            'minimum_payment' => 25,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('circuit_breakers', ['id' => $circuitBreaker->id, 'minimum_payment' => 25]);
    }

    public function test_cannot_create_two_circuit_breakers_with_the_same_ampere(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        CircuitBreaker::factory()->create(['ampere' => 4]);

        $this->actingAs($superAdmin)->post(route('circuit-breakers.store'), [
            'ampere' => 4,
            'minimum_payment' => 15,
        ])->assertSessionHasErrors('ampere');
    }

    public function test_two_and_four_ampere_breakers_can_share_the_twenty_shekel_minimum(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->post(route('circuit-breakers.store'), [
            'ampere' => 2, 'minimum_payment' => 20,
        ])->assertSessionHasNoErrors();

        $this->post(route('circuit-breakers.store'), ['ampere' => 4, 'minimum_payment' => 20])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('circuit_breakers', 2);
        $this->assertDatabaseHas('circuit_breakers', ['ampere' => 2, 'minimum_payment' => 20]);
        $this->assertDatabaseHas('circuit_breakers', ['ampere' => 4, 'minimum_payment' => 20]);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_creating_a_breaker_below_two_amperes_is_rejected(int $ampere): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('circuit-breakers.store'), ['ampere' => $ampere, 'minimum_payment' => 20])
            ->assertSessionHasErrors('ampere');

        $this->assertDatabaseCount('circuit_breakers', 0);
    }

    public function test_editing_a_breaker_to_one_ampere_is_rejected_without_changing_its_payment(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $breaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);

        $this->actingAs($superAdmin)->put(route('circuit-breakers.update', $breaker), ['ampere' => 1, 'minimum_payment' => 40])
            ->assertSessionHasErrors('ampere');

        $this->assertSame([4, '20.00'], [$breaker->fresh()->ampere, $breaker->fresh()->minimum_payment]);
    }

    public function test_editing_a_breaker_to_an_existing_size_is_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        CircuitBreaker::factory()->create(['ampere' => 2, 'minimum_payment' => 20]);
        $breaker = CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 20]);

        $this->actingAs($superAdmin)->put(route('circuit-breakers.update', $breaker), ['ampere' => 2, 'minimum_payment' => 40])
            ->assertSessionHasErrors('ampere');

        $this->assertSame([4, '20.00'], [$breaker->fresh()->ampere, $breaker->fresh()->minimum_payment]);
        $this->assertDatabaseCount('circuit_breakers', 2);
    }

    public function test_collector_cannot_view_circuit_breakers(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)
            ->get(route('circuit-breakers.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('circuit-breakers.index'))
            ->assertRedirect(route('login'));
    }

    public function test_view_permission_does_not_allow_writing(): void
    {
        $this->seed(PermissionSeeder::class);
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewCircuitBreakers->value)->firstOrFail());
        $circuitBreaker = CircuitBreaker::factory()->create();

        $this->actingAs($collector)->get(route('circuit-breakers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.viewCircuitBreakers', true));
        $this->post(route('circuit-breakers.store'), ['ampere' => 4, 'minimum_payment' => 20])->assertForbidden();
        $this->put(route('circuit-breakers.update', $circuitBreaker), ['ampere' => 4, 'minimum_payment' => 20])->assertForbidden();
    }

    public function test_navigation_is_hidden_without_permission(): void
    {
        $this->actingAs(User::factory()->collector()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.viewCircuitBreakers', false));
    }

    public function test_circuit_breaker_values_must_be_positive_ampere_and_non_negative_payment(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('circuit-breakers.store'), ['ampere' => 0, 'minimum_payment' => -1])
            ->assertSessionHasErrors(['ampere', 'minimum_payment']);

        $this->assertDatabaseCount('circuit_breakers', 0);
    }

    public function test_circuit_breakers_page_can_be_filtered_by_ampere(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        CircuitBreaker::factory()->create(['ampere' => 4, 'minimum_payment' => 10]);
        CircuitBreaker::factory()->create(['ampere' => 8, 'minimum_payment' => 20]);

        $response = $this->actingAs($superAdmin)->get(route('circuit-breakers.index', ['filter' => ['ampere' => 4]]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('circuitBreakers.data', 1)
            ->where('circuitBreakers.data.0.ampere', 4));
    }

    public function test_a_view_only_user_is_not_offered_add_or_edit_on_the_circuit_breakers_page(): void
    {
        $this->seed(PermissionSeeder::class);
        CircuitBreaker::factory()->create();
        $collector = User::factory()->collector()->create();
        $collector->permissions()->attach(Permission::where('key', PermissionKey::ViewCircuitBreakers->value)->firstOrFail());

        $response = $this->actingAs($collector)->get(route('circuit-breakers.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', false)
            ->where('circuitBreakers.data.0.canUpdate', false));
    }

    public function test_a_branch_admin_can_only_view_circuit_breakers_by_default(): void
    {
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 16, 'minimum_payment' => 10]);
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)->get(route('circuit-breakers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', false)
                ->where('circuitBreakers.data.0.canUpdate', false));
        $this->post(route('circuit-breakers.store'), ['ampere' => 4, 'minimum_payment' => 15])->assertForbidden();
        $this->put(route('circuit-breakers.update', $circuitBreaker), ['ampere' => 16, 'minimum_payment' => 30])->assertForbidden();

        $this->assertDatabaseMissing('circuit_breakers', ['ampere' => 4]);
        $this->assertDatabaseHas('circuit_breakers', ['id' => $circuitBreaker->id, 'minimum_payment' => 10]);
    }

    public function test_a_branch_admin_given_add_and_edit_can_change_circuit_breakers(): void
    {
        $circuitBreaker = CircuitBreaker::factory()->create(['ampere' => 16, 'minimum_payment' => 10]);
        $branchAdmin = User::factory()->branchAdmin()->create();
        $branchAdmin->permissions()->attach(Permission::idsFor([PermissionKey::CreateCircuitBreakers, PermissionKey::UpdateCircuitBreakers]));

        $this->actingAs($branchAdmin)->get(route('circuit-breakers.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', true)
                ->where('circuitBreakers.data.0.canUpdate', true));
        $this->post(route('circuit-breakers.store'), ['ampere' => 4, 'minimum_payment' => 15])->assertRedirect(route('circuit-breakers.index'));
        $this->put(route('circuit-breakers.update', $circuitBreaker), ['ampere' => 16, 'minimum_payment' => 30])->assertRedirect(route('circuit-breakers.index'));

        $this->assertDatabaseHas('circuit_breakers', ['ampere' => 4, 'minimum_payment' => 15]);
        $this->assertDatabaseHas('circuit_breakers', ['id' => $circuitBreaker->id, 'minimum_payment' => 30]);
    }
}
