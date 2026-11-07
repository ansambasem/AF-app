<?php

namespace Tests\Feature;

use App\Enums\TariffCategory;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\TeamTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TeamTestingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_seeder_prepares_a_repeatable_complete_test_dataset(): void
    {
        $this->freezeTime();
        $this->seed(TeamTestingSeeder::class);

        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('branches', 5);
        $this->assertDatabaseCount('meter_boxes', 969);
        $this->assertDatabaseCount('circuit_breakers', 11);
        $this->assertDatabaseCount('tariff_segments', 12);
        $this->assertDatabaseCount('tariffs', 2);
        $this->assertSame(['30.00', '30.00'], Tariff::query()->orderBy('category')->pluck('rate')->all());
        $this->assertDatabaseCount('subscriptions', 20);
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertSame(0, Subscription::query()->whereDoesntHave('meterBox.subArea')->count());
        $this->assertSame(5, Subscription::query()->with('meterBox')->get()->pluck('meterBox.sub_area_id')->unique()->count());
        $users = User::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $subscribers = Subscription::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $tariff = Tariff::query()->where('category', TariffCategory::Residential)->sole();
        $tariff->update(['rate' => 45]);

        $this->seed(TeamTestingSeeder::class);

        $this->assertSame($users, User::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($subscribers, Subscription::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame('45.00', $tariff->fresh()->rate);
        $this->assertDatabaseCount('meter_boxes', 969);
    }

    public function test_team_seeder_keeps_existing_super_administrator_credentials(): void
    {
        $administrator = User::factory()->superAdmin()->create();
        $original = $administrator->fresh()->getRawOriginal();

        $this->seed(TeamTestingSeeder::class);

        $this->assertSame($original, $administrator->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 7);
    }

    public function test_team_seeder_refuses_a_conflicting_administrator_username(): void
    {
        $other = User::factory()->collector()->create(['username' => config('powercollect.super_admin.username')]);

        try {
            $this->seed(TeamTestingSeeder::class);
            $this->fail('The existing account must be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The administrator username is already used by another account.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertSame($other->id, User::query()->sole()->id);
    }

    public function test_team_data_cannot_be_seeded_in_production(): void
    {
        app()->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Team test data is only available locally or in tests.');

        app(TeamTestingSeeder::class)->run();
    }
}
