<?php

namespace Tests\Feature\SubAreas;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A branch's areas («منطقة 2», shown as «المنطقة») are managed through the
 * branch: added to the branch's area container, the link the branch already
 * has, renamed in place, never moved to another branch. Names are unique
 * within a branch and free across branches.
 */
class SubAreaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_holds_several_areas_added_through_it(): void
    {
        $container = Area::factory()->create();
        $branch = Branch::factory()->inArea($container)->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        foreach (['الحاج', 'الابراج', 'المدارس'] as $name) {
            $this->post(route('sub-areas.store'), ['name' => $name, 'branch_id' => $branch->id])
                ->assertRedirect(route('branch-areas.index', ['selected' => $branch->id]));
        }

        // Each one in the branch's own container: nothing else is created or relinked.
        $this->assertSame(['الابراج', 'الحاج', 'المدارس'], $branch->subAreas()->orderBy('name')->pluck('name')->all());
        $this->assertSame([$container->id], SubArea::query()->distinct()->pluck('area_id')->all());
        $this->assertSame(1, Area::query()->count());
        $this->assertSame($container->id, $branch->fresh()->area_id);
    }

    public function test_the_same_name_is_allowed_in_two_branches_but_not_twice_in_one(): void
    {
        $first = Branch::factory()->inArea(Area::factory()->create())->create();
        $second = Branch::factory()->inArea(Area::factory()->create())->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('sub-areas.store'), ['name' => 'المدارس', 'branch_id' => $first->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'المدارس', 'branch_id' => $second->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'المدارس', 'branch_id' => $first->id])->assertSessionHasErrors(['name' => 'يوجد في هذا الفرع منطقة بهذا الاسم.']);

        $this->assertSame(1, $first->subAreas()->where('name', 'المدارس')->count());
        $this->assertSame(1, $second->subAreas()->where('name', 'المدارس')->count());
    }

    public function test_renaming_keeps_the_area_in_its_branch_and_its_name_unique_there(): void
    {
        $container = Area::factory()->create();
        $branch = Branch::factory()->inArea($container)->create();
        $area = SubArea::factory()->for($container)->create(['name' => 'قديم']);
        SubArea::factory()->for($container)->create(['name' => 'موجود']);
        $this->actingAs(User::factory()->superAdmin()->create());

        // Asking for another branch changes nothing: an area is renamed, never moved.
        $this->put(route('sub-areas.update', $area), ['name' => 'جديد', 'branch_id' => Branch::factory()->create()->id])
            ->assertRedirect(route('branch-areas.index', ['selected' => $branch->id]));
        $this->put(route('sub-areas.update', $area), ['name' => 'موجود'])->assertSessionHasErrors('name');
        $this->put(route('sub-areas.update', $area), ['name' => 'جديد'])->assertSessionHasNoErrors();

        $this->assertSame(['جديد', $container->id], [$area->fresh()->name, $area->fresh()->area_id]);
    }

    public function test_a_branch_with_no_container_yet_gets_its_own_for_its_first_area(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع جديد', 'area_id' => null]);

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('sub-areas.store'), ['name' => 'الأولى', 'branch_id' => $branch->id])->assertSessionHasNoErrors();

        $branch->refresh();
        $this->assertNotNull($branch->area_id);
        $this->assertSame(['الأولى'], $branch->subAreas()->pluck('name')->all());
    }

    public function test_a_container_two_branches_share_takes_no_new_area_and_is_left_as_it_is(): void
    {
        $shared = Area::factory()->create();
        $first = Branch::factory()->inArea($shared)->create(['name' => 'الأول']);
        Branch::factory()->inArea($shared)->create(['name' => 'الثاني']);
        $existing = SubArea::factory()->for($shared)->create(['name' => 'قائمة']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('sub-areas.store'), ['name' => 'جديدة', 'branch_id' => $first->id])->assertSessionHasErrors('branch_id');

        $this->assertSame([$existing->id], SubArea::query()->pluck('id')->all());
        $this->get(route('branch-areas.index', ['selected' => $first->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('branch.canCreateArea', false)
            ->where('branch.sharedWith', ['الثاني'])
            ->where('review.sharedContainers.0.branches', ['الأول', 'الثاني']));
    }

    public function test_inline_creation_from_the_box_form_returns_the_area_with_its_branch(): void
    {
        $container = Area::factory()->create();
        $branch = Branch::factory()->inArea($container)->create();

        $this->actingAs(User::factory()->superAdmin()->create())->postJson(route('sub-areas.store'), ['name' => 'حي جديد', 'branch_id' => $branch->id])
            ->assertCreated()->assertJsonPath('name', 'حي جديد')->assertJsonPath('area_id', $container->id)->assertJsonPath('branch_ids', [$branch->id]);
    }

    public function test_a_branch_admin_manages_only_their_own_branchs_areas(): void
    {
        $own = Area::factory()->create();
        $other = Area::factory()->create();
        $admin = User::factory()->branchAdmin()->for(Branch::factory()->inArea($own))->create();
        $otherBranch = Branch::factory()->inArea($other)->create();
        $theirs = SubArea::factory()->for($own)->create(['name' => 'داخل الفرع']);
        $elsewhere = SubArea::factory()->for($other)->create(['name' => 'فرع آخر']);
        $this->actingAs($admin);

        $this->post(route('sub-areas.store'), ['name' => 'جديدة', 'branch_id' => $admin->branch_id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'مرفوضة', 'branch_id' => $otherBranch->id])->assertSessionHasErrors(['branch_id' => 'يمكنك إضافة مناطق إلى فرعك فقط.']);
        $this->put(route('sub-areas.update', $theirs), ['name' => 'داخل الفرع 2'])->assertSessionHasNoErrors();
        $this->put(route('sub-areas.update', $elsewhere), ['name' => 'تعديل'])->assertForbidden();
        $this->delete(route('sub-areas.destroy', $elsewhere))->assertForbidden();

        $this->assertSame(['داخل الفرع 2', 'فرع آخر'], [$theirs->fresh()->name, $elsewhere->fresh()->name]);
        $this->assertDatabaseMissing('sub_areas', ['name' => 'مرفوضة']);
    }

    public function test_staff_need_the_permission_to_add_areas_to_their_branch(): void
    {
        $this->seed(PermissionSeeder::class);
        $container = Area::factory()->create();
        $collector = User::factory()->collector()->for(Branch::factory()->inArea($container))->create();

        $this->actingAs($collector)->post(route('sub-areas.store'), ['name' => 'قبل', 'branch_id' => $collector->branch_id])->assertForbidden();

        $collector->permissions()->attach(Permission::where('key', PermissionKey::CreateSubAreas->value)->firstOrFail());
        $this->actingAs($collector->fresh())->post(route('sub-areas.store'), ['name' => 'بعد', 'branch_id' => $collector->branch_id])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sub_areas', ['name' => 'قبل']);
        $this->assertDatabaseHas('sub_areas', ['name' => 'بعد', 'area_id' => $container->id]);
    }

    public function test_an_area_holding_boxes_is_not_deleted_and_an_empty_one_is(): void
    {
        $container = Area::factory()->create();
        $branch = Branch::factory()->inArea($container)->create();
        $used = SubArea::factory()->for($container)->create();
        $empty = SubArea::factory()->for($container)->create();
        MeterBox::factory()->for($branch)->create(['sub_area_id' => $used->id]);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->delete(route('sub-areas.destroy', $used));
        $this->delete(route('sub-areas.destroy', $empty));

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    public function test_the_branch_areas_page_shows_the_branchs_areas_and_lists_undecided_ones_unchanged(): void
    {
        $north = Branch::factory()->inArea($container = Area::factory()->create())->create();
        $south = Branch::factory()->inArea($southContainer = Area::factory()->create())->create();
        $schools = SubArea::factory()->for($container)->create(['name' => 'المدارس']);
        SubArea::factory()->for($southContainer)->create(['name' => 'الحاج']);
        MeterBox::factory()->count(2)->for($north)->create(['sub_area_id' => $schools->id]);
        $orphan = SubArea::factory()->create(['area_id' => null, 'name' => 'بلا فرع']);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('branch-areas.index', ['selected' => $north->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('BranchAreas/Index')
                ->where('branch.id', $north->id)
                ->where('areas', [['id' => $schools->id, 'name' => 'المدارس', 'boxesCount' => 2, 'canUpdate' => true, 'canDelete' => true]])
                ->where('review.unattachedAreas', [['id' => $orphan->id, 'name' => 'بلا فرع', 'boxesCount' => 0]]));
        $this->assertNull($orphan->fresh()->area_id);

        // A branch admin sees only their own branch, whatever is asked for.
        $this->actingAs(User::factory()->branchAdmin()->for($south)->create())->get(route('branch-areas.index', ['selected' => $north->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branch.id', $south->id)->has('branches', 1)->where('areas.0.name', 'الحاج')->where('review', null));
    }

    public function test_guests_and_staff_without_the_permission_cannot_open_the_page(): void
    {
        $this->get(route('branch-areas.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->collector()->create())->get(route('branch-areas.index'))->assertForbidden();
    }
}
