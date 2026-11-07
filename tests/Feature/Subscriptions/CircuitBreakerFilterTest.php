<?php

namespace Tests\Feature\Subscriptions;

use App\Models\CircuitBreaker;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitBreakerFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $branchAdmin;

    private Subscription $onSmall;

    private Subscription $onLarge;

    private Subscription $withoutBreaker;

    private CircuitBreaker $small;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchAdmin = User::factory()->branchAdmin()->create();
        $this->small = CircuitBreaker::factory()->create(['ampere' => 4]);
        $large = CircuitBreaker::factory()->create(['ampere' => 16]);
        $branch = ['branch_id' => $this->branchAdmin->branch_id];
        $this->onSmall = Subscription::factory()->create([...$branch, 'circuit_breaker_id' => $this->small->id]);
        $this->onLarge = Subscription::factory()->create([...$branch, 'circuit_breaker_id' => $large->id]);
        $this->withoutBreaker = Subscription::factory()->create([...$branch, 'circuit_breaker_id' => null]);
    }

    public function test_the_subscriptions_list_filters_by_circuit_breaker_or_by_having_none(): void
    {
        $listed = fn (string $value) => collect($this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.index', ['filter' => ['circuit_breaker_id' => $value]]))
            ->viewData('page')['props']['subscriptions']['data'])->pluck('id')->all();

        $this->assertSame([$this->onSmall->id], $listed((string) $this->small->id));
        $this->assertSame([$this->withoutBreaker->id], $listed('none'));
    }

    public function test_the_filter_offers_every_breaker_by_size_and_none(): void
    {
        $this->actingAs($this->branchAdmin)->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where(
                'filterOptions',
                fn ($groups) => collect($groups)->firstWhere('key', 'circuit_breaker_id')['options'] === [
                    ['value' => 'none', 'label' => 'بدون قاطع'],
                    ['value' => (string) $this->small->id, 'label' => '4 أمبير'],
                    ['value' => (string) $this->onLarge->circuit_breaker_id, 'label' => '16 أمبير'],
                ],
            ));
    }

    public function test_the_reading_sheet_filters_by_circuit_breaker(): void
    {
        $rows = $this->actingAs($this->branchAdmin)
            ->get(route('meter-readings.index', ['filter' => ['circuit_breaker_id' => (string) $this->small->id]]))
            ->viewData('page')['props']['rows']['data'];

        $this->assertSame([$this->onSmall->id], array_column($rows, 'id'));
    }

    public function test_a_bulk_change_for_every_match_keeps_to_the_circuit_breaker_filter(): void
    {
        $this->actingAs($this->branchAdmin)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge',
            'mode' => 'amount',
            'value' => 77,
            'all' => true,
            'filter' => ['circuit_breaker_id' => (string) $this->small->id],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(77, (float) $this->onSmall->fresh()->minimum_charge);
        $this->assertNotEquals(77, (float) $this->onLarge->fresh()->minimum_charge);
    }

    public function test_a_message_can_go_to_the_subscriptions_without_a_circuit_breaker(): void
    {
        $this->actingAs($this->branchAdmin)
            ->get(route('messages.create', ['kind' => 'custom', 'status' => '', 'circuit_breaker_id' => 'none']))
            ->assertInertia(fn ($page) => $page->reloadOnly('recipients', fn ($reload) => $reload
                ->has('recipients', 1)
                ->where('recipients.0.id', $this->withoutBreaker->id)));
    }
}
