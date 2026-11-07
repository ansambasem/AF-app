<?php

namespace Tests\Feature;

use App\Models\Subscription;
use Database\Seeders\SubscriptionImportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionImportSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_the_old_system_subscriptions_into_the_camp_2_branch(): void
    {
        $this->seed(SubscriptionImportSeeder::class);

        // 160 rows; the second use of 133163 and of 133168 is left out.
        $this->assertSame(158, Subscription::query()->count());
        $first = Subscription::query()->where('legacy_number', '129600')->sole();
        $this->assertSame('اسامة فضل العثماني', $first->full_name);
        $this->assertSame(123.05, $first->balance());
        $this->assertSame('رشدي عليان سالم خطاب', Subscription::query()->where('legacy_number', '133163')->sole()->full_name);
        $this->assertSame('monthly', Subscription::query()->where('legacy_number', '140332')->sole()->accounting_type->value);
        $this->assertSame(1, Subscription::query()->distinct()->count('branch_id'));

        $this->seed(SubscriptionImportSeeder::class);

        $this->assertSame(158, Subscription::query()->count());
    }
}
