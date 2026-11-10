<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BranchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_branches_and_the_camp_branchs_areas_without_demo_data(): void
    {
        $this->seed(BranchSeeder::class);

        $this->assertSame(['مكتب البريج', 'مكتب الزوايدة', 'مكتب المخيم الجديد', 'مكتب النصيرات', 'مكتب مخيم 2'], Branch::query()->orderBy('name')->pluck('name')->all());
        $campTwo = Branch::query()->where('seed_key', 'camp-two')->sole();
        // Each area belongs to its branch: no governorates or former area level are made.
        $this->assertSame(35, $campTwo->subAreas()->count());
        $this->assertSame(35, SubArea::query()->count());
        $this->assertDatabaseCount('governorates', 0);
        $this->assertDatabaseCount('areas', 0);
        $this->assertSame(0, Branch::query()->whereNotNull('governorate_id')->orWhereNotNull('area_id')->count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('meter_boxes', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_repeated_runs_preserve_existing_records_and_do_not_duplicate_areas(): void
    {
        $this->seed(BranchSeeder::class);
        $branch = Branch::query()->where('name', 'مكتب البريج')->sole();
        $branch->update(['phone' => '0599000000', 'location' => 'مكتب البريج', 'is_active' => false]);
        $branchAttributes = $branch->refresh()->getAttributes();
        $superAdmin = User::factory()->superAdmin()->create();
        $adminAttributes = $superAdmin->refresh()->getAttributes();
        $campTwo = Branch::query()->where('name', 'مكتب مخيم 2')->sole();
        $meterBox = MeterBox::factory()->for($campTwo)->create();

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseCount('branches', 5);
        $this->assertDatabaseCount('sub_areas', 35);
        $this->assertSame($branchAttributes, $branch->fresh()->getAttributes());
        $this->assertSame($adminAttributes, $superAdmin->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('meter_boxes', 1);
        $this->assertModelExists($meterBox);
    }

    public function test_corrects_the_existing_camp_name_without_replacing_its_boxes_or_areas(): void
    {
        $branch = Branch::factory()->create(['name' => 'مخيم 2']);
        $subArea = SubArea::factory()->for($branch)->create(['name' => 'منطقة محفوظة']);
        $meterBox = MeterBox::factory()->for($branch)->create(['sub_area_id' => $subArea->id]);
        $boxAttributes = $meterBox->refresh()->getAttributes();
        $subAreaAttributes = $subArea->refresh()->getAttributes();

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'مكتب مخيم 2', 'seed_key' => 'camp-two']);
        $this->assertSame($boxAttributes, $meterBox->fresh()->getAttributes());
        $this->assertSame($subAreaAttributes, $subArea->fresh()->getAttributes());
        $this->assertSame(36, $branch->subAreas()->count());
        $this->assertDatabaseCount('branches', 5);
        $this->assertDatabaseCount('meter_boxes', 1);
    }

    public function test_uses_the_stable_key_when_a_branch_name_changes(): void
    {
        $this->seed(BranchSeeder::class);
        $branch = Branch::query()->where('seed_key', 'bureij-office')->sole();
        $branch->update(['name' => 'اسم معدل للمكتب']);
        $campTwo = Branch::query()->where('seed_key', 'camp-two')->sole();
        $campTwo->update(['name' => 'اسم معدل لمكتب المخيم']);
        $this->assertSame($campTwo->id, LocationSeeder::branch()->id);

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'seed_key' => 'bureij-office', 'name' => 'مكتب البريج']);
        $this->assertDatabaseHas('branches', ['id' => $campTwo->id, 'seed_key' => 'camp-two', 'name' => 'مكتب مخيم 2']);
        $this->assertDatabaseCount('branches', 5);
    }

    public function test_adopts_an_existing_office_with_the_old_prefix_and_extra_spaces(): void
    {
        $branch = Branch::factory()->create(['name' => ' فرع مكتب البريج ']);

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'seed_key' => 'bureij-office', 'name' => 'مكتب البريج']);
        $this->assertDatabaseCount('branches', 5);
    }

    public function test_ambiguous_legacy_branches_stop_seeding_without_deleting_or_changing_existing_records(): void
    {
        $original = Branch::factory()->create(['name' => 'فرع مكتب البريج']);
        $duplicate = Branch::factory()->create(['name' => ' مكتب البريج']);

        try {
            $this->seed(BranchSeeder::class);
            $this->fail('Ambiguous branches must not be merged automatically.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Multiple existing branches match [bureij-office]; resolve duplicates before seeding.', $exception->getMessage());
        }

        $this->assertDatabaseHas('branches', ['id' => $original->id, 'name' => 'فرع مكتب البريج', 'seed_key' => null]);
        $this->assertDatabaseHas('branches', ['id' => $duplicate->id, 'name' => ' مكتب البريج', 'seed_key' => null]);
        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('sub_areas', 0);
    }
}
