<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
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

    public function test_seeds_the_branches_in_their_first_level_areas_without_demo_data(): void
    {
        $this->seed(BranchSeeder::class);

        $bureij = Branch::query()->where('name', 'مكتب البريج')->sole();
        $this->assertSame('الوسطى', $bureij->governorate->name);
        $this->assertSame('البريج', $bureij->area->name);
        $this->assertSame($bureij->governorate_id, $bureij->area->governorate_id);

        $newCamp = Branch::query()->where('name', 'مكتب المخيم الجديد')->sole();
        $campTwo = Branch::query()->where('name', 'مكتب مخيم 2')->sole();
        $this->assertSame('الوسطى', $campTwo->governorate->name);
        $this->assertSame('مخيم 2', $campTwo->area->name);
        $this->assertSame('الوسطى', $newCamp->governorate->name);
        $this->assertSame('المخيم الجديد', $newCamp->area->name);
        $this->assertSame($campTwo->governorate_id, $newCamp->governorate_id);
        $this->assertNotSame($campTwo->area_id, $newCamp->area_id);
        $this->assertSame($newCamp->governorate_id, $newCamp->area->governorate_id);

        $nuseirat = Branch::query()->where('name', 'مكتب النصيرات')->sole();
        $this->assertSame('الوسطى', $nuseirat->governorate->name);
        $this->assertSame('النصيرات', $nuseirat->area->name);
        $this->assertSame($campTwo->governorate_id, $nuseirat->governorate_id);
        $this->assertSame($nuseirat->governorate_id, $nuseirat->area->governorate_id);
        $this->assertNotSame($newCamp->area_id, $nuseirat->area_id);

        $zawaida = Branch::query()->where('name', 'مكتب الزوايدة')->sole();
        $this->assertSame('الوسطى', $zawaida->governorate->name);
        $this->assertSame('الزوايدة', $zawaida->area->name);

        $this->assertDatabaseCount('branches', 5);
        $this->assertDatabaseCount('governorates', 1);
        $this->assertDatabaseCount('areas', 5);
        $this->assertDatabaseCount('sub_areas', 35);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('meter_boxes', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_repeated_runs_preserve_existing_records_and_do_not_duplicate_locations(): void
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
        $this->assertDatabaseCount('governorates', 1);
        $this->assertDatabaseCount('areas', 5);
        $this->assertDatabaseCount('sub_areas', 35);
        $this->assertSame($branchAttributes, $branch->fresh()->getAttributes());
        $this->assertSame($adminAttributes, $superAdmin->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('meter_boxes', 1);
        $this->assertModelExists($meterBox);
    }

    public function test_corrects_the_existing_camp_name_and_governorate_without_replacing_its_boxes_or_sub_areas(): void
    {
        $governorate = Governorate::factory()->create(['name' => 'النصيرات']);
        $area = Area::factory()->for($governorate)->create(['name' => 'مخيم 2']);
        $branch = Branch::factory()->inArea($area)->create(['name' => 'مخيم 2']);
        $subArea = SubArea::factory()->for($area)->create(['name' => 'منطقة محفوظة']);
        $meterBox = MeterBox::factory()->for($branch)->create(['sub_area_id' => $subArea->id]);
        $boxAttributes = $meterBox->refresh()->getAttributes();
        $subAreaAttributes = $subArea->refresh()->getAttributes();

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'مكتب مخيم 2', 'area_id' => $area->id]);
        $this->assertSame('الوسطى', $branch->fresh()->governorate->name);
        $this->assertSame($branch->fresh()->governorate_id, $area->fresh()->governorate_id);
        $this->assertSame($boxAttributes, $meterBox->fresh()->getAttributes());
        $this->assertSame($subAreaAttributes, $subArea->fresh()->getAttributes());
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
        $governorate = Governorate::factory()->create(['name' => 'الوسطى']);
        $area = Area::factory()->for($governorate)->create(['name' => 'البريج']);
        $branch = Branch::factory()->inArea($area)->create(['name' => ' فرع مكتب البريج ']);

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'seed_key' => 'bureij-office', 'name' => 'مكتب البريج']);
        $this->assertDatabaseCount('branches', 5);
    }

    public function test_ambiguous_legacy_branches_stop_seeding_without_deleting_or_changing_existing_records(): void
    {
        $governorate = Governorate::factory()->create(['name' => 'الوسطى']);
        $area = Area::factory()->for($governorate)->create(['name' => 'البريج']);
        $original = Branch::factory()->inArea($area)->create(['name' => 'فرع مكتب البريج']);
        $duplicate = Branch::factory()->inArea($area)->create(['name' => ' مكتب البريج']);

        try {
            $this->seed(BranchSeeder::class);
            $this->fail('Ambiguous branches must not be merged automatically.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Multiple existing branches match [bureij-office]; resolve duplicates before seeding.', $exception->getMessage());
        }

        $this->assertDatabaseHas('branches', ['id' => $original->id, 'name' => 'فرع مكتب البريج', 'seed_key' => null]);
        $this->assertDatabaseHas('branches', ['id' => $duplicate->id, 'name' => ' مكتب البريج', 'seed_key' => null]);
        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('areas', 1);
    }
}
