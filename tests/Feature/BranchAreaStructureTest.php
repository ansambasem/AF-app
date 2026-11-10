<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Branch → area → meter box, on the links that already exist: a branch's
 * areas are those in its area container (`branches.area_id`), a box keeps
 * the area it is in. The box form offers only the chosen branch's areas,
 * refuses an area of another branch, and still allows no area at all.
 */
class BranchAreaStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_box_form_names_each_area_with_its_branch_so_only_the_chosen_branchs_show(): void
    {
        $north = Branch::factory()->inArea($northContainer = Area::factory()->create())->create();
        $south = Branch::factory()->inArea($southContainer = Area::factory()->create())->create();
        $schools = SubArea::factory()->for($northContainer)->create(['name' => 'المدارس']);
        $market = SubArea::factory()->for($southContainer)->create(['name' => 'المدارس']);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('meter-boxes.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subAreas', fn ($areas): bool => collect($areas)->pluck('branch_ids', 'id')->all() === [$schools->id => [$north->id], $market->id => [$south->id]]));

        // A branch's staff are offered their own branch's areas only.
        $this->actingAs(User::factory()->branchAdmin()->for($south)->create())->get(route('meter-boxes.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subAreas', 1)->where('subAreas.0.id', $market->id)->where('currentBranchId', $south->id));
    }

    public function test_a_box_whose_area_is_another_branchs_is_refused_on_create_and_on_edit(): void
    {
        $north = Branch::factory()->inArea($northContainer = Area::factory()->create())->create();
        $south = Branch::factory()->inArea($southContainer = Area::factory()->create())->create();
        $northArea = SubArea::factory()->for($northContainer)->create();
        $southArea = SubArea::factory()->for($southContainer)->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('meter-boxes.store'), ['name' => 'X', 'box_number' => 'N-1', 'branch_id' => $north->id, 'sub_area_id' => $southArea->id])
            ->assertSessionHasErrors(['sub_area_id' => 'المنطقة المختارة لا تتبع فرع الطبلون.']);
        $box = MeterBox::factory()->for($north)->create(['sub_area_id' => $northArea->id]);
        // Moving the box to the other branch while keeping its old area is refused too.
        $this->put(route('meter-boxes.update', $box), ['name' => $box->name, 'box_number' => $box->box_number, 'branch_id' => $south->id, 'sub_area_id' => $northArea->id])
            ->assertSessionHasErrors('sub_area_id');

        $this->assertSame([$north->id, $northArea->id], [$box->fresh()->branch_id, $box->fresh()->sub_area_id]);
        $this->assertDatabaseMissing('meter_boxes', ['box_number' => 'N-1']);
    }

    public function test_a_box_may_still_have_no_area(): void
    {
        $branch = Branch::factory()->inArea(Area::factory()->create())->create();

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('meter-boxes.store'), ['name' => 'بلا منطقة', 'box_number' => 'Z-1', 'branch_id' => $branch->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meter_boxes', ['box_number' => 'Z-1', 'branch_id' => $branch->id, 'sub_area_id' => null]);
    }

    public function test_the_name_index_migration_changes_no_row_or_link(): void
    {
        $migration = $this->migration();
        $migration->down();
        $container = Area::factory()->create();
        $branch = Branch::factory()->inArea($container)->create();
        $area = SubArea::factory()->for($container)->create();
        MeterBox::factory()->for($branch)->create(['sub_area_id' => $area->id]);
        MeterBox::factory()->create(['sub_area_id' => null]);
        $before = $this->snapshot();

        $migration->up();

        $this->assertSame($before, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_10_122421_scope_area_names_to_their_branch.php');
    }

    /** @return array<string, list<array<string, mixed>>> every branch, area and box link as it is now */
    private function snapshot(): array
    {
        return [
            'branches' => DB::table('branches')->orderBy('id')->get(['id', 'name', 'area_id', 'governorate_id'])->map(fn ($row): array => (array) $row)->all(),
            'containers' => DB::table('areas')->orderBy('id')->get(['id', 'name', 'governorate_id'])->map(fn ($row): array => (array) $row)->all(),
            'areas' => DB::table('sub_areas')->orderBy('id')->get(['id', 'name', 'area_id'])->map(fn ($row): array => (array) $row)->all(),
            'boxes' => DB::table('meter_boxes')->orderBy('id')->get(['id', 'name', 'branch_id', 'sub_area_id'])->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
