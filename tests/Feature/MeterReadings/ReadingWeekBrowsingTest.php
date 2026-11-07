<?php

namespace Tests\Feature\MeterReadings;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening the weeks before the latest one takes its own permission; without
 * it the sheet and the field app show the latest week only.
 */
class ReadingWeekBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private User $dataEntry;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 24 Sep 2026: the latest week runs 18 → 24 Sep.
        $this->travelTo('2026-09-24 10:00:00');

        $this->dataEntry = User::factory()->dataEntry()->create(['branch_id' => Branch::factory()->create()->id]);
    }

    public function test_data_entry_browses_earlier_weeks_by_default(): void
    {
        $this->assertTrue($this->dataEntry->hasPermission(PermissionKey::BrowseMeterReadingWeeks));

        $this->actingAs($this->dataEntry)
            ->get(route('meter-readings.index', ['week' => '2026-09-11']))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-11')
                ->has('weekOptions', 60));
    }

    public function test_without_the_permission_the_sheet_stays_on_the_latest_week(): void
    {
        $this->dataEntry->permissions()->detach(Permission::idsFor([PermissionKey::BrowseMeterReadingWeeks]));

        $this->actingAs($this->dataEntry->fresh())
            ->get(route('meter-readings.index', ['week' => '2026-09-11']))
            ->assertInertia(fn ($page) => $page
                ->where('week', '2026-09-18')
                ->has('weekOptions', 1)
                ->where('weekOptions.0.value', '2026-09-18'));
    }

    public function test_without_the_permission_the_field_app_stays_on_the_latest_week(): void
    {
        $this->dataEntry->permissions()->detach(Permission::idsFor([PermissionKey::BrowseMeterReadingWeeks]));

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($this->dataEntry->fresh()))
            ->getJson(route('mobile.readings.index', ['week' => '2026-09-11']))
            ->assertOk()
            ->assertJsonPath('week_start', '2026-09-18')
            ->assertJsonCount(1, 'week_options');
    }
}
