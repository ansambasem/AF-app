<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PageLoadingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_table_only_reload_does_not_load_the_shared_user_profile(): void
    {
        $actor = User::factory()->branchAdmin()->create(['user_type_id' => UserType::factory()]);
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($actor)->getJson(route('subscriptions.index'), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Subscriptions/Index',
            'X-Inertia-Partial-Data' => 'filters',
        ])->assertOk()->assertJsonMissingPath('props.auth')->assertJsonMissingPath('props.can');

        $profileQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "user_types"'));
        DB::disableQueryLog();
        $this->assertCount(0, $profileQueries);
    }

    public function test_subscription_reading_history_does_not_query_each_readings_parent_or_successors(): void
    {
        $this->freezeTime();
        $actor = User::factory()->superAdmin()->create();
        $subscriptions = Subscription::factory()->count(3)->create();
        foreach ($subscriptions as $subscription) {
            $latest = MeterReading::latestEndedWeekStart($subscription->branch_id);
            for ($week = 0; $week < 4; $week++) {
                MeterReading::factory()->for($subscription)->create([
                    'week_start' => $latest->copy()->subWeeks($week), 'recorded_by' => $actor->id,
                ]);
            }
        }
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($actor)->getJson(route('subscriptions.index'), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Subscriptions/Index',
            'X-Inertia-Partial-Data' => 'subscriptions,filters',
        ])->assertOk()->assertJsonCount(3, 'props.subscriptions.data')
            ->assertJsonCount(4, 'props.subscriptions.data.0.meterReadings');

        $rowQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from "(?:subscriptions|meter_readings)" where "(?:subscriptions|meter_readings)"\.(?:"id"|"subscription_id") = \?/', $query['query']) === 1);
        DB::disableQueryLog();
        $this->assertCount(0, $rowQueries);
    }

    public function test_the_weekly_sheet_hydrates_only_the_selected_and_previous_reading(): void
    {
        $this->freezeTime();
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $latest = MeterReading::latestEndedWeekStart($subscription->branch_id);
        for ($week = 0; $week < 12; $week++) {
            MeterReading::factory()->for($subscription)->create([
                'week_start' => $latest->copy()->subWeeks($week), 'recorded_by' => $actor->id,
                'previous_reading' => 100 - $week, 'current_reading' => 101 - $week,
            ]);
        }
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.MeterReading::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        $version = app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($actor)->getJson(route('meter-readings.index', ['week' => $latest->toDateString()]), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'MeterReadings/Index', 'X-Inertia-Partial-Data' => 'rows',
        ])->assertOk()->assertJsonPath('props.rows.data.0.previousReading', 100)
            ->assertJsonPath('props.rows.data.0.reading.currentReading', 101);

        $this->assertSame(2, $hydrated);
    }

    public function test_a_reading_sheet_filter_only_reload_does_not_fetch_readings_or_summaries(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($actor)->getJson(route('meter-readings.index'), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'MeterReadings/Index', 'X-Inertia-Partial-Data' => 'filters',
        ])->assertOk()->assertJsonMissingPath('props.rows')->assertJsonMissingPath('props.summary');

        $readingQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "meter_readings"'));
        DB::disableQueryLog();
        $this->assertCount(0, $readingQueries);
    }

    public function test_batched_successor_checks_keep_the_same_branch_scope_as_live_checks(): void
    {
        $this->freezeTime();
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $week = MeterReading::latestEndedWeekStart($branch->id);
        $reading = MeterReading::factory()->for($subscription)->create(['branch_id' => $branch->id, 'week_start' => $week]);
        MeterReading::factory()->for($subscription)->create(['branch_id' => $otherBranch->id, 'week_start' => $week->copy()->addWeek()]);

        $central = MeterReading::withLaterReadingsCount()->findOrFail($reading->id);
        $this->assertSame(1, $central->laterReadingsCount());
        $this->assertTrue($central->hasLaterReadingBefore($week->copy()->addWeeks(2)));

        request()->attributes->set('domain_branch', $branch);
        request()->attributes->set('branch_domain_authorized', true);
        $batched = MeterReading::withLaterReadingsCount()->findOrFail($reading->id);
        $live = MeterReading::findOrFail($reading->id);
        $this->assertSame(0, $batched->laterReadingsCount());
        $this->assertSame($live->laterReadingsCount(), $batched->laterReadingsCount());
        $this->assertFalse($batched->hasLaterReadingBefore($week->copy()->addWeeks(2)));
        $this->assertSame($live->hasLaterReadingBefore($week->copy()->addWeeks(2)), $batched->hasLaterReadingBefore($week->copy()->addWeeks(2)));
    }

    public function test_login_preloads_the_local_body_font_without_google_font_requests(): void
    {
        $this->get(route('login'))->assertSee('as="font"', false)
            ->assertSee('type="font/woff2"', false)
            ->assertDontSee('fonts.googleapis.com', false)
            ->assertDontSee('fonts.gstatic.com', false);
    }

    public function test_filtering_latest_payments_does_not_recompute_reading_summaries(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($actor)->getJson(route('dashboard'), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'latestPayments,filters',
        ])->assertJsonPath('props.latestPayments.sum', 0)
            ->assertJsonMissingPath('props.sections');

        $summaryQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "meter_readings"'));
        DB::disableQueryLog();
        $this->assertCount(0, $summaryQueries);
    }

    public function test_subscription_search_does_not_fetch_form_options(): void
    {
        $actor = User::factory()->superAdmin()->create();
        Subscription::factory()->create(['full_name' => 'عميل البحث']);
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($actor)->getJson(route('subscriptions.index', ['search' => 'لا يوجد']), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Subscriptions/Index',
            'X-Inertia-Partial-Data' => 'subscriptions,filters',
        ])->assertJsonPath('props.subscriptions.total', 0)
            ->assertJsonMissingPath('props.tariffs')
            ->assertJsonMissingPath('props.circuitBreakers');

        $optionQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "tariffs"') || str_contains($query['query'], 'from "circuit_breakers"'));
        DB::disableQueryLog();
        $this->assertCount(0, $optionQueries);
    }

    public function test_mobile_summary_supplies_personal_details_and_the_subscriber_sub_area(): void
    {
        $actor = User::factory()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'صاحب الحساب', 'subscription_name' => 'متجر الكهرباء', 'phone' => '0599000111', 'national_id' => '123456789']);
        $subArea = SubArea::factory()->create(['name' => 'الحي الشرقي']);
        $subscription->meterBox->update(['sub_area_id' => $subArea->id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor))
            ->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertJsonPath('subscription.subscriber_name', 'صاحب الحساب')
            ->assertJsonPath('subscription.subscription_name', 'متجر الكهرباء')
            ->assertJsonPath('subscription.national_id', '123456789')
            ->assertJsonPath('subscription.personal_phone', '0599000111')
            ->assertJsonPath('subscription.sub_area_name', 'الحي الشرقي');
    }
}
