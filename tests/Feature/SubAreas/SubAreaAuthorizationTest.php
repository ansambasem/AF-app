<?php

namespace Tests\Feature\SubAreas;

use App\Enums\PermissionKey;
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
 * A branch's areas: each belongs to one branch, which manages them. The
 * Super Admin manages any branch's; a branch's staff, given the permission,
 * only their own branch's. Names are unique within a branch.
 */
class SubAreaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_adds_an_area_to_a_branch_and_returns_to_its_areas(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('sub-areas.store'), ['name' => 'الحاج', 'branch_id' => $branch->id])
            ->assertRedirect(route('branch-areas.index', ['selected' => $branch->id]));

        $this->assertDatabaseHas('sub_areas', ['name' => 'الحاج', 'branch_id' => $branch->id]);
    }

    public function test_an_area_always_belongs_to_a_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('sub-areas.store'), ['name' => 'بلا فرع'])
            ->assertSessionHasErrors(['branch_id' => 'اختر الفرع الذي تتبعه المنطقة.']);

        $this->assertDatabaseMissing('sub_areas', ['name' => 'بلا فرع']);
    }

    public function test_a_name_is_unique_within_its_branch_but_another_branch_may_use_it(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        SubArea::factory()->for($first)->create(['name' => 'المدارس']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('sub-areas.store'), ['name' => 'المدارس', 'branch_id' => $first->id])->assertSessionHasErrors(['name' => 'يوجد في هذا الفرع منطقة بهذا الاسم.']);
        $this->post(route('sub-areas.store'), ['name' => 'المدارس', 'branch_id' => $second->id])->assertSessionHasNoErrors();

        $this->assertSame([$first->id, $second->id], SubArea::query()->where('name', 'المدارس')->orderBy('branch_id')->pluck('branch_id')->all());
    }

    public function test_inline_creation_from_the_box_form_returns_the_saved_area(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())->postJson(route('sub-areas.store'), ['name' => 'حي جديد', 'branch_id' => $branch->id])
            ->assertCreated()->assertJsonPath('name', 'حي جديد')->assertJsonPath('branch_id', $branch->id);
    }

    public function test_a_branch_admin_manages_only_their_own_branchs_areas(): void
    {
        [$own, $other] = Branch::factory()->count(2)->create();
        $admin = User::factory()->branchAdmin()->for($own)->create();
        $theirs = SubArea::factory()->for($own)->create(['name' => 'داخل الفرع']);
        $elsewhere = SubArea::factory()->for($other)->create(['name' => 'فرع آخر']);
        $this->actingAs($admin);

        $this->post(route('sub-areas.store'), ['name' => 'جديدة', 'branch_id' => $own->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'مرفوضة', 'branch_id' => $other->id])->assertSessionHasErrors(['branch_id' => 'يمكنك إضافة مناطق إلى فرعك فقط.']);
        $this->put(route('sub-areas.update', $theirs), ['name' => 'داخل الفرع 2', 'branch_id' => $own->id])->assertSessionHasNoErrors();
        $this->put(route('sub-areas.update', $elsewhere), ['name' => 'تعديل', 'branch_id' => $other->id])->assertForbidden();
        $this->delete(route('sub-areas.destroy', $elsewhere))->assertForbidden();

        $this->assertSame('داخل الفرع 2', $theirs->fresh()->name);
        $this->assertSame('فرع آخر', $elsewhere->fresh()->name);
        $this->assertDatabaseMissing('sub_areas', ['name' => 'مرفوضة']);
    }

    public function test_staff_need_the_permission_to_add_areas_to_their_branch(): void
    {
        $this->seed(PermissionSeeder::class);
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();

        $this->actingAs($collector)->post(route('sub-areas.store'), ['name' => 'قبل', 'branch_id' => $branch->id])->assertForbidden();

        $collector->permissions()->attach(Permission::where('key', PermissionKey::CreateSubAreas->value)->firstOrFail());
        $this->actingAs($collector->fresh())->post(route('sub-areas.store'), ['name' => 'بعد', 'branch_id' => $branch->id])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sub_areas', ['name' => 'قبل']);
        $this->assertDatabaseHas('sub_areas', ['name' => 'بعد', 'branch_id' => $branch->id]);
    }

    public function test_an_area_holding_boxes_is_not_deleted_and_an_empty_one_is(): void
    {
        $branch = Branch::factory()->create();
        $used = SubArea::factory()->for($branch)->create();
        $empty = SubArea::factory()->for($branch)->create();
        MeterBox::factory()->for($branch)->create(['sub_area_id' => $used->id]);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->delete(route('sub-areas.destroy', $used));
        $this->delete(route('sub-areas.destroy', $empty));

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    public function test_the_branch_areas_page_shows_the_branchs_areas_with_their_boxes(): void
    {
        [$north, $south] = Branch::factory()->count(2)->create();
        $schools = SubArea::factory()->for($north)->create(['name' => 'المدارس']);
        SubArea::factory()->for($south)->create(['name' => 'الحاج']);
        MeterBox::factory()->count(2)->for($north)->create(['sub_area_id' => $schools->id]);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('branch-areas.index', ['selected' => $north->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('BranchAreas/Index')
                ->where('branch.id', $north->id)
                ->where('areas', [['id' => $schools->id, 'name' => 'المدارس', 'branch_id' => $north->id, 'boxesCount' => 2, 'canUpdate' => true, 'canDelete' => true]])
                ->where('branches', function ($branches) use ($north, $south): bool {
                    $counts = collect($branches)->pluck('areasCount', 'id');

                    return $counts[$north->id] === 1 && $counts[$south->id] === 1;
                }));

        // A branch admin sees only their own branch, whatever is asked for.
        $this->actingAs(User::factory()->branchAdmin()->for($south)->create())->get(route('branch-areas.index', ['selected' => $north->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branch.id', $south->id)->has('branches', 1)->where('areas.0.name', 'الحاج'));
    }

    public function test_areas_left_without_a_branch_are_listed_for_the_super_admin_to_assign(): void
    {
        $branch = Branch::factory()->create();
        $orphan = SubArea::factory()->create(['branch_id' => null, 'name' => 'بلا فرع']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('branch-areas.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('unassignedAreas.0.id', $orphan->id));
        $this->put(route('sub-areas.update', $orphan), ['name' => 'بلا فرع', 'branch_id' => $branch->id])->assertSessionHasNoErrors();

        $this->assertSame($branch->id, $orphan->fresh()->branch_id);
    }

    public function test_guests_and_staff_without_the_permission_cannot_open_the_page(): void
    {
        $this->get(route('branch-areas.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->collector()->create())->get(route('branch-areas.index'))->assertForbidden();
    }
}
