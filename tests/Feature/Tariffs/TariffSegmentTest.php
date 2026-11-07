<?php

namespace Tests\Feature\Tariffs;

use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffSegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_adds_a_customer_segment_that_belongs_to_no_tariff(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('tariff-segments.store'), ['name' => 'مساجد'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tariffs.index'));

        $this->assertDatabaseHas('tariff_segments', ['name' => 'مساجد']);
    }

    public function test_a_segment_name_is_rejected_twice(): void
    {
        TariffSegment::factory()->create(['name' => 'مدارس']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('tariff-segments.store'), ['name' => 'مدارس'])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('tariff_segments', 1);
    }

    public function test_a_segment_can_be_renamed_but_not_to_an_existing_name(): void
    {
        $segment = TariffSegment::factory()->create(['name' => 'مسجد']);
        TariffSegment::factory()->create(['name' => 'مدارس']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->put(route('tariff-segments.update', $segment), ['name' => 'مدارس'])->assertSessionHasErrors('name');
        $this->put(route('tariff-segments.update', $segment), ['name' => 'مساجد'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tariffs.index'));

        $this->assertDatabaseHas('tariff_segments', ['id' => $segment->id, 'name' => 'مساجد']);
    }

    public function test_a_branch_admin_without_tariff_permissions_cannot_add_or_rename_segments(): void
    {
        $segment = TariffSegment::factory()->create(['name' => 'مساجد']);
        $this->actingAs(User::factory()->branchAdmin()->create());

        $this->post(route('tariff-segments.store'), ['name' => 'مدارس'])->assertForbidden();
        $this->put(route('tariff-segments.update', $segment), ['name' => 'مستشفيات'])->assertForbidden();

        $this->assertDatabaseHas('tariff_segments', ['id' => $segment->id, 'name' => 'مساجد']);
        $this->assertDatabaseCount('tariff_segments', 1);
    }

    public function test_the_tariffs_page_lists_the_segments_with_their_subscription_counts_across_tariffs(): void
    {
        $residential = Tariff::factory()->residential()->create();
        $commercial = Tariff::factory()->commercial()->create();
        $segment = TariffSegment::factory()->create(['name' => 'مساجد']);
        Subscription::factory()->create(['tariff_id' => $residential->id, 'tariff_segment_id' => $segment->id]);
        Subscription::factory()->create(['tariff_id' => $commercial->id, 'tariff_segment_id' => $segment->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('tariffs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreateSegment', true)
                ->where('segments.0.name', 'مساجد')
                ->where('segments.0.subscriptionsCount', 2)
                ->where('segments.0.canUpdate', true));
    }
}
