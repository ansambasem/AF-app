<?php

namespace Tests\Feature\Settings;

use App\Enums\ReadingEntryMode;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_the_schedule_defaulting_to_thursday(): void
    {
        $this->travelTo('2026-09-25 10:00:00'); // Friday

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('settings.reading-schedule.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ReadingSchedule')
                ->where('branchScope.all', true)
                ->where('branchScope.name', 'جميع الفروع')
                ->where('setting.reading_day', CarbonInterface::THURSDAY)
                ->where('setting.latestWeekEnd', '2026-09-24')
                ->where('setting.open_days', [CarbonInterface::THURSDAY])
                ->where('setting.mode', 'automatic')
                ->where('setting.opens_at', '00:00')
                ->where('setting.closes_at', '23:59')
                ->where('currentWeeks.'.CarbonInterface::SATURDAY, ['start' => '2026-09-25', 'end' => '2026-09-26'])
                ->where('firstWeeks.'.CarbonInterface::SATURDAY, ['start' => '2026-09-25', 'end' => '2026-09-26']));
    }

    public function test_super_admin_can_change_the_open_days_and_mode(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('settings.reading-schedule.update'), [
                'reading_day' => CarbonInterface::THURSDAY,
                'open_days' => [CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY],
                'mode' => 'closed',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.reading-schedule.edit'));

        $setting = ReadingEntrySetting::sole();
        $this->assertSame([CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY], $setting->open_days);
        $this->assertSame(ReadingEntryMode::Closed, $setting->mode);
        $this->assertTrue($setting->updatedBy->is($superAdmin));
        $this->assertSame(CarbonInterface::THURSDAY, $setting->reading_day);
        $this->assertNull($setting->reading_day_history);
    }

    public function test_moving_the_reading_day_keeps_the_ended_weeks_and_starts_the_first_new_week_after_them(): void
    {
        $this->travelTo('2026-09-30 10:00:00'); // Wednesday — the latest ended week is 18 → 24 Sep

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('settings.reading-schedule.update'), [
                'reading_day' => CarbonInterface::SATURDAY,
                'open_days' => [CarbonInterface::SATURDAY],
                'mode' => 'automatic',
            ])
            ->assertSessionHasNoErrors();

        $setting = ReadingEntrySetting::sole();
        $this->assertSame(CarbonInterface::SATURDAY, $setting->reading_day);
        // The first Saturday week runs from 25 Sep to the coming Saturday.
        $this->assertSame(
            [['reading_day' => CarbonInterface::THURSDAY, 'last_week_end' => '2026-09-24', 'next_week_end' => '2026-10-03']],
            $setting->reading_day_history,
        );
    }

    public function test_company_schedule_overrides_custom_branch_settings_and_preserves_each_branchs_ended_weeks(): void
    {
        $this->travelTo('2026-09-30 10:00:00');
        $following = Branch::factory()->create();
        $custom = Branch::factory()->create();
        $own = ReadingEntrySetting::factory()->forBranch($custom)->forcedOpen()->create([
            'reading_day' => CarbonInterface::SATURDAY,
            'open_days' => [CarbonInterface::SATURDAY],
            'opens_at' => '00:00', 'closes_at' => '23:59', 'assignment_lead_days' => 0,
        ]);
        $actor = User::factory()->superAdmin()->create();
        $staff = User::factory()->dataEntry()->create(['branch_id' => $custom->id]);

        $this->actingAs($actor)->put(route('settings.reading-schedule.update'), [
            'reading_day' => CarbonInterface::WEDNESDAY,
            'open_days' => [CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY, CarbonInterface::FRIDAY],
            'opens_at' => '08:30', 'closes_at' => '17:00', 'assignment_lead_days' => 3,
            'mode' => 'closed',
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.reading-schedule.edit'));

        foreach ([$following, $custom] as $branch) {
            $setting = ReadingEntrySetting::forBranch($branch);
            $this->assertSame(CarbonInterface::WEDNESDAY, $setting->reading_day);
            $this->assertSame([CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY, CarbonInterface::FRIDAY], $setting->open_days);
            $this->assertSame('08:30', substr($setting->opens_at, 0, 5));
            $this->assertSame('17:00', substr($setting->closes_at, 0, 5));
            $this->assertSame(3, $setting->assignment_lead_days);
            $this->assertSame(ReadingEntryMode::Closed, $setting->mode);
            $this->assertTrue($setting->updatedBy->is($actor));
        }

        $this->assertFalse($staff->can('create', MeterReading::class));
        $this->assertSame('2026-09-24', MeterReading::weekEndFor(now()->parse('2026-09-18'), $following)->toDateString());
        $this->assertSame('2026-09-26', MeterReading::weekEndFor(now()->parse('2026-09-20'), $custom)->toDateString());
        $this->assertSame('2026-09-25', MeterReading::latestEndedWeekStart($following)->toDateString());
        $this->assertSame('2026-09-27', MeterReading::latestEndedWeekStart($custom)->toDateString());
        $this->assertSame($own->id, ReadingEntrySetting::ownFor($custom)->id);
        $this->assertDatabaseCount('reading_entry_settings', 2);
    }

    public function test_reapplying_unchanged_company_settings_replaces_a_branchs_custom_settings(): void
    {
        $this->travelTo('2026-09-25 10:00:00');
        ReadingEntrySetting::factory()->forcedClosed()->create(['opens_at' => '08:30', 'closes_at' => '17:00', 'assignment_lead_days' => 2]);
        $branch = Branch::factory()->create();
        ReadingEntrySetting::factory()->forBranch($branch)->forcedOpen()->create(['reading_day' => CarbonInterface::SATURDAY]);

        $this->actingAs(User::factory()->superAdmin()->create())->put(route('settings.reading-schedule.update'), [
            'reading_day' => CarbonInterface::THURSDAY, 'open_days' => [CarbonInterface::THURSDAY], 'mode' => 'closed',
        ])->assertSessionHasNoErrors();

        $setting = ReadingEntrySetting::forBranch($branch);
        $this->assertSame(CarbonInterface::THURSDAY, $setting->reading_day);
        $this->assertSame([CarbonInterface::THURSDAY], $setting->open_days);
        $this->assertSame(ReadingEntryMode::Closed, $setting->mode);
        $this->assertSame('08:30', substr($setting->opens_at, 0, 5));
        $this->assertSame('17:00', substr($setting->closes_at, 0, 5));
        $this->assertSame(2, $setting->assignment_lead_days);
        $this->get(route('settings.reading-schedule.edit'))->assertInertia(fn ($page) => $page
            ->where('branchScope.all', true)
            ->where('branchScope.name', 'جميع الفروع')
            ->where('branchScope.branches', 1));
    }

    public function test_moving_the_reading_day_again_before_a_week_ends_on_it_replaces_the_earlier_move(): void
    {
        $this->travelTo('2026-09-25 10:00:00'); // Friday
        $setting = ReadingEntrySetting::factory()->create();

        $setting->changeReadingDay(CarbonInterface::SATURDAY);
        $setting->changeReadingDay(CarbonInterface::FRIDAY);

        $this->assertSame(CarbonInterface::FRIDAY, $setting->reading_day);
        $this->assertSame(
            [['reading_day' => CarbonInterface::THURSDAY, 'last_week_end' => '2026-09-24', 'next_week_end' => '2026-09-25']],
            $setting->reading_day_history,
        );
    }

    public function test_moving_the_reading_day_back_before_a_week_ends_on_the_new_day_undoes_the_move(): void
    {
        $this->travelTo('2026-09-25 10:00:00'); // Friday
        $setting = ReadingEntrySetting::factory()->create();

        $setting->changeReadingDay(CarbonInterface::SATURDAY);
        $setting->changeReadingDay(CarbonInterface::THURSDAY);

        $this->assertSame(CarbonInterface::THURSDAY, $setting->reading_day);
        $this->assertSame([], $setting->reading_day_history);
    }

    public function test_the_reading_day_is_required_and_must_be_a_day_of_the_week(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('settings.reading-schedule.update'), ['open_days' => [4], 'mode' => 'automatic'])
            ->assertSessionHasErrors(['reading_day' => 'اختر يوم القراءة الأسبوعي.']);

        $this->actingAs($superAdmin)
            ->put(route('settings.reading-schedule.update'), ['reading_day' => 7, 'open_days' => [4], 'mode' => 'automatic'])
            ->assertSessionHasErrors('reading_day');

        $this->assertDatabaseCount('reading_entry_settings', 0);
    }

    public function test_at_least_one_open_day_is_required(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('settings.reading-schedule.update'), ['reading_day' => 4, 'open_days' => [], 'mode' => 'automatic'])
            ->assertSessionHasErrors(['open_days' => 'اختر يومًا واحدًا على الأقل لفتح الإدخال.']);
    }

    public function test_branch_admin_cannot_manage_the_company_default_or_another_branchs_schedule(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $otherBranch = Branch::factory()->create();
        $this->actingAs($branchAdmin);

        $this->get(route('settings.reading-schedule.edit', ['branch' => $otherBranch->id]))->assertForbidden();
        $this->put(route('settings.reading-schedule.update'), ['reading_day' => 4, 'open_days' => [1], 'mode' => 'open'])->assertForbidden();
        $this->put(route('settings.reading-schedule.update'), ['branch_id' => $otherBranch->id, 'reading_day' => 4, 'open_days' => [1], 'mode' => 'open'])->assertForbidden();

        $this->assertDatabaseMissing('reading_entry_settings', ['mode' => 'open']);
    }

    public function test_only_branch_admins_and_the_super_admin_open_the_schedule(): void
    {
        $this->actingAs(User::factory()->dataEntry()->create())->get(route('settings.reading-schedule.edit'))->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->get(route('settings.reading-schedule.edit'))->assertForbidden();
    }

    public function test_a_branch_admin_sees_their_own_branch_following_the_company_until_they_set_it(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs(User::factory()->branchAdmin()->create(['branch_id' => $branch->id]))
            ->get(route('settings.reading-schedule.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ReadingSchedule')
                ->where('branch', ['id' => $branch->id, 'name' => $branch->name])
                ->where('branchScope.all', false)
                ->where('branchScope.name', $branch->name)
                ->where('branches', [])
                ->where('followsCompany', true)
                ->where('setting.reading_day', CarbonInterface::THURSDAY));
    }

    public function test_a_branch_admin_sets_only_their_own_branchs_schedule(): void
    {
        $this->travelTo('2026-09-30 10:00:00'); // Wednesday — the latest ended week is 18 → 24 Sep
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        $this->actingAs($admin)
            ->put(route('settings.reading-schedule.update'), [
                'branch_id' => $branch->id,
                'reading_day' => CarbonInterface::SATURDAY,
                'open_days' => [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY],
                'opens_at' => '08:00',
                'closes_at' => '14:00',
                'mode' => 'automatic',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.reading-schedule.edit', ['branch' => $branch->id]));

        $own = ReadingEntrySetting::ownFor($branch);
        $this->assertSame(CarbonInterface::SATURDAY, $own->reading_day);
        $this->assertSame([CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], $own->open_days);
        $this->assertSame('08:00', substr($own->opens_at, 0, 5));
        $this->assertTrue($own->updatedBy->is($admin));
        // The weeks the branch already read on Thursdays keep their dates, as for the company.
        $this->assertSame(
            [['reading_day' => CarbonInterface::THURSDAY, 'last_week_end' => '2026-09-24', 'next_week_end' => '2026-10-03']],
            $own->reading_day_history,
        );
        $this->assertNull(ReadingEntrySetting::ownFor($other));
        $this->assertSame(CarbonInterface::THURSDAY, ReadingEntrySetting::company()->reading_day);
        $this->assertSame(CarbonInterface::THURSDAY, ReadingEntrySetting::forBranch($other)->reading_day);
    }

    public function test_saving_again_updates_the_branchs_schedule_instead_of_adding_another(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $schedule = ['branch_id' => $branch->id, 'reading_day' => CarbonInterface::THURSDAY, 'open_days' => [CarbonInterface::THURSDAY], 'mode' => 'automatic'];

        $this->actingAs($admin)->put(route('settings.reading-schedule.update'), $schedule)->assertSessionHasNoErrors();
        $this->put(route('settings.reading-schedule.update'), [...$schedule, 'mode' => 'closed'])->assertSessionHasNoErrors();

        $this->assertSame(1, ReadingEntrySetting::where('branch_id', $branch->id)->count());
        $this->assertSame(ReadingEntryMode::Closed, ReadingEntrySetting::forBranch($branch)->mode);
        $this->assertSame(ReadingEntryMode::Automatic, ReadingEntrySetting::company()->mode);
    }

    public function test_the_super_admin_picks_a_branch_and_sees_which_ones_have_their_own_schedule(): void
    {
        $own = Branch::factory()->create(['name' => 'Alpha']);
        $following = Branch::factory()->create(['name' => 'Beta']);
        ReadingEntrySetting::factory()->forBranch($own)->create(['reading_day' => CarbonInterface::SATURDAY]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('settings.reading-schedule.edit', ['branch' => $own->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('branch.id', $own->id)
                ->where('followsCompany', false)
                ->where('setting.reading_day', CarbonInterface::SATURDAY)
                ->where('branchesWithOwn', 1)
                ->where('branches', [
                    ['value' => $own->id, 'label' => 'Alpha', 'hasOwn' => true],
                    ['value' => $following->id, 'label' => 'Beta', 'hasOwn' => false],
                ]));

        $this->get(route('settings.reading-schedule.edit', ['branch' => $following->id]))
            ->assertInertia(fn ($page) => $page->where('followsCompany', true)->where('setting.reading_day', CarbonInterface::THURSDAY));
        $this->get(route('settings.reading-schedule.edit', ['branch' => 'all']))
            ->assertInertia(fn ($page) => $page->where('branch', null)->where('followsCompany', false));
    }

    public function test_a_branch_follows_the_companys_reading_day_until_it_has_its_own(): void
    {
        $this->travelTo('2026-09-25 10:00:00'); // Friday
        $following = Branch::factory()->create();
        $own = Branch::factory()->create();
        ReadingEntrySetting::factory()->forBranch($own)->create(['reading_day' => CarbonInterface::SATURDAY]);

        $this->assertSame('2026-09-24', MeterReading::latestEndedWeekStart($following)->addDays(6)->toDateString());
        // The week containing Friday 25 Sep ends on the next Thursday for the company's schedule, on Saturday for the branch's own.
        $this->assertSame('2026-10-01', MeterReading::weekEndFor(now(), $following)->toDateString());
        $this->assertSame('2026-09-26', MeterReading::weekEndFor(now(), $own)->toDateString());

        ReadingEntrySetting::company()->update(['reading_day' => CarbonInterface::WEDNESDAY]);

        $this->assertSame(CarbonInterface::WEDNESDAY, ReadingEntrySetting::forBranch($following)->reading_day);
        $this->assertSame(CarbonInterface::SATURDAY, ReadingEntrySetting::forBranch($own)->reading_day);
    }

    public function test_each_branch_has_its_own_latest_ended_week(): void
    {
        $this->travelTo('2026-09-25 10:00:00'); // Friday
        $thursdays = Branch::factory()->create(['name' => 'Alpha']);
        $saturdays = Branch::factory()->create(['name' => 'Beta']);
        ReadingEntrySetting::factory()->forBranch($saturdays)->create(['reading_day' => CarbonInterface::SATURDAY]);

        $this->assertSame('2026-09-18', MeterReading::latestEndedWeekStart($thursdays)->toDateString());
        $this->assertSame('2026-09-13', MeterReading::latestEndedWeekStart($saturdays)->toDateString());
        $this->assertSame('2026-09-18', MeterReading::recentWeekOptions($thursdays, 1)[0]['value']);
        $this->assertSame('2026-09-13', MeterReading::recentWeekOptions($saturdays, 1)[0]['value']);
        $this->assertSame([$thursdays->id, $saturdays->id], array_keys(MeterReading::recentWeekOptionsFor(User::factory()->superAdmin()->create(), 1)));
    }

    public function test_a_branchs_entry_window_does_not_close_the_other_branches(): void
    {
        $this->travelTo('2026-09-24 10:00:00'); // Thursday
        $closed = Branch::factory()->create();
        $open = Branch::factory()->create();
        ReadingEntrySetting::factory()->forBranch($closed)->forcedClosed()->create();
        $closedStaff = User::factory()->dataEntry()->create(['branch_id' => $closed->id]);
        $openStaff = User::factory()->dataEntry()->create(['branch_id' => $open->id]);

        $this->assertFalse($closedStaff->can('create', MeterReading::class));
        $this->assertTrue($openStaff->can('create', MeterReading::class));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('create', MeterReading::class));
    }

    public function test_scheduled_days_follow_the_business_timezone(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $setting = ReadingEntrySetting::factory()->create(['open_days' => [CarbonInterface::THURSDAY]]);

        // Wednesday 22:30 UTC is already Thursday 01:30 in Gaza.
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-23 22:30:00', 'UTC')));
        // Thursday 22:30 UTC is already Friday in Gaza.
        $this->assertFalse($setting->isOpen(now()->parse('2026-09-24 22:30:00', 'UTC')));
    }

    public function test_entry_hours_are_saved_and_returned_on_the_schedule_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('settings.reading-schedule.update'), [
                'reading_day' => CarbonInterface::THURSDAY,
                'open_days' => [CarbonInterface::THURSDAY],
                'mode' => 'automatic',
                'opens_at' => '08:30',
                'closes_at' => '17:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.reading-schedule.edit'));

        $setting = ReadingEntrySetting::sole();
        $this->assertSame('08:30', substr($setting->opens_at, 0, 5));
        $this->assertSame('17:00', substr($setting->closes_at, 0, 5));
        $this->get(route('settings.reading-schedule.edit'))
            ->assertInertia(fn ($page) => $page->where('setting.opens_at', '08:30')->where('setting.closes_at', '17:00'));
    }

    public function test_invalid_incomplete_and_reversed_entry_hours_do_not_change_the_schedule(): void
    {
        $setting = ReadingEntrySetting::factory()->create(['opens_at' => '08:30:00', 'closes_at' => '17:00:00']);
        $this->actingAs(User::factory()->superAdmin()->create());
        $schedule = ['reading_day' => 4, 'open_days' => [4], 'mode' => 'automatic'];

        foreach ([
            [['opens_at' => '25:00', 'closes_at' => '17:00'], 'opens_at'],
            [['opens_at' => '08:30', 'closes_at' => '24:00'], 'closes_at'],
            [['opens_at' => '08:30', 'closes_at' => '08:30'], 'closes_at'],
            [['opens_at' => '17:00', 'closes_at' => '08:30'], 'closes_at'],
            [['opens_at' => '08:30'], 'closes_at'],
            [['closes_at' => '17:00'], 'opens_at'],
            [['opens_at' => '', 'closes_at' => ''], ['opens_at', 'closes_at']],
        ] as [$hours, $errors]) {
            $this->put(route('settings.reading-schedule.update'), $schedule + $hours)->assertSessionHasErrors($errors);
        }

        $this->assertSame('08:30', substr($setting->fresh()->opens_at, 0, 5));
        $this->assertSame('17:00', substr($setting->fresh()->closes_at, 0, 5));
    }

    public function test_existing_schedule_updates_without_hours_keep_the_saved_window(): void
    {
        $setting = ReadingEntrySetting::factory()->create(['opens_at' => '08:30:00', 'closes_at' => '17:00:00']);
        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('settings.reading-schedule.update'), ['reading_day' => 4, 'open_days' => [4], 'mode' => 'open'])
            ->assertSessionHasNoErrors();

        $this->assertSame('08:30', substr($setting->fresh()->opens_at, 0, 5));
        $this->assertSame('17:00', substr($setting->fresh()->closes_at, 0, 5));
    }

    public function test_automatic_entry_includes_both_boundary_minutes_in_the_business_timezone(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $setting = ReadingEntrySetting::factory()->create(['opens_at' => '08:30:00', 'closes_at' => '17:00:00']);

        $this->assertFalse($setting->isOpen(now()->parse('2026-09-24 05:29:59', 'UTC')));
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-24 05:30:00', 'UTC')));
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-24 14:00:59', 'UTC')));
        $this->assertFalse($setting->isOpen(now()->parse('2026-09-24 14:01:00', 'UTC')));
        $this->assertFalse($setting->isOpen(now()->parse('2026-09-25 05:30:00', 'UTC')));
    }

    public function test_default_hours_cover_the_whole_day_and_manual_modes_override_hours(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $setting = ReadingEntrySetting::factory()->create();
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-24 00:00:00', 'Asia/Gaza')));
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-24 23:59:59', 'Asia/Gaza')));

        $setting->opens_at = '08:30:00';
        $setting->closes_at = '17:00:00';
        $setting->mode = ReadingEntryMode::Open;
        $this->assertTrue($setting->isOpen(now()->parse('2026-09-25 03:00:00', 'Asia/Gaza')));
        $setting->mode = ReadingEntryMode::Closed;
        $this->assertFalse($setting->isOpen(now()->parse('2026-09-24 10:00:00', 'Asia/Gaza')));
    }
}
