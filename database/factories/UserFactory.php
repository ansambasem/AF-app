<?php

namespace Database\Factories;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /** Legacy named states are explicit test fixtures, not account creation defaults. */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $user->permissions()->sync(Permission::idsFor($user->role->starterPermissions())));
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Collector,
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the user is the company-wide Super Admin.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SuperAdmin,
            'branch_id' => null,
        ]);
    }

    /**
     * Indicate that the user manages a branch.
     */
    public function branchAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::BranchAdmin,
        ]);
    }

    /**
     * Indicate that the user is a collector (the default role).
     */
    public function collector(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Collector,
        ]);
    }

    /**
     * Indicate that the user registers subscriptions and enters readings.
     */
    public function dataEntry(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::DataEntry,
        ]);
    }

    /**
     * Indicate that the user approves weekly meter readings.
     */
    public function accountant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Accountant,
        ]);
    }

    /**
     * Indicate that the user confirms or rejects recorded collections.
     */
    public function financialAuditor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::FinancialAuditor,
        ]);
    }

    /** @param array<int, PermissionKey> $permissions */
    /**
     * Lets the user hand on every permission they hold: the right the
     * Super Admin gives a branch manager, permission by permission.
     */
    public function grantingWhatTheyHold(): static
    {
        return $this->afterCreating(fn (User $user) => DB::table('permission_user')->where('user_id', $user->id)->update(['grantable' => true]));
    }

    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(fn (User $user) => $user->permissions()->syncWithoutDetaching(Permission::idsFor($permissions)));
    }
}
