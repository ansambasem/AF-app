<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class SubscriptionImportSeeder extends Seeder
{
    /**
     * Import the subscriptions of the old system from `data/subscriptions.csv`
     * into the مخيم 2 branch, registered by its manager (see
     * ImportSubscriptions). Safe to run again: a subscription already imported
     * is kept. A row that cannot be imported — a number used twice in the
     * file, say — is listed and left out.
     */
    public function run(): void
    {
        $this->call(BranchManagerSeeder::class);
        $this->call(TariffSeeder::class);

        $manager = User::query()->where('username', config('powercollect.branch_manager.username'))->first()
            ?? throw new RuntimeException('The branch manager account is missing.');

        Artisan::call('subscriptions:import', [
            'file' => __DIR__.'/data/subscriptions.csv',
            '--user' => $manager->username,
        ]);

        $this->command?->getOutput()->write(Artisan::output());
    }
}
