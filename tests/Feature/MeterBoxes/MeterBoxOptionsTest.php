<?php

namespace Tests\Feature\MeterBoxes;

use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterBoxOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $dataEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
    }

    public function test_it_offers_only_boxes_of_the_users_own_branch_with_their_label_and_sub_area(): void
    {
        $subArea = SubArea::factory()->create();
        $own = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'sub_area_id' => $subArea->id, 'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897']);
        MeterBox::factory()->create(['branch_id' => Branch::factory()->create()->id, 'box_number' => '5555']);

        $this->actingAs($this->dataEntry)->getJson(route('meter-boxes.options'))
            ->assertOk()
            ->assertExactJson([
                'data' => [['value' => (string) $own->id, 'label' => 'camp 2A - (9897)', 'hint' => $this->branch->name.' · '.$subArea->name, 'sub_area_id' => $subArea->id, 'branch_id' => $this->branch->id]],
                'hasMore' => false,
            ]);
    }

    public function test_a_branch_user_cannot_widen_the_search_to_another_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        MeterBox::factory()->create(['branch_id' => $otherBranch->id, 'box_number' => '5555']);

        $this->actingAs($this->dataEntry)->getJson(route('meter-boxes.options', ['branch_id' => $otherBranch->id]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_super_admin_narrows_by_branch_and_sub_area(): void
    {
        $area = Area::factory()->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        $otherSubArea = SubArea::factory()->create(['area_id' => $area->id]);
        $inSubArea = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'sub_area_id' => $subArea->id, 'box_number' => '100']);
        MeterBox::factory()->create(['branch_id' => $this->branch->id, 'sub_area_id' => $otherSubArea->id, 'box_number' => '101']);
        MeterBox::factory()->create(['branch_id' => Branch::factory()->create()->id, 'sub_area_id' => $subArea->id, 'box_number' => '102']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson(route('meter-boxes.options'))->assertJsonCount(3, 'data');
        $this->getJson(route('meter-boxes.options', ['branch_id' => $this->branch->id]))->assertJsonCount(2, 'data');
        $this->getJson(route('meter-boxes.options', ['branch_id' => $this->branch->id, 'sub_area_id' => $subArea->id]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', (string) $inSubArea->id);
    }

    public function test_it_searches_name_suffix_and_number_and_lists_an_exact_number_first(): void
    {
        MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897']);
        MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'club', 'name_suffix' => null, 'box_number' => '10']);
        $exact = MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'school', 'name_suffix' => null, 'box_number' => '1']);
        MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => 'hall', 'name_suffix' => null, 'box_number' => '11']);

        $this->actingAs($this->dataEntry);

        $this->getJson(route('meter-boxes.options', ['search' => 'camp 2A']))->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'camp 2A - (9897)');
        $this->getJson(route('meter-boxes.options', ['search' => '9897']))->assertJsonCount(1, 'data');
        $this->getJson(route('meter-boxes.options', ['search' => 'nothing like it']))->assertJsonCount(0, 'data');
        $this->getJson(route('meter-boxes.options', ['search' => '1']))
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.value', (string) $exact->id);
    }

    public function test_one_search_offers_at_most_thirty_boxes_and_says_when_there_are_more(): void
    {
        MeterBox::factory()->count(31)->sequence(fn ($sequence) => ['branch_id' => $this->branch->id, 'box_number' => 'B'.str_pad((string) $sequence->index, 3, '0', STR_PAD_LEFT)])->create();

        $this->actingAs($this->dataEntry)->getJson(route('meter-boxes.options'))
            ->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('hasMore', true);
        $this->getJson(route('meter-boxes.options', ['search' => 'B03']))
            ->assertJsonCount(1, 'data')->assertJsonPath('hasMore', false);
    }

    public function test_it_needs_access_to_subscriptions(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($collector)->getJson(route('meter-boxes.options'))->assertForbidden();
        auth()->logout();
        $this->getJson(route('meter-boxes.options'))->assertUnauthorized();
    }

    public function test_opened_directly_in_the_browser_it_goes_to_the_subscriptions_page_instead_of_showing_json(): void
    {
        $this->actingAs($this->dataEntry)->get(route('meter-boxes.options'))->assertRedirect(route('subscriptions.index'));
    }

    public function test_a_boxs_subscriptions_opened_directly_in_the_browser_go_to_the_meter_boxes_page(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $admin->branch_id]);

        $this->actingAs($admin)->get(route('meter-boxes.subscriptions.index', $box))->assertRedirect(route('meter-boxes.index'));
        $this->getJson(route('meter-boxes.subscriptions.index', $box))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_another_branchs_box_is_still_not_found_for_a_browser_visit(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create();

        $this->actingAs($admin)->get(route('meter-boxes.subscriptions.index', $box))->assertNotFound();
    }
}
