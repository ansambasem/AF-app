<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeterBoxFilterOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<string, array<int, MeterBox>> */
    private array $boxes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();

        foreach (['camp', 'club', 'school'] as $name) {
            foreach (range(1, 40) as $suffix) {
                $this->boxes[$name][] = MeterBox::factory()->create([
                    'branch_id' => $this->branch->id,
                    'name' => $name,
                    'name_suffix' => (string) $suffix,
                    'box_number' => $name.'-'.$suffix,
                ]);
            }
        }

        MeterBox::factory()->create(['branch_id' => $this->branch->id, 'name' => null, 'name_suffix' => null, 'box_number' => 'unnamed']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'subscriptions' => ['subscriptions.index', 'filterOptions'],
            'meter readings' => ['meter-readings.index', 'filterOptions'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_page_lists_the_box_names_but_none_of_their_boxes_until_a_name_is_picked(string $route, string $prop): void
    {
        $this->actingAs($this->superAdmin())->get(route($route))
            ->assertInertia(fn ($page) => $page->where($prop, function ($groups): bool {
                $groups = collect($groups);

                return $groups->firstWhere('key', 'meter_box_name')['options'] !== []
                    && collect($groups->firstWhere('key', 'meter_box_name')['options'])->pluck('label')->all() === ['camp', 'club', 'school']
                    && $groups->firstWhere('key', 'meter_box_id')['options'] === []
                    && $groups->firstWhere('key', 'meter_box_id')['dependsOn'] === 'meter_box_name';
            }));
    }

    #[DataProvider('pages')]
    public function test_picking_a_name_lists_only_that_names_boxes(string $route, string $prop): void
    {
        $this->actingAs($this->superAdmin())->get(route($route, ['filter' => ['meter_box_name' => 'club']]))
            ->assertInertia(fn ($page) => $page->where($prop, function ($groups): bool {
                $numbers = collect(collect($groups)->firstWhere('key', 'meter_box_id')['options']);

                return $numbers->count() === 40 && $numbers->pluck('parent')->unique()->all() === ['club'];
            }));
    }

    #[DataProvider('pages')]
    public function test_a_link_that_carries_only_a_box_lists_the_boxes_of_its_name(string $route, string $prop): void
    {
        $box = $this->boxes['school'][5];

        $this->actingAs($this->superAdmin())->get(route($route, ['filter' => ['meter_box_id' => $box->id]]))
            ->assertInertia(fn ($page) => $page->where($prop, function ($groups) use ($box): bool {
                $numbers = collect(collect($groups)->firstWhere('key', 'meter_box_id')['options']);

                return $numbers->count() === 40
                    && $numbers->pluck('parent')->unique()->all() === ['school']
                    && $numbers->contains('value', (string) $box->id);
            }));
    }

    public function test_a_branch_user_is_offered_only_the_names_and_boxes_of_their_own_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        MeterBox::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'hospital', 'name_suffix' => '1', 'box_number' => 'h-1']);
        $user = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($user)->get(route('subscriptions.index', ['filter' => ['meter_box_name' => 'hospital']]))
            ->assertInertia(fn ($page) => $page->where('filterOptions', function ($groups): bool {
                $groups = collect($groups);

                return collect($groups->firstWhere('key', 'meter_box_name')['options'])->pluck('label')->all() === ['camp', 'club', 'school']
                    && $groups->firstWhere('key', 'meter_box_id')['options'] === [];
            }));
    }

    public function test_a_selected_branch_is_not_repeated_in_each_box_number_label(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('subscriptions.index', ['filter' => ['branch_id' => $this->branch->id, 'meter_box_name' => 'club']]))
            ->assertInertia(fn ($page) => $page->where('filterOptions', function ($groups): bool {
                $numbers = collect(collect($groups)->firstWhere('key', 'meter_box_id')['options']);

                return $numbers->firstWhere('value', (string) $this->boxes['club'][0]->id)['label'] === '1 (club-1)'
                    && $numbers->firstWhere('value', (string) $this->boxes['club'][9]->id)['label'] === '10 (club-10)';
            }));
    }

    public function test_box_numbers_keep_their_branch_context_while_no_branch_is_selected(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('subscriptions.index', ['filter' => ['meter_box_name' => 'club']]))
            ->assertInertia(fn ($page) => $page->where('filterOptions', function ($groups): bool {
                $numbers = collect(collect($groups)->firstWhere('key', 'meter_box_id')['options']);

                return $numbers->firstWhere('value', (string) $this->boxes['club'][0]->id)['label'] === '1 (club-1) — '.$this->branch->name;
            }));
    }

    public function test_the_options_do_not_grow_with_the_number_of_boxes(): void
    {
        $sizes = [];

        foreach ([0, 300] as $extra) {
            MeterBox::factory()->count($extra)->sequence(fn ($sequence) => [
                'branch_id' => $this->branch->id,
                'name' => 'camp',
                'name_suffix' => 'x'.$sequence->index.'-'.$extra,
                'box_number' => 'extra-'.$extra.'-'.$sequence->index,
            ])->create();

            $sizes[] = strlen(json_encode($this->actingAs($this->superAdmin())->get(route('subscriptions.index'))->viewData('page')['props']['filterOptions']));
        }

        $this->assertSame($sizes[0], $sizes[1]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }
}
