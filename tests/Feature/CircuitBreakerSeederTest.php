<?php

namespace Tests\Feature;

use App\Models\CircuitBreaker;
use Database\Seeders\CircuitBreakerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitBreakerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_requested_sizes_with_their_minimum_payments(): void
    {
        $this->seed(CircuitBreakerSeeder::class);

        $this->assertSame([
            2 => '20.00',
            4 => '20.00',
            5 => '50.00',
            6 => '60.00',
            8 => '80.00',
            10 => '100.00',
            12 => '120.00',
            16 => '160.00',
            20 => '200.00',
            24 => '240.00',
            32 => '320.00',
        ], CircuitBreaker::query()->orderBy('ampere')->pluck('minimum_payment', 'ampere')->all());
    }

    public function test_repeated_runs_update_payments_without_replacing_or_duplicating_breakers(): void
    {
        $breaker = CircuitBreaker::factory()->create(['ampere' => 6, 'minimum_payment' => 10]);
        $other = CircuitBreaker::factory()->create(['ampere' => 40, 'minimum_payment' => 400]);
        $this->seed(CircuitBreakerSeeder::class);
        $ids = CircuitBreaker::query()->orderBy('ampere')->pluck('id', 'ampere')->all();

        $this->seed(CircuitBreakerSeeder::class);

        $this->assertSame($ids, CircuitBreaker::query()->orderBy('ampere')->pluck('id', 'ampere')->all());
        $this->assertDatabaseHas('circuit_breakers', ['id' => $breaker->id, 'ampere' => 6, 'minimum_payment' => 60]);
        $this->assertDatabaseHas('circuit_breakers', ['id' => $other->id, 'ampere' => 40, 'minimum_payment' => 400]);
        $this->assertDatabaseCount('circuit_breakers', 12);
    }
}
