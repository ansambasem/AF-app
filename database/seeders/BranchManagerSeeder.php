<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class BranchManagerSeeder extends Seeder
{
    /**
     * Seed the manager (a branch admin) of the مخيم 2 branch, with the name,
     * username and password from `config/powercollect.php`. Safe to run
     * again: an account with that username is kept.
     */
    public function run(): void
    {
        $this->call(LocationSeeder::class);

        $manager = config('powercollect.branch_manager');

        if (User::query()->where('username', $manager['username'])->exists()) {
            return;
        }

        $password = $manager['password'];

        if (! $password) {
            if (app()->environment('production')) {
                throw new RuntimeException('BRANCH_MANAGER_PASSWORD must be set before seeding in production.');
            }

            $password = 'password';
        }

        User::factory()->branchAdmin()->create([
            'name' => $manager['name'],
            'username' => $manager['username'],
            'password' => $password,
            'branch_id' => LocationSeeder::branch()->id,
        ]);
    }
}
