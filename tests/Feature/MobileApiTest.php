<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingEntrySetting;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    /** Taking payments in the app, both ways: cash and bank transfers. */
    private const TAKES_PAYMENTS = [PermissionKey::RecordCollections, PermissionKey::RecordCashPayments, PermissionKey::RecordTransferPayments];

    private function assignReading(User $reader, Subscription $subscription): void
    {
        if ($subscription->meter_box_id === null) {
            $subscription->update(['meter_box_id' => MeterBox::factory()->create(['branch_id' => $reader->branch_id])->id]);
        }
        $week = MeterReading::latestEndedWeekStart($reader->branch_id);
        $cycle = ReadingCycle::firstOrCreate(['branch_id' => $reader->branch_id, 'week_start' => $week->toDateString()], [
            'week_end' => MeterReading::weekEndFor($week, $reader->branch_id)->toDateString(),
            'reading_date' => now()->timezone(config('app.business_timezone'))->toDateString(), 'created_by' => $reader->id,
        ]);
        ReadingAssignment::factory()->mobile($reader)->approved()->create([
            'reading_cycle_id' => $cycle->id, 'branch_id' => $reader->branch_id, 'meter_box_id' => $subscription->meter_box_id, 'assigned_by' => $reader->id,
        ]);
    }

    public function test_mobile_login_issues_a_hashed_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->branchAdmin()->create();

        $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'wrong'])
            ->assertUnprocessable();

        $token = $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->json('token');

        $this->assertDatabaseHas('mobile_access_tokens', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('mobile_access_tokens', ['token_hash' => $token]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.username', $user->username);
        $this->postJson(route('mobile.logout'))->assertOk();
        $this->getJson(route('mobile.me'))->assertUnauthorized();
    }

    public function test_the_website_reading_day_limit_keeps_the_mobile_apps_configured_entry_days(): void
    {
        $this->travelTo('2026-09-25 10:00:00');
        $actor = User::factory()->dataEntry()->create();
        ReadingEntrySetting::factory()->forBranch($actor->branch)->create(['reading_day' => 4, 'open_days' => [4, 5]]);
        $subscription = Subscription::factory()->for($actor->branch)->create(['initial_reading' => 100]);
        $this->assignReading($actor, $subscription);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor))
            ->postJson(route('mobile.readings.store'), [
                'subscription_id' => $subscription->id, 'week_start' => '2026-09-18',
                'current_reading' => 130, 'mobile_operation_id' => (string) Str::uuid(),
            ])->assertCreated()->assertJsonPath('current_reading', 130);

        $this->assertDatabaseHas('meter_readings', ['subscription_id' => $subscription->id, 'current_reading' => 130]);
    }

    public function test_super_admin_mobile_entry_cannot_fill_in_a_previous_week(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $actor = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create(['initial_reading' => 100]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor))
            ->postJson(route('mobile.readings.store'), [
                'subscription_id' => $subscription->id, 'week_start' => '2026-09-11',
                'current_reading' => 125, 'mobile_operation_id' => (string) Str::uuid(),
            ])->assertUnprocessable()->assertJsonValidationErrors(['week_start' => 'يمكن إدخال قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.']);

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_weekly_reading_view_requires_permission_and_does_not_grant_write_access(): void
    {
        $this->getJson(route('mobile.readings.index'))->assertUnauthorized();
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_view_readings', false);
        $this->getJson(route('mobile.readings.index'))->assertForbidden();

        $collector->permissions()->sync(Permission::idsFor([PermissionKey::ViewMeterReadings]));
        $this->getJson(route('mobile.me'))
            ->assertJsonPath('user.can_view_readings', true)
            ->assertJsonPath('user.can_record_readings', false)
            ->assertJsonPath('user.can_record_collections', false);
        $this->getJson(route('mobile.readings.index'))->assertOk();
        $this->postJson(route('mobile.readings.store'), [])->assertForbidden();
        $this->postJson(route('mobile.collections.store'), [])->assertForbidden();
        $this->getJson(route('mobile.subscriptions.index'))->assertForbidden();

        $collector->permissions()->detach();
        $this->getJson(route('mobile.readings.index'))->assertForbidden();
    }

    public function test_weekly_readings_show_selected_week_and_missing_readings_only_in_the_visible_branch(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $user = User::factory()->collector()->create();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewMeterReadings, PermissionKey::BrowseMeterReadingWeeks]));
        $week = MeterReading::latestEndedWeekStart(null);
        $olderWeek = $week->copy()->subWeek();
        $subscription = Subscription::factory()->create(['branch_id' => $user->branch_id, 'full_name' => 'Alpha', 'account_number' => 'WEEK-42']);
        $missing = Subscription::factory()->create(['branch_id' => $user->branch_id, 'full_name' => 'Beta', 'initial_reading' => 40]);
        $other = Subscription::factory()->create(['full_name' => 'Hidden', 'account_number' => 'HIDDEN-99']);
        MeterReading::factory()->approved()->create([
            'subscription_id' => $subscription->id, 'week_start' => $olderWeek,
            'previous_reading' => 100, 'current_reading' => 120, 'consumption' => 20,
        ]);
        MeterReading::factory()->create([
            'subscription_id' => $subscription->id, 'week_start' => $week,
            'previous_reading' => 120, 'current_reading' => 150, 'consumption' => 30, 'amount_due' => '25.00',
        ]);
        MeterReading::factory()->create(['subscription_id' => $other->id, 'week_start' => $week]);
        MeterReading::factory()->create(['subscription_id' => $missing->id, 'week_start' => $week->copy()->addWeek(), 'current_reading' => 99]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));
        $this->getJson(route('mobile.readings.index'))
            ->assertJsonPath('week_start', $week->toDateString())
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.previous_reading', 120)
            ->assertJsonPath('data.0.current_reading', 150)
            ->assertJsonPath('data.0.consumption', 30)
            ->assertJsonPath('data.0.amount_due', '25.00')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.1.current_reading', null)
            ->assertJsonPath('data.1.previous_reading', 40)
            ->assertJsonPath('data.1.status', null);
        $this->getJson(route('mobile.readings.index', ['week' => $olderWeek->toDateString(), 'search' => 'WEEK-42']))
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.current_reading', 120)
            ->assertJsonPath('data.0.status', 'approved');
        $this->getJson(route('mobile.readings.index', ['search' => 'HIDDEN-99']))->assertJsonPath('total', 0);
    }

    public function test_weekly_readings_filter_subscription_status_within_the_visible_branch(): void
    {
        $user = User::factory()->branchAdmin()->create();
        Subscription::factory()->create(['branch_id' => $user->branch_id]);
        $disconnected = Subscription::factory()->create(['branch_id' => $user->branch_id, 'status' => 'disconnected']);
        Subscription::factory()->create(['status' => 'disconnected']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));

        $this->getJson(route('mobile.readings.index'))->assertJsonPath('total', 1)->assertJsonPath('data.0.subscription_status', 'active');
        $this->getJson(route('mobile.readings.index', ['subscription_status' => 'disconnected']))
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $disconnected->id)->assertJsonPath('data.0.subscription_status', 'disconnected');
        $this->getJson(route('mobile.readings.index', ['subscription_status' => 'all']))->assertJsonPath('total', 2);
        $this->getJson(route('mobile.readings.index', ['subscription_status' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('subscription_status');
    }

    public function test_bank_transfer_receipt_returns_the_reference_and_saved_payment_details_on_retry(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $payload = [
            'mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true,
            'subscription_id' => $subscription->id, 'amount' => '25', 'currency' => 'ILS',
            'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Sender',
            'reference_number' => 'BANK-0042', 'notes' => 'Transfer details',
        ];
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $first = $this->postJson(route('mobile.collections.store'), $payload)->assertCreated();
        $this->postJson(route('mobile.collections.store'), $payload)->assertCreated()
            ->assertJsonPath('id', $first->json('id'))->assertJsonPath('reference_number', 'BANK-0042')
            ->assertJsonPath('bank_name', 'بنك فلسطين')->assertJsonPath('sender_name', 'Sender')->assertJsonPath('notes', 'Transfer details');
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_weekly_reading_list_is_paginated_and_rejects_invalid_filters(): void
    {
        $user = User::factory()->collector()->create();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewMeterReadings]));
        Subscription::factory()->recycle(CircuitBreaker::factory()->create())->count(26)->create(['branch_id' => $user->branch_id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));

        $this->getJson(route('mobile.readings.index'))->assertJsonCount(25, 'data')->assertJsonPath('last_page', 2);
        $this->getJson(route('mobile.readings.index', ['page' => 2]))->assertJsonCount(1, 'data')->assertJsonPath('total', 26);
        $this->getJson(route('mobile.readings.index', ['week' => 'invalid', 'page' => 0, 'search' => str_repeat('a', 101)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['week', 'page', 'search']);
    }

    public function test_inactive_user_and_expired_token_cannot_access_mobile(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password'])
            ->assertUnprocessable();

        $active = User::factory()->create();
        $token = MobileAccessToken::issue($active);
        MobileAccessToken::query()->where('user_id', $active->id)->update(['expires_at' => now()->subSecond()]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('mobile.me'))->assertUnauthorized();
    }

    public function test_mobile_roster_is_scoped_to_the_staff_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $user = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $own = Subscription::factory()->create(['branch_id' => $branch->id, 'full_name' => 'Own Subscription', 'initial_reading' => 1200]);
        $this->assignReading($user, $own);
        Subscription::factory()->create(['branch_id' => $otherBranch->id, 'full_name' => 'Other Subscription']);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user))
            ->getJson(route('mobile.subscriptions.index'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.previous_reading', 1200);
    }

    public function test_mobile_roster_carries_recent_weeks_to_compare_the_new_reading_against(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        $user = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $user->branch_id, 'initial_reading' => 0]);
        $this->assignReading($user, $subscription);
        $week = MeterReading::latestEndedWeekStart(null);
        foreach ([4 => [0, 100], 3 => [100, 130], 2 => [130, 170], 1 => [170, 195]] as $weeksAgo => [$previous, $current]) {
            MeterReading::factory()->approved()->create([
                'subscription_id' => $subscription->id, 'week_start' => $week->copy()->subWeeks($weeksAgo),
                'previous_reading' => $previous, 'current_reading' => $current, 'consumption' => $current - $previous,
            ]);
        }
        MeterReading::factory()->create([
            'subscription_id' => $subscription->id, 'week_start' => $week,
            'previous_reading' => 195, 'current_reading' => 230, 'consumption' => 35,
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user))
            ->getJson(route('mobile.subscriptions.index'))
            ->assertOk()
            ->assertJsonPath('data.0.previous_reading', 195)
            ->assertJsonPath('data.0.current_reading', 230)
            ->assertJsonPath('data.0.reading_status', 'pending')
            ->assertJsonCount(3, 'data.0.recent_readings')
            ->assertJsonPath('data.0.recent_readings.0.week_start', $week->copy()->subWeek()->toDateString())
            ->assertJsonPath('data.0.recent_readings.0.current_reading', 195)
            ->assertJsonPath('data.0.recent_readings.0.consumption', 25)
            ->assertJsonPath('data.0.recent_readings.2.consumption', 30);
    }

    public function test_collector_cannot_download_the_offline_reading_roster(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.subscriptions.index'))
            ->assertForbidden();
    }

    public function test_mobile_reading_is_pending_and_retries_do_not_duplicate_it(): void
    {
        $this->travelTo('2026-09-24 10:00:00');
        ReadingEntrySetting::factory()->forcedOpen()->create();
        $user = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $user->branch_id, 'initial_reading' => 1200]);
        $this->assignReading($user, $subscription);
        $operationId = Str::uuid()->toString();
        $payload = [
            'mobile_operation_id' => $operationId,
            'subscription_id' => $subscription->id,
            'week_start' => MeterReading::latestEndedWeekStart(null)->toDateString(),
            'current_reading' => 1250,
        ];

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));
        $created = $this->postJson(route('mobile.readings.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->json('id');
        $this->postJson(route('mobile.readings.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('id', $created);
        $this->assertDatabaseCount('meter_readings', 1);

        $otherSubscription = Subscription::factory()->create(['branch_id' => $user->branch_id, 'initial_reading' => 1200]);
        $this->postJson(route('mobile.readings.store'), [
            ...$payload,
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $otherSubscription->id,
            'current_reading' => 1100,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_reading');
    }

    public function test_mobile_entry_returns_403_outside_the_saved_hours(): void
    {
        config(['app.business_timezone' => 'Asia/Gaza']);
        $this->travelTo(now()->parse('2026-09-24 14:01:00', 'UTC'));
        ReadingEntrySetting::factory()->create(['opens_at' => '08:30:00', 'closes_at' => '17:00:00']);
        $user = User::factory()->dataEntry()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $user->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user));
        $this->getJson(route('mobile.subscriptions.index'))->assertOk()->assertJsonPath('can_record_readings_now', false);
        $this->postJson(route('mobile.readings.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $subscription->id,
            'week_start' => '2026-09-18',
            'current_reading' => 1250,
        ])->assertForbidden();

        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_staff_cannot_record_a_reading_for_another_branch(): void
    {
        ReadingEntrySetting::factory()->forcedOpen()->create();
        $user = User::factory()->dataEntry()->create();
        $otherSubscription = Subscription::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user))
            ->postJson(route('mobile.readings.store'), [
                'mobile_operation_id' => Str::uuid()->toString(),
                'subscription_id' => $otherSubscription->id,
                'week_start' => MeterReading::latestEndedWeekStart(null)->toDateString(),
                'current_reading' => 100,
            ])->assertUnprocessable()->assertJsonValidationErrors('subscription_id');
        $this->assertDatabaseCount('meter_readings', 0);
    }

    public function test_a_collector_who_may_take_only_bank_transfers_is_refused_cash_in_the_app(): void
    {
        $collector = $this->collectorWithPermission(PermissionKey::RecordCollections, PermissionKey::RecordTransferPayments);
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.me'))
            ->assertJsonPath('user.can_record_collections', true)
            ->assertJsonPath('user.payment_methods', ['bank_transfer']);

        $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $subscription->id,
            'amount' => '100.00',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            'collector_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment_method' => 'لا تملك صلاحية الدفع نقدًا؛ اطلبها من مدير الفرع.']);

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_mobile_payment_requires_collector_confirmation_and_records_directly_once(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $startingBalance = $subscription->balance();
        $payload = [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $subscription->id,
            'amount' => '100.00',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ];

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->postJson(route('mobile.collections.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('collector_confirmed');
        $this->postJson(route('mobile.collections.store'), [
            ...$payload,
            'collector_confirmed' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('collector_confirmed');
        $this->assertSame($startingBalance, $subscription->fresh()->balance());

        $payload['collector_confirmed'] = true;
        $created = $this->postJson(route('mobile.collections.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'recorded')
            ->assertJsonPath('amount', '100.00')
            ->assertJsonPath('balance_after', number_format($startingBalance - 100, 2, '.', ''))
            ->assertJsonStructure(['voucher_number'])
            ->json('id');
        $this->postJson(route('mobile.collections.store'), $payload)
            ->assertCreated()->assertJsonPath('id', $created);

        $this->assertDatabaseHas('subscription_transactions', [
            'id' => $created,
            'mobile_operation_id' => $payload['mobile_operation_id'],
            'type' => SubscriptionTransaction::TYPE_PAYMENT,
            'amount' => '-100.00',
        ]);
        $this->assertSame(1, SubscriptionTransaction::query()->where('mobile_operation_id', $payload['mobile_operation_id'])->count());
        $this->assertSame($startingBalance - 100, $subscription->fresh()->balance());
        $this->getJson(route('mobile.collections.index'))
            ->assertOk()->assertJsonPath('data.0.id', $created)
            ->assertJsonPath('data.0.status', 'recorded');
    }

    public function test_the_collectors_payments_today_follow_the_business_day_not_the_utc_date(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $paymentAt = function (string $utc) use ($collector, $subscription): int {
            $this->travelTo($utc);

            return SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])->id;
        };
        $paymentAt('2026-10-05 09:00:00'); // 12:00 on the 5th in Gaza
        $paymentAt('2026-10-05 20:30:00'); // 23:30 on the 5th in Gaza
        $afterMidnight = $paymentAt('2026-10-05 22:30:00'); // 01:30 on the 6th in Gaza, still the 5th in UTC

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $today = function (string $utc) {
            $this->travelTo($utc);

            return $this->getJson(route('mobile.collections.index'))->assertOk();
        };

        // 23:45 on the 5th in Gaza: the day holds the two taken before midnight.
        $today('2026-10-05 20:45:00')->assertJsonCount(2, 'data')->assertJsonPath('total', 20);
        // 01:45 on the 6th in Gaza (the 5th in UTC): the new day holds only what was taken since midnight.
        $today('2026-10-05 22:45:00')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $afterMidnight)->assertJsonPath('total', 10);
        // Midday on the 7th: nothing yet.
        $today('2026-10-07 09:00:00')->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
    }

    #[TestWith(['suspended', 'Suspended'])]
    #[TestWith(['disconnected', 'Disconnected'])]
    public function test_a_suspended_or_disconnected_subscription_can_pay_in_the_app_as_on_the_website(string $status, string $label): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'status' => $status]);
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '80.00']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.collections.subscriptions'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.status', $status)
            ->assertJsonPath('data.0.status_label', __($label));
        $response = $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'collector_confirmed' => true,
            'subscription_id' => $subscription->id,
            'amount' => '30',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()->assertJsonPath('balance_after', '50.00');
        $this->assertSame(50.0, $subscription->fresh()->balance());
    }

    public function test_collection_search_ignores_arabic_spelling_and_word_order(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $ahmad = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'أحمد علي عبد الله']);
        $fatima = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'فاطمة مصطفى']);
        Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'خالد يوسف']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'عبد  اَحمد']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ahmad->id);
        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'فاطمه مصطفي']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fatima->id);
        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'احمد خالد']))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_collection_search_reads_arabic_digits_and_lists_the_exact_account_first(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $longer = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'account_number' => '91042']);
        SubscriptionTransaction::factory()->for($longer)->create(['amount' => '500.00']);
        $exact = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'account_number' => '1042']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.collections.subscriptions', ['search' => '١٠٤٢']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $exact->id)
            ->assertJsonPath('data.1.id', $longer->id);
    }

    public function test_collection_search_treats_like_wildcards_as_plain_text(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'Plain Name']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.collections.subscriptions', ['search' => '%']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'Pl_in']))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_weekly_readings_search_matches_arabic_spelling_variants(): void
    {
        $user = $this->collectorWithPermission(PermissionKey::ViewMeterReadings);
        $subscription = Subscription::factory()->create(['branch_id' => $user->branch_id, 'full_name' => 'إسراء']);
        Subscription::factory()->create(['branch_id' => $user->branch_id, 'full_name' => 'سلمى']);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($user))
            ->getJson(route('mobile.readings.index', ['search' => 'اسراء']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $subscription->id);
    }

    public function test_subscription_page_shows_balance_last_payment_and_latest_lines_newest_first(): void
    {
        $collector = $this->collectorWithPermission(...[...self::TAKES_PAYMENTS, PermissionKey::ViewSubscriptions]);
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id, 'full_name' => 'أحمد', 'phone' => '0599000111']);
        $charge = SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '120.00', 'created_at' => now()->subDays(2)]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $collector, ['amount' => '30', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->id)
            ->assertJsonPath('subscription.full_name', 'أحمد')
            ->assertJsonPath('subscription.phone', '0599000111')
            ->assertJsonPath('subscription.balance', '90.00')
            ->assertJsonPath('last_payment.id', $payment->id)
            ->assertJsonPath('last_payment.amount_in_shekels', '30.00')
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('transactions.0.id', $payment->id)
            ->assertJsonPath('transactions.0.amount', '30.00')
            ->assertJsonPath('transactions.0.is_credit', true)
            ->assertJsonPath('transactions.0.balance_after', '90.00')
            ->assertJsonPath('transactions.1.id', $charge->id)
            ->assertJsonPath('transactions.1.is_credit', false)
            ->assertJsonPath('transactions.1.balance_after', '120.00');
    }

    public function test_subscription_page_needs_the_collection_permission_and_the_same_branch(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $otherBranch = Subscription::factory()->create();
        $reader = $this->collectorWithPermission(PermissionKey::ViewMeterReadings);
        $ownBranch = Subscription::factory()->create(['branch_id' => $reader->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.subscriptions.show', $otherBranch))
            ->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($reader))
            ->getJson(route('mobile.collections.subscriptions.show', $ownBranch))
            ->assertForbidden();
    }

    public function test_subscription_summary_includes_personal_details_terms_and_the_latest_reading_by_week(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $subscription = Subscription::factory()->create([
            'branch_id' => $collector->branch_id,
            'full_name' => 'أحمد علي',
            'subscription_name' => 'منزل أحمد',
            'phone' => '0599000111',
            'subscription_phone' => '0599000222',
            'address' => 'شارع النور',
            'accounting_type' => 'monthly',
            'subscription_date' => '2026-09-01',
            'kilowatt_price' => '2.50',
            'minimum_charge' => '25.00',
            'circuit_breaker_id' => CircuitBreaker::factory()->create(['ampere' => 20])->id,
        ]);
        $latest = MeterReading::factory()->for($subscription)->create([
            'week_start' => '2026-10-09',
            'week_end' => '2026-10-15',
            'previous_reading' => 1200,
            'current_reading' => 1250.5,
            'consumption' => 50.5,
        ]);
        MeterReading::factory()->for($subscription)->approved()->create(['week_start' => '2026-10-02']);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertOk()
            ->assertJsonPath('subscription.full_name', 'منزل أحمد')
            ->assertJsonPath('subscription.subscriber_name', 'أحمد علي')
            ->assertJsonPath('subscription.personal_phone', '0599000111')
            ->assertJsonPath('subscription.phone', '0599000222')
            ->assertJsonPath('subscription.address', 'شارع النور')
            ->assertJsonPath('subscription.accounting_type', 'monthly')
            ->assertJsonPath('subscription.subscription_date', '2026-09-01')
            ->assertJsonPath('subscription.kilowatt_price', '2.50')
            ->assertJsonPath('subscription.minimum_payment', '25.00')
            ->assertJsonPath('subscription.circuit_breaker_ampere', 20)
            ->assertJsonPath('last_reading.id', $latest->id)
            ->assertJsonPath('last_reading.week_start', '2026-10-09')
            ->assertJsonPath('last_reading.week_end', '2026-10-15')
            ->assertJsonPath('last_reading.previous_reading', 1200)
            ->assertJsonPath('last_reading.current_reading', 1250.5)
            ->assertJsonPath('last_reading.consumption', 50.5)
            ->assertJsonPath('last_reading.status', 'pending');
    }

    public function test_subscription_summary_without_readings_includes_initial_reading_and_default_terms(): void
    {
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $subscription = Subscription::factory()->create([
            'branch_id' => $collector->branch_id,
            'initial_reading' => 500,
            'kilowatt_price' => null,
            'minimum_charge' => null,
            'circuit_breaker_id' => CircuitBreaker::factory()->create(['minimum_payment' => '15.00'])->id,
        ]);
        $subscription->tariff->update(['rate' => '1.75']);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.subscriptions.show', $subscription))
            ->assertOk()
            ->assertJsonPath('last_reading', null)
            ->assertJsonPath('subscription.initial_reading', 500)
            ->assertJsonPath('subscription.kilowatt_price', '1.75')
            ->assertJsonPath('subscription.minimum_payment', '15.00');
    }

    public function test_mobile_bank_transfers_keep_both_source_and_destination_banks(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.store'), [
                'mobile_operation_id' => Str::uuid()->toString(),
                'subscription_id' => $subscription->id,
                'amount' => '100',
                'currency' => 'ILS',
                'payment_method' => 'bank_transfer',
                'bank_name' => 'محفظة بالباي',
                'sender_bank_name' => 'البنك الإسلامي العربي',
                'sender_name' => 'Ahmad',
                'reference_number' => 'TR-MOBILE-1',
                'collector_confirmed' => true,
            ])->assertCreated();

        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $subscription->id,
            'bank_name' => 'محفظة بالباي',
            'sender_bank_name' => 'البنك الإسلامي العربي',
            'amount' => '-100.00',
        ]);
    }

    public function test_mobile_bank_transfer_rejects_a_reference_already_used_on_the_web(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        SubscriptionTransaction::recordPayment($subscription, $collector, [
            'amount' => '10',
            'currency' => 'ILS',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => 'WEB-100',
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.store'), [
                'mobile_operation_id' => Str::uuid()->toString(),
                'subscription_id' => $subscription->id,
                'amount' => '10',
                'currency' => 'ILS',
                'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك فلسطين',
                'sender_name' => 'Ahmad',
                'reference_number' => ' web  -100 ',
                'collector_confirmed' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_number');

        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_the_app_is_told_whether_a_transfer_reference_is_available_or_repeated(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));

        $this->getJson(route('mobile.collections.reference-status', ['reference_number' => 'WEB-100']))
            ->assertOk()
            ->assertJson(['available' => true, 'message' => 'الرقم المرجعي متاح.']);

        SubscriptionTransaction::recordPayment(Subscription::factory()->create(['branch_id' => $collector->branch_id]), $collector, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'WEB-100',
        ]);

        $this->getJson(route('mobile.collections.reference-status', ['reference_number' => 'web 100']))
            ->assertJson(['available' => false, 'message' => 'الرقم المرجعي مكرر: مسجَّل مسبقًا على دفعة أخرى.']);
    }

    public function test_mobile_payments_reject_unsupported_currencies_and_offer_the_website_banks(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $startingBalance = $subscription->balance();
        $payment = [
            'subscription_id' => $subscription->id,
            'amount' => '20',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            'collector_confirmed' => true,
        ];

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->getJson(route('mobile.me'))
            ->assertJsonPath('user.transfer_banks', config('powercollect.transfer_banks'));
        $this->postJson(route('mobile.collections.store'), [...$payment, 'currency' => 'GBP', 'exchange_rate' => '3.7', 'mobile_operation_id' => Str::uuid()->toString()])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson(route('mobile.collections.store'), [...$payment, 'mobile_operation_id' => Str::uuid()->toString()])
            ->assertCreated()
            ->assertJsonPath('amount', '20.00')
            ->assertJsonPath('currency', 'ILS')
            ->assertJsonPath('amount_in_shekels', '20.00');
        $this->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => Str::uuid()->toString(),
            'subscription_id' => $subscription->id,
            'amount' => '30',
            'currency' => 'ILS',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'البنك الإسلامي العربي',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-30',
            'collector_confirmed' => true,
        ])->assertCreated()->assertJsonPath('bank_name', 'البنك الإسلامي العربي');

        $this->assertSame($startingBalance - 50, $subscription->fresh()->balance());
        $this->getJson(route('mobile.collections.index'))
            ->assertJsonPath('total', 50)
            ->assertJsonPath('cash_total', 20);
    }

    public function test_receipts_are_no_longer_read_or_stored_on_the_server(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson('/api/mobile/payment-receipts/analyze')->assertNotFound();

        foreach (['payment_receipts', 'receipt_example_runs', 'receipt_examples', 'payment_providers'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped");
        }
    }

    public function test_mobile_payment_rejects_other_branch_and_other_collectors_retry(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $collector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $otherSubscription = Subscription::factory()->create(['branch_id' => $otherBranch->id]);
        $payload = [
            'mobile_operation_id' => Str::uuid()->toString(),
            'collector_confirmed' => true,
            'subscription_id' => $otherSubscription->id,
            'amount' => '25.00',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ];

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->postJson(route('mobile.collections.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('subscription_id');

        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);
        $payload['subscription_id'] = $subscription->id;
        $this->postJson(route('mobile.collections.store'), $payload)->assertCreated();

        $otherCollector = User::factory()->collector()->create(['branch_id' => $branch->id]);
        $otherCollector->permissions()->sync(Permission::idsFor(self::TAKES_PAYMENTS));
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($otherCollector))
            ->postJson(route('mobile.collections.store'), $payload)->assertForbidden();

        $this->actingAs(User::factory()->branchAdmin()->create(['branch_id' => $branch->id]))
            ->get('/pending-collections')->assertNotFound();
    }

    public function test_full_subscriber_history_paginates_with_running_balances(): void
    {
        $this->freezeTime();
        $collector = $this->collectorWithPermission(...[...self::TAKES_PAYMENTS, PermissionKey::ViewSubscriptions]);
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $charges = SubscriptionTransaction::factory()->for($subscription)->count(26)
            ->sequence(fn ($sequence): array => ['source_key' => 'history-charge:'.$sequence->index])
            ->create(['amount' => '10.00', 'created_at' => now()]);
        $payment = SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => 'payment', 'amount' => '-30.00', 'source_key' => 'history-payment',
            'status' => SubscriptionTransaction::STATUS_CANCELLED, 'payment_method' => 'cash',
            'cancelled_at' => now(), 'created_at' => now(),
        ]);
        $reversal = SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => 'cancellation', 'amount' => '30.00', 'source_key' => 'history-cancellation',
            'reference_transaction_id' => $payment->id, 'created_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.subscriptions.transactions', $subscription))
            ->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 28)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.id', $reversal->id)->assertJsonPath('data.0.balance_after', '260.00')
            ->assertJsonPath('data.1.id', $payment->id)->assertJsonPath('data.1.is_cancelled', true)
            ->assertJsonPath('data.1.is_credit', true)->assertJsonPath('data.1.balance_after', '230.00')
            ->assertJsonPath('data.24.balance_after', '40.00');
        $this->getJson(route('mobile.collections.subscriptions.transactions', [$subscription, 'page' => 2]))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('current_page', 2)
            ->assertJsonPath('data.0.id', $charges[2]->id)->assertJsonPath('data.0.balance_after', '30.00')
            ->assertJsonPath('data.2.id', $charges[0]->id)->assertJsonPath('data.2.balance_after', '10.00');
    }

    public function test_mobile_details_and_history_use_website_permissions_without_leaking_statement_lines(): void
    {
        $collector = $this->collectorWithPermission(PermissionKey::RecordCollections);
        $own = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $other = Subscription::factory()->create();
        SubscriptionTransaction::factory()->for($own)->create(['amount' => '40.00']);
        $details = route('mobile.collections.subscriptions.show', $own);
        $history = route('mobile.collections.subscriptions.transactions', $own);

        $this->getJson($history)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector));
        $this->getJson($details)->assertForbidden();
        $this->getJson(route('mobile.collections.subscriptions'))->assertForbidden();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments]));
        $this->getJson($details)->assertOk()->assertJsonPath('subscription.balance', '40.00')
            ->assertJsonPath('permissions.view_history', false)->assertJsonPath('permissions.payment_methods', ['cash'])
            ->assertJsonPath('last_payment', null)->assertJsonCount(0, 'transactions');
        $this->getJson($history)->assertForbidden();
        $this->getJson(route('mobile.collections.subscriptions.transactions', $other))->assertNotFound();
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordCashPayments, PermissionKey::ViewSubscriptions]));
        $this->getJson($details)->assertJsonPath('permissions.view_history', true);
        $this->getJson($history)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($history.'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_collector_today_totals_exclude_cancelled_payments_as_on_the_website(): void
    {
        $this->freezeTime();
        $collector = $this->collectorWithPermission(...self::TAKES_PAYMENTS);
        $subscription = Subscription::factory()->create(['branch_id' => $collector->branch_id]);
        $active = SubscriptionTransaction::factory()->for($subscription)->create([
            'recorded_by' => $collector->id, 'type' => 'payment', 'payment_method' => 'cash',
            'amount' => '-20.00', 'currency_amount' => '20.00', 'source_key' => 'active-mobile-payment',
        ]);
        SubscriptionTransaction::factory()->for($subscription)->create([
            'recorded_by' => $collector->id, 'type' => 'payment', 'payment_method' => 'cash',
            'amount' => '-10.00', 'currency_amount' => '10.00', 'source_key' => 'cancelled-mobile-payment', 'cancelled_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($collector))
            ->getJson(route('mobile.collections.index'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('total', 20)->assertJsonPath('cash_total', 20);
    }

    public function test_view_only_staff_can_open_their_branch_statements_without_payment_permissions(): void
    {
        $viewer = $this->collectorWithPermission(PermissionKey::ViewSubscriptions);
        $own = Subscription::factory()->create(['branch_id' => $viewer->branch_id]);
        $other = Subscription::factory()->create();
        $charge = SubscriptionTransaction::factory()->for($own)->create(['amount' => '40.00']);
        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($viewer));

        $this->getJson(route('mobile.me'))->assertOk()
            ->assertJsonPath('user.can_view_subscriptions', true)
            ->assertJsonPath('user.can_record_collections', false);
        $this->getJson(route('mobile.collections.subscriptions'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson(route('mobile.collections.subscriptions.show', $own))->assertOk()
            ->assertJsonPath('subscription.balance', '40.00')
            ->assertJsonPath('permissions.view_history', true)
            ->assertJsonPath('permissions.record_payment', false)
            ->assertJsonPath('permissions.record_split_payment', false);
        $this->getJson(route('mobile.collections.subscriptions.transactions', $own))->assertOk()
            ->assertJsonPath('data.0.id', $charge->id)->assertJsonPath('data.0.balance_after', '40.00');
        $this->getJson(route('mobile.collections.subscriptions.show', $other))->assertNotFound();
        $this->getJson(route('mobile.collections.subscriptions.transactions', $other))->assertNotFound();
        $this->postJson(route('mobile.collections.store'), [
            'subscription_id' => $own->id,
            'mobile_operation_id' => Str::uuid()->toString(),
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash', 'collector_confirmed' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('subscription_transactions', 1);

        $viewer->permissions()->detach();
        $this->getJson(route('mobile.me'))->assertJsonPath('user.can_view_subscriptions', false);
        $this->getJson(route('mobile.collections.subscriptions'))->assertForbidden();
        $this->getJson(route('mobile.collections.subscriptions.show', $own))->assertForbidden();
        $this->getJson(route('mobile.collections.subscriptions.transactions', $own))->assertForbidden();
    }

    public function test_mobile_statement_has_the_current_balance_and_local_transaction_details(): void
    {
        config(['app.business_timezone' => 'Asia/Hebron']);
        $viewer = $this->collectorWithPermission(PermissionKey::ViewSubscriptions);
        $subscription = Subscription::factory()->create(['branch_id' => $viewer->branch_id]);
        SubscriptionTransaction::factory()->for($subscription)->create([
            'amount' => '50.00', 'created_at' => '2026-10-19 10:00:00',
        ]);
        $payment = SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => 'payment', 'payment_method' => 'bank_transfer', 'amount' => '-20.00',
            'source_key' => 'statement-payment-details',
            'created_at' => '2026-10-20 22:30:00', 'notes' => 'تسديد جزء من المستحق',
            'reference_number' => 'REF-456', 'manual_voucher_number' => 'V-123',
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($viewer))
            ->getJson(route('mobile.collections.subscriptions.transactions', $subscription))->assertOk()
            ->assertJsonPath('balance', '30.00')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.amount', '20.00')
            ->assertJsonPath('data.0.date', '2026-10-21')
            ->assertJsonPath('data.0.recorded_at', '2026-10-21 01:30')
            ->assertJsonPath('data.0.type_label', 'دفعة')
            ->assertJsonPath('data.0.notes', 'تسديد جزء من المستحق')
            ->assertJsonPath('data.0.reference_number', 'REF-456')
            ->assertJsonPath('data.0.voucher_number', 'V-123');
    }

    public function test_mobile_subscriber_summary_includes_the_address_area_and_meter_box_location(): void
    {
        $area = Area::factory()->create(['name' => 'مخيم 2']);
        $branch = Branch::factory()->inArea($area)->create();
        $viewer = $this->collectorWithPermission(PermissionKey::ViewSubscriptions);
        $viewer->update(['branch_id' => $branch->id]);
        $subArea = SubArea::factory()->create(['name' => 'الحي الشرقي']);
        $box = MeterBox::factory()->create([
            'branch_id' => $branch->id, 'sub_area_id' => $subArea->id,
            'name' => 'أبو ذر', 'name_suffix' => '8', 'box_number' => 'B-42',
        ]);
        $subscription = Subscription::factory()->create([
            'branch_id' => $branch->id, 'meter_box_id' => $box->id, 'address' => 'بجانب المدرسة',
        ]);

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($viewer))
            ->getJson(route('mobile.collections.subscriptions.show', $subscription))->assertOk()
            ->assertJsonPath('subscription.address', 'بجانب المدرسة')
            ->assertJsonPath('subscription.area_name', 'مخيم 2')
            ->assertJsonPath('subscription.sub_area_name', 'الحي الشرقي')
            ->assertJsonPath('subscription.meter_box_name', 'أبو ذر 8')
            ->assertJsonPath('subscription.meter_box_number', 'B-42');
    }

    private function collectorWithPermission(PermissionKey ...$permissions): User
    {
        $user = User::factory()->collector()->create();
        $user->permissions()->sync(Permission::idsFor($permissions));

        return $user;
    }
}
