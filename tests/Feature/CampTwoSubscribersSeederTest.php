<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\CampTwoSubscribersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CampTwoSubscribersSeederTest extends TestCase
{
    use RefreshDatabase;

    private function prepareBranch(): Branch
    {
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create(['seed_key' => 'camp-two', 'name' => 'مكتب مخيم 2']);
        User::factory()->superAdmin()->create();
        foreach (SubArea::factory()->count(5)->for($area)->create() as $subArea) {
            MeterBox::factory()->count(2)->for($branch)->for($subArea)->create();
        }
        CircuitBreaker::factory()->create(['ampere' => 2, 'minimum_payment' => 20]);
        CircuitBreaker::factory()->create(['ampere' => 16, 'minimum_payment' => 160]);
        Tariff::factory()->residential()->create();

        return $branch;
    }

    public function test_adds_twenty_existing_arabic_names_only_to_camp_two_without_financial_activity(): void
    {
        $branch = $this->prepareBranch();

        $this->seed(CampTwoSubscribersSeeder::class);

        $this->assertDatabaseCount('subscriptions', 20);
        $this->assertDatabaseCount('subscriber_profiles', 20);
        $this->assertDatabaseHas('subscriptions', [
            'legacy_number' => 'SAMPLE-C2-129600', 'full_name' => 'اسامة فضل العثماني',
            'branch_id' => $branch->id, 'status' => 'active', 'minimum_charge' => 20,
        ]);
        $this->assertDatabaseHas('subscriptions', ['legacy_number' => 'SAMPLE-C2-129601', 'minimum_charge' => 160]);
        $this->assertSame(0, Subscription::query()->where('branch_id', '!=', $branch->id)->count());
        $this->assertSame(20, Subscription::query()->distinct()->count('account_number'));
        $this->assertSame(0, Subscription::query()->whereNull('account_number')->count());
        $this->assertSame(0, Subscription::query()->whereNotNull('phone')->count());
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertDatabaseCount('cash_boxes', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('meter_boxes', 10);
        $subscriptions = Subscription::query()->with('meterBox.subArea')->get();
        $this->assertCount(5, $subscriptions->pluck('meterBox.sub_area_id')->unique());
        $this->assertCount(10, $subscriptions->pluck('meter_box_id')->unique());
        foreach ($subscriptions as $subscription) {
            $this->assertSame($branch->id, $subscription->meterBox->branch_id);
            $this->assertSame($branch->area_id, $subscription->meterBox->subArea->area_id);
        }
    }

    public function test_repeating_seeder_preserves_existing_subscriptions_and_does_not_create_duplicates(): void
    {
        $this->prepareBranch();
        $this->seed(CampTwoSubscribersSeeder::class);
        $subscription = Subscription::query()->where('legacy_number', 'SAMPLE-C2-129600')->sole();
        $subscription->update(['full_name' => 'اسم عربي معدل محفوظ', 'notes' => 'ملاحظة محفوظة']);
        $before = Subscription::query()->orderBy('id')->get()->toJson();
        $admin = User::query()->sole()->getAttributes();

        $this->seed(CampTwoSubscribersSeeder::class);

        $this->assertSame($before, Subscription::query()->orderBy('id')->get()->toJson());
        $this->assertSame($admin, User::query()->sole()->getAttributes());
        $this->assertDatabaseCount('subscriptions', 20);
        $this->assertDatabaseCount('subscriber_profiles', 20);
    }

    public function test_missing_meter_boxes_leave_all_subscription_data_unchanged(): void
    {
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create(['seed_key' => 'camp-two']);
        MeterBox::factory()->for($branch)->create();
        $otherArea = Area::factory()->create();
        $otherSubArea = SubArea::factory()->for($otherArea)->create();
        MeterBox::factory()->for($branch)->for($otherSubArea)->create();

        try {
            $this->seed(CampTwoSubscribersSeeder::class);
            $this->fail('A sample subscriber must use an existing camp meter box.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assign camp meter boxes to the branch sub-areas before seeding subscribers.', $exception->getMessage());
        }

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscriber_profiles', 0);
        $this->assertDatabaseCount('tariffs', 0);
    }

    public function test_repairs_only_the_meter_box_of_a_sample_without_a_sub_area(): void
    {
        $branch = $this->prepareBranch();
        $this->seed(CampTwoSubscribersSeeder::class);
        $unassignedBox = MeterBox::factory()->for($branch)->create();
        $subscription = Subscription::query()->where('legacy_number', 'SAMPLE-C2-129600')->sole();
        $subscription->update(['meter_box_id' => $unassignedBox->id, 'full_name' => 'اسم عربي معدل محفوظ']);
        $before = $subscription->fresh()->getAttributes();

        $this->seed(CampTwoSubscribersSeeder::class);

        $subscription->refresh();
        $this->assertNotSame($unassignedBox->id, $subscription->meter_box_id);
        $this->assertSame($branch->area_id, $subscription->meterBox->subArea->area_id);
        unset($before['meter_box_id'], $before['updated_at']);
        $after = $subscription->getAttributes();
        unset($after['meter_box_id'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('subscriptions', 20);
        $this->assertDatabaseCount('subscriber_profiles', 20);
    }

    public function test_a_sample_number_used_in_another_branch_rolls_back_the_whole_batch(): void
    {
        $this->prepareBranch();
        $otherBranch = Branch::factory()->create();
        $other = Subscription::factory()->for($otherBranch)->create([
            'legacy_number' => 'SAMPLE-C2-129601',
            'registered_by' => User::query()->sole()->id,
            'meter_box_id' => MeterBox::factory()->for($otherBranch)->create()->id,
            'circuit_breaker_id' => CircuitBreaker::query()->where('ampere', 2)->sole()->id,
        ]);

        try {
            $this->seed(CampTwoSubscribersSeeder::class);
            $this->fail('An existing subscription must never be reassigned.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A sample subscription number is already used in another branch.', $exception->getMessage());
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $other->id, 'branch_id' => $otherBranch->id]);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscriber_profiles', 1);
    }

    public function test_sample_subscriptions_are_not_created_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            app(CampTwoSubscribersSeeder::class)->run();
            $this->fail('Sample subscribers must not be created in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sample subscribers are only available locally or in tests.', $exception->getMessage());
        }

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
