<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Closing;
use App\Models\ClosingSetting;
use App\Models\ClosingSettingChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WeeklyBranchPackagesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_packages_are_backfilled_and_future_settings_are_weekly_combined(): void
    {
        $this->freezeTime();
        $migration = require database_path('migrations/2026_10_12_080636_weekly_branch_packages.php');
        $migration->down();
        $branch = Branch::factory()->create();
        $user = User::factory()->branchAdmin()->for($branch)->create();
        $closing = Closing::factory()->for($branch)->create(['workflow_settings' => ['frequency' => 'daily', 'arrangement' => 'separate', 'week_starts_on' => 2, 'cutoff_time' => '18:00']]);
        $received = CashBox::factory()->create(['branch_id' => $branch->id, 'closing_id' => $closing->id, 'status' => 'received', 'received_at' => now()]);
        $pending = CashBox::factory()->create(['branch_id' => $branch->id, 'closing_id' => $closing->id, 'status' => 'pending']);
        ClosingSetting::query()->create(['branch_id' => $branch->id, 'frequency' => 'daily', 'arrangement' => 'separate', 'week_starts_on' => 2, 'cutoff_time' => '18:00']);
        $settings = ['frequency' => 'daily', 'arrangement' => 'separate', 'week_starts_on' => 4, 'cutoff_time' => '17:30'];
        $change = ClosingSettingChange::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'settings' => $settings]);
        $applied = ClosingSettingChange::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'settings' => $settings, 'applied_at' => now()]);

        $migration->up();

        $this->assertDatabaseHas('cash_boxes', ['id' => $received->id, 'received_in_closing_id' => $closing->id]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $pending->id, 'received_in_closing_id' => null]);
        $this->assertDatabaseHas('closing_settings', ['branch_id' => $branch->id, 'frequency' => 'weekly', 'arrangement' => 'combined', 'week_starts_on' => 2, 'cutoff_time' => '18:00']);
        $expectedSettings = [...$settings, 'frequency' => 'weekly', 'arrangement' => 'combined'];
        $normalizedSettings = $change->fresh()->settings;
        $appliedSettings = $applied->fresh()->settings;
        ksort($expectedSettings);
        ksort($normalizedSettings);
        ksort($settings);
        ksort($appliedSettings);
        $this->assertSame($expectedSettings, $normalizedSettings);
        $this->assertSame($settings, $appliedSettings);
        $this->assertSame('daily', $closing->fresh()->workflow_settings['frequency']);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('cash_boxes', 'received_in_closing_id'));
        $this->assertDatabaseCount('cash_boxes', 2);
    }
}
