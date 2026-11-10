<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubAreaAndMinimumChargeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $branchAdmin;

    private SubArea $schools;

    private Subscription $inSchools;

    private Subscription $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $area = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $area->id]);
        $branch = ['branch_id' => $this->branchAdmin->branch_id];
        $this->schools = SubArea::factory()->create(['name' => 'المدارس', 'branch_id' => $area->id]);
        $other = SubArea::factory()->create(['name' => 'الحاج', 'branch_id' => $area->id]);
        $this->inSchools = Subscription::factory()->create([
            ...$branch,
            'minimum_charge' => 20,
            'meter_box_id' => MeterBox::factory()->create([...$branch, 'sub_area_id' => $this->schools->id])->id,
        ]);
        $this->elsewhere = Subscription::factory()->create([
            ...$branch,
            'minimum_charge' => 35.5,
            'meter_box_id' => MeterBox::factory()->create([...$branch, 'sub_area_id' => $other->id])->id,
        ]);
    }

    private function listed(array $filter): array
    {
        return collect($this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.index', ['filter' => $filter]))
            ->viewData('page')['props']['subscriptions']['data'])->pluck('id')->all();
    }

    public function test_the_list_filters_by_منطقة_2(): void
    {
        $this->assertSame([$this->inSchools->id], $this->listed(['sub_area_id' => (string) $this->schools->id]));
    }

    public function test_the_list_filters_by_minimum_charge(): void
    {
        $this->assertSame([$this->elsewhere->id], $this->listed(['minimum_charge' => '35.5']));
        $this->assertSame([$this->inSchools->id], $this->listed(['minimum_charge' => '20']));
    }

    public function test_the_subscriptions_list_offers_no_filter_menu_only_its_search(): void
    {
        // The links and bulk choices that carry filters still narrow the list; the page just has no menu to pick them.
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('filterOptions', []));
        $this->get(route('subscriptions.index', ['search' => 'zzz-no-match']))
            ->assertInertia(fn ($page) => $page->where('subscriptions.total', 0)->where('filters.search', 'zzz-no-match'));
    }
}
