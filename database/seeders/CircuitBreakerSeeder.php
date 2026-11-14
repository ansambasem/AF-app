<?php

namespace Database\Seeders;

use App\Models\CircuitBreaker;
use Illuminate\Database\Seeder;

class CircuitBreakerSeeder extends Seeder
{
    /**
     * Each circuit breaker's size in amperes and its minimum payment in
     * shekels.
     *
     * @var array<int, int>
     */
    private const MINIMUM_PAYMENTS = [
        2 => 20,
        4 => 20,
        5 => 50,
        6 => 60,
        8 => 80,
        10 => 100,
        12 => 120,
        16 => 160,
        20 => 200,
        24 => 240,
        32 => 320,
    ];

    /**
     * Seed the circuit breakers. Safe to run again: a breaker that exists
     * gets the minimum payment listed here.
     */
    public function run(): void
    {
        foreach (self::MINIMUM_PAYMENTS as $ampere => $minimumPayment) {
            CircuitBreaker::query()->updateOrCreate(['ampere' => $ampere], ['minimum_payment' => $minimumPayment]);
        }
    }
}
