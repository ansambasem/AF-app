<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MeterBox;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Moving the areas (formerly «منطقة 2») under their branch: each gets the
 * one branch that sat in its former area, or else the one branch its boxes
 * belong to; one with neither is left for the Super Admin to assign. No row
 * is deleted, and the governorates and former areas stay as they were.
 */
class MoveAreasUnderBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require database_path('migrations/2026_11_10_100629_move_areas_under_branches.php');
        // The database as it was before: areas under a governorate's area, not under a branch.
        $this->migration->down();
    }

    public function test_each_area_moves_to_the_one_branch_it_belongs_to_and_nothing_is_lost(): void
    {
        $governorate = DB::table('governorates')->insertGetId(['name' => 'الوسطى', 'created_at' => now(), 'updated_at' => now()]);
        $camp = DB::table('areas')->insertGetId(['name' => 'مخيم 2', 'governorate_id' => $governorate, 'created_at' => now(), 'updated_at' => now()]);
        $shared = DB::table('areas')->insertGetId(['name' => 'مشتركة', 'governorate_id' => $governorate, 'created_at' => now(), 'updated_at' => now()]);
        $campBranch = Branch::factory()->create();
        DB::table('branches')->where('id', $campBranch->id)->update(['area_id' => $camp, 'governorate_id' => $governorate]);
        [$first, $second] = Branch::factory()->count(2)->create();
        DB::table('branches')->whereIn('id', [$first->id, $second->id])->update(['area_id' => $shared]);

        $byBranch = $this->area('الحاج', $camp);
        $byBoxes = $this->area('الابراج', $shared);
        MeterBox::factory()->for($second)->count(2)->create(['sub_area_id' => $byBoxes]);
        $undecided = $this->area('مجهولة', $shared);
        MeterBox::factory()->for($first)->create(['sub_area_id' => $undecided]);
        MeterBox::factory()->for($second)->create(['sub_area_id' => $undecided]);
        $orphan = $this->area('بلا منطقة', null);
        $before = DB::table('sub_areas')->orderBy('id')->get(['id', 'name', 'area_id'])->map(fn ($row) => (array) $row)->all();
        $boxes = DB::table('meter_boxes')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        ob_start();
        $this->migration->up();
        $report = (string) ob_get_clean();

        $this->assertSame([$byBranch => $campBranch->id, $byBoxes => $second->id, $undecided => null, $orphan => null], DB::table('sub_areas')->orderBy('id')->pluck('branch_id', 'id')->all());
        $this->assertStringContainsString("#{$undecided} مجهولة", $report);
        $this->assertStringContainsString("#{$orphan} بلا منطقة", $report);
        // Nothing deleted or changed but the new link.
        $this->assertSame($before, DB::table('sub_areas')->orderBy('id')->get(['id', 'name', 'area_id'])->map(fn ($row) => (array) $row)->all());
        $this->assertSame($boxes, DB::table('meter_boxes')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, DB::table('governorates')->count());
        $this->assertSame(2, DB::table('areas')->count());
        $this->assertSame($camp, DB::table('branches')->where('id', $campBranch->id)->value('area_id'));
    }

    public function test_afterwards_a_name_is_unique_within_a_branch_only(): void
    {
        $this->migration->up();
        [$first, $second] = Branch::factory()->count(2)->create();

        DB::table('sub_areas')->insert([['name' => 'المدارس', 'branch_id' => $first->id], ['name' => 'المدارس', 'branch_id' => $second->id]]);
        $this->assertTrue(Schema::hasColumn('sub_areas', 'branch_id'));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('sub_areas')->insert(['name' => 'المدارس', 'branch_id' => $first->id]);
    }

    private function area(string $name, ?int $formerArea): int
    {
        return DB::table('sub_areas')->insertGetId(['name' => $name, 'area_id' => $formerArea, 'created_at' => now(), 'updated_at' => now()]);
    }
}
