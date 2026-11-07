<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Area;
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

        $area = Area::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => Branch::factory()->inArea($area)->create()->id]);
        $branch = ['branch_id' => $this->branchAdmin->branch_id];
        $this->schools = SubArea::factory()->create(['name' => 'المدارس', 'area_id' => $area->id]);
        $other = SubArea::factory()->create(['name' => 'الحاج', 'area_id' => $area->id]);
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

    public function test_the_filter_menu_offers_both_filters(): void
    {
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('filterOptions', function ($groups): bool {
                $groups = collect($groups);

                return $groups->firstWhere('key', 'sub_area_id')['label'] === 'منطقة 2'
                    && collect($groups->firstWhere('key', 'sub_area_id')['options'])->pluck('label')->all() === ['الحاج', 'المدارس']
                    && collect($groups->firstWhere('key', 'minimum_charge')['options'])->pluck('label')->all() === ['20 شيكل', '35.50 شيكل'];
            }));
    }
}
