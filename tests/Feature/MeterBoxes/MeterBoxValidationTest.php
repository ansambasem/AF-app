<?php

namespace Tests\Feature\MeterBoxes;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MeterBoxValidationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['2'])]
    #[TestWith(['2A'])]
    #[TestWith(['A'])]
    #[TestWith(['ب'])]
    public function test_a_name_suffix_accepts_numbers_letters_and_mixed_text_without_changing_the_box_number(string $suffix): void
    {
        $admin = User::factory()->branchAdmin()->create();

        $this->actingAs($admin)->post(route('meter-boxes.store'), [
            'name' => 'camp', 'name_suffix' => $suffix, 'box_number' => '9898',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meter_boxes', ['name' => 'camp', 'name_suffix' => $suffix, 'box_number' => '9898']);
        $this->assertSame('camp '.$suffix.' - (9898)', MeterBox::sole()->label());
    }

    public function test_a_suffix_cannot_repeat_with_the_same_name_even_with_a_different_box_number(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9898']);

        $this->actingAs($admin)->post(route('meter-boxes.store'), [
            'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9897',
        ])->assertSessionHasErrors(['name_suffix' => 'هذه اللاحقة مستخدمة مع اسم الطبلون نفسه.']);
        $this->assertDatabaseCount('meter_boxes', 1);

        $this->actingAs($admin)->post(route('meter-boxes.store'), [
            'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('meter-boxes.store'), [
            'name' => 'other', 'name_suffix' => '2', 'box_number' => '9896',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meter_boxes', ['name' => 'other', 'name_suffix' => '2', 'box_number' => '9896']);
    }

    public function test_the_box_number_still_is_required_and_globally_unique_and_the_suffix_is_optional(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'box_number' => '9898']);

        $this->actingAs($admin)->post(route('meter-boxes.store'), ['name' => 'other', 'name_suffix' => '7'])
            ->assertSessionHasErrors('box_number');
        $this->actingAs($admin)->post(route('meter-boxes.store'), [
            'name' => 'other', 'name_suffix' => '7', 'box_number' => '9898',
        ])->assertSessionHasErrors('box_number');
        $this->assertDatabaseCount('meter_boxes', 1);

        $this->actingAs($admin)->post(route('meter-boxes.store'), ['name' => 'camp', 'box_number' => '9897'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meter_boxes', ['name' => 'camp', 'name_suffix' => null, 'box_number' => '9897']);
    }

    public function test_editing_can_keep_or_change_its_suffix_but_cannot_take_an_existing_name_and_suffix(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $first = MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9898']);
        $second = MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897']);

        $this->actingAs($admin)->put(route('meter-boxes.update', $first), [
            'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9898',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('meter-boxes.update', $second), [
            'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9897',
        ])->assertSessionHasErrors('name_suffix');
        $this->assertSame('2A', $second->fresh()->name_suffix);

        $this->actingAs($admin)->put(route('meter-boxes.update', $second), [
            'name' => 'camp', 'name_suffix' => '7', 'box_number' => '9897',
        ])->assertSessionHasNoErrors();
        $this->assertSame('camp 7 - (9897)', $second->fresh()->label());

        $this->actingAs($admin)->put(route('meter-boxes.update', $second), [
            'name' => 'camp', 'name_suffix' => '', 'box_number' => '9897',
        ])->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->name_suffix);
        $this->assertSame('9897', $second->fresh()->box_number);
    }

    public function test_search_and_subscription_options_show_the_name_suffix_and_independent_box_number(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897']);
        MeterBox::factory()->create(['branch_id' => $admin->branch_id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9898']);

        $this->actingAs($admin)->get(route('meter-boxes.index', ['search' => 'camp 2A']))
            ->assertInertia(fn ($page) => $page
                ->where('meterBoxes.total', 1)
                ->where('meterBoxes.data.0.id', $box->id)
                ->where('meterBoxes.data.0.display_name', 'camp 2A')
                ->where('meterBoxes.data.0.box_number', '9897')
                ->where('meterBoxes.data.0.name_suffix', '2A'));

        $this->actingAs($admin)->getJson(route('meter-boxes.options', ['search' => 'camp 2A']))
            ->assertJsonPath('data.0.label', 'camp 2A - (9897)');
    }

    public function test_the_database_prevents_duplicate_name_suffixes_with_different_box_numbers(): void
    {
        $box = MeterBox::factory()->create(['name' => 'camp', 'name_suffix' => '2', 'box_number' => '9898']);

        $this->expectException(UniqueConstraintViolationException::class);

        MeterBox::factory()->create(['branch_id' => $box->branch_id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '9897']);
    }

    public function test_can_create_a_meter_box_with_a_sub_area_belonging_to_the_branch_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $area = Area::factory()->create();
        $branch = Branch::factory()->create(['area_id' => $area->id]);
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);

        $response = $this->actingAs($superAdmin)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-100',
            'branch_id' => $branch->id,
            'sub_area_id' => $subArea->id,
        ]);

        $response->assertRedirect(route('meter-boxes.index'));
        $this->assertDatabaseHas('meter_boxes', [
            'box_number' => 'BOX-100',
            'sub_area_id' => $subArea->id,
        ]);
    }

    public function test_cannot_create_a_meter_box_with_a_sub_area_from_a_different_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branchArea = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $branch = Branch::factory()->create(['area_id' => $branchArea->id]);
        $subArea = SubArea::factory()->create(['area_id' => $otherArea->id]);

        $response = $this->actingAs($superAdmin)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-101',
            'branch_id' => $branch->id,
            'sub_area_id' => $subArea->id,
        ]);

        $response->assertSessionHasErrors('sub_area_id');
        $this->assertDatabaseMissing('meter_boxes', ['box_number' => 'BOX-101']);
    }

    public function test_can_create_a_meter_box_without_a_sub_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($superAdmin)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-102',
            'branch_id' => $branch->id,
        ]);

        $response->assertRedirect(route('meter-boxes.index'));
        $this->assertDatabaseHas('meter_boxes', ['box_number' => 'BOX-102', 'sub_area_id' => null]);
    }

    public function test_cannot_update_a_meter_box_with_a_sub_area_from_a_different_area(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branchArea = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $branch = Branch::factory()->create(['area_id' => $branchArea->id]);
        $meterBox = MeterBox::factory()->create(['branch_id' => $branch->id]);
        $subArea = SubArea::factory()->create(['area_id' => $otherArea->id]);

        $response = $this->actingAs($superAdmin)->put(route('meter-boxes.update', $meterBox), [
            'name' => $meterBox->name,
            'box_number' => $meterBox->box_number,
            'branch_id' => $branch->id,
            'sub_area_id' => $subArea->id,
        ]);

        $response->assertSessionHasErrors('sub_area_id');
        $this->assertDatabaseHas('meter_boxes', ['id' => $meterBox->id, 'sub_area_id' => null]);
    }

    public function test_data_entry_can_create_a_meter_box_with_a_sub_area_of_their_own_branch_area(): void
    {
        $area = Area::factory()->create();
        $branch = Branch::factory()->create(['area_id' => $area->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $dataEntry->permissions()->attach(
            Permission::create(['key' => PermissionKey::CreateMeterBoxes->value, 'label' => PermissionKey::CreateMeterBoxes->label()]),
        );
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);

        $response = $this->actingAs($dataEntry)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-200',
            'sub_area_id' => $subArea->id,
        ]);

        $response->assertRedirect(route('meter-boxes.index'));
        $this->assertDatabaseHas('meter_boxes', [
            'box_number' => 'BOX-200',
            'branch_id' => $branch->id,
            'sub_area_id' => $subArea->id,
        ]);
    }

    public function test_data_entry_cannot_create_a_meter_box_with_a_sub_area_outside_their_own_branch_area(): void
    {
        $area = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $branch = Branch::factory()->create(['area_id' => $area->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $dataEntry->permissions()->attach(
            Permission::create(['key' => PermissionKey::CreateMeterBoxes->value, 'label' => PermissionKey::CreateMeterBoxes->label()]),
        );
        $subArea = SubArea::factory()->create(['area_id' => $otherArea->id]);

        $response = $this->actingAs($dataEntry)->post(route('meter-boxes.store'), [
            'name' => 'Main Box',
            'box_number' => 'BOX-201',
            'sub_area_id' => $subArea->id,
        ]);

        $response->assertSessionHasErrors('sub_area_id');
        $this->assertDatabaseMissing('meter_boxes', ['box_number' => 'BOX-201']);
    }
}
