<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\PermissionKey;
use App\Models\CashBox;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A collector's day in the field app, against a company set up through the
 * website: signing in on their phone, finding subscribers, taking cash,
 * transfers, dollars and a transfer split between two accounts, recording a
 * clearing, retrying what a poor connection may have sent twice, seeing
 * their day and their boxes, and confirming the box the branch received.
 * And what the app refuses: other people's accounts, missing permissions,
 * a second phone, a stopped account and an audit-only login.
 */
class MobileCollectorDayJourneyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyThroughTheWebsite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFreshDeployment();
    }

    public function test_a_collector_works_a_whole_day_in_the_app_and_the_branch_receives_what_they_collected(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        $company = $this->setUpCompany();
        ['manager' => $manager, 'collector' => $collector] = $company;
        [$salem, $khaled] = $this->registerTwoSubscribers($company);

        $token = $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => self::PASSWORD, 'remember' => true])->assertOk()
            ->assertJsonPath('user.branch_name', 'فرع النصيرات')
            ->assertJsonPath('user.can_record_collections', true)
            ->assertJsonPath('user.can_record_split_payments', true)
            ->assertJsonPath('user.can_adjust_balances', true)
            ->assertJsonPath('user.can_record_readings', false)
            ->assertJsonPath('user.payment_methods', ['cash', 'bank_transfer'])
            ->json('token');
        $this->assertContains('بنك فلسطين', $this->withToken($token)->getJson(route('mobile.me'))->assertOk()->json('user.transfer_banks'));

        $this->withToken($token)->getJson(route('mobile.collections.subscriptions', ['search' => 'سالم']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.account_number', '202600001')->assertJsonPath('data.0.balance', fn ($balance): bool => (float) $balance === 300.0);
        $this->withToken($token)->getJson(route('mobile.collections.subscriptions', ['search' => '202600002']))->assertOk()
            ->assertJsonPath('data.0.id', $khaled->id);
        $this->withToken($token)->getJson(route('mobile.collections.subscriptions.show', $salem))->assertOk();

        $cashOperation = (string) Str::uuid();
        $this->collect($token, $salem, ['amount' => '60', 'payment_method' => 'cash'], $cashOperation)->assertCreated()->assertJsonPath('balance_after', '240.00');
        $this->collect($token, $salem, ['amount' => '60', 'payment_method' => 'cash'], $cashOperation)->assertCreated()->assertJsonPath('balance_after', '240.00');
        $operation = (string) Str::uuid();
        $this->collect($token, $khaled, ['amount' => '50', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash'], $operation)->assertCreated()->assertJsonPath('balance_after', '215.00');
        $this->collect($token, $khaled, ['amount' => '50', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash'], $operation)->assertCreated()->assertJsonPath('balance_after', '215.00');
        $this->collect($token, $salem, ['amount' => '40', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'سالم عبد الله', 'reference_number' => 'APP-TRF-1'])->assertCreated();
        $this->collect($token, $khaled, ['amount' => '10', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'خالد', 'reference_number' => 'APP-TRF-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->collect($token, $salem, ['amount' => '10', 'payment_method' => 'cash'], collectorConfirmed: false)->assertUnprocessable()->assertJsonValidationErrors('collector_confirmed');

        $split = ['mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true, 'total_amount' => '150', 'bank_name' => 'جوال باي', 'sender_name' => 'أبو خالد',
            'reference_number' => 'APP-SPLIT-1', 'parts' => [['subscription_id' => $salem->id, 'amount' => '50'], ['subscription_id' => $khaled->id, 'amount' => '100']]];
        $this->withToken($token)->postJson(route('mobile.split-payments.store'), [...$split, 'total_amount' => '140'])->assertUnprocessable();
        $this->withToken($token)->postJson(route('mobile.split-payments.store'), $split)->assertCreated()->assertJsonCount(2, 'parts');
        $this->withToken($token)->postJson(route('mobile.split-payments.store'), $split)->assertCreated();
        $this->withToken($token)->postJson(route('mobile.collections.subscriptions.adjustments', $khaled), [
            'mobile_operation_id' => (string) Str::uuid(), 'action' => 'clearing', 'amount' => '15', 'notes' => 'صيانة خط على حساب المشترك',
        ])->assertCreated()->assertJsonPath('balance_after', '100.00');
        $this->withToken($token)->postJson(route('mobile.collections.subscriptions.adjustments', $khaled), [
            'mobile_operation_id' => (string) Str::uuid(), 'action' => 'charge', 'type' => 'penalty', 'amount' => '15', 'notes' => 'غرامة',
        ])->assertForbidden();

        $this->assertSame([150.0, 100.0], [$salem->balance(), $khaled->balance()]);
        $this->assertSame(5, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->where('recorded_by', $collector->id)->count());
        $this->withToken($token)->getJson(route('mobile.collections.index'))->assertOk()
            ->assertJsonPath('total', 435)
            ->assertJsonPath('cash_total', 245)
            ->assertJsonPath('bank_transfer_total', 190)
            ->assertJsonCount(5, 'data');

        $boxes = $this->withToken($token)->getJson(route('mobile.cash-boxes.index'))->assertOk()->assertJsonPath('started', true)->json('boxes');
        $this->assertEqualsCanonicalizing(['cash', 'bank_transfer', 'clearing'], array_column($boxes, 'type'));
        $cashBox = CashBox::query()->where('user_id', $collector->id)->where('type', 'cash')->sole();
        $this->withToken($token)->postJson(route('mobile.cash-boxes.actions', ['action' => 'confirm', 'record' => $cashBox->id]), ['branch_id' => $company['branch']->id])->assertUnprocessable();
        $this->withToken($token)->postJson(route('mobile.cash-boxes.actions', ['action' => 'receive', 'record' => $cashBox->id]), ['branch_id' => $company['branch']->id])->assertForbidden();
        $this->actingAs($manager)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $cashBox->id]), ['branch_id' => $company['branch']->id])->assertSessionHasNoErrors();
        // Receiving the box confirms it for the collector: nothing is left for them to confirm.
        $this->assertNotNull($cashBox->fresh()->confirmed_at);
        $this->withToken($token)->postJson(route('mobile.cash-boxes.actions', ['action' => 'confirm', 'record' => $cashBox->id]), ['branch_id' => $company['branch']->id])->assertUnprocessable();
        $this->withToken($token)->getJson(route('mobile.cash-boxes.index'))->assertOk()
            ->assertJsonPath('boxes', fn (array $boxes): bool => collect($boxes)->firstWhere('id', $cashBox->id)['stage'] === 'completed');

        $this->withToken($token)->postJson(route('mobile.logout'))->assertOk();
        $this->withToken($token)->getJson(route('mobile.me'))->assertUnauthorized();
    }

    public function test_the_app_refuses_other_branches_missing_permissions_a_second_phone_a_stopped_account_and_audit_only_logins(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        $company = $this->setUpCompany();
        ['collector' => $collector, 'reader' => $reader] = $company;
        [$salem] = $this->registerTwoSubscribers($company);
        $foreign = Subscription::factory()->create();

        $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => 'wrong'])->assertUnprocessable();
        $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'auditor-1', 'password' => self::PASSWORD])->assertForbidden();
        $token = $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => self::PASSWORD])->assertOk()->json('token');

        $this->withToken($token)->getJson(route('mobile.collections.subscriptions.show', $foreign))->assertNotFound();
        $this->collect($token, $foreign, ['amount' => '10', 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('subscription_id');
        $this->withToken($token)->postJson(route('mobile.collections.subscriptions.adjustments', $foreign), [
            'mobile_operation_id' => (string) Str::uuid(), 'action' => 'clearing', 'amount' => '5', 'notes' => 'خارج الفرع',
        ])->assertNotFound();
        $this->withToken($token)->getJson(route('mobile.reading-assignments.index'))->assertForbidden();

        $readerToken = $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'reader-1', 'password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('user.can_record_collections', false)->json('token');
        $this->collect($readerToken, $salem, ['amount' => '10', 'payment_method' => 'cash'])->assertForbidden();
        $this->withToken($readerToken)->getJson(route('mobile.cash-boxes.index'))->assertForbidden();

        // The branch takes cash away from the collector: the app stops offering it and the server refuses it.
        $this->actingAs($this->superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [$collector->id => Permission::idsFor([PermissionKey::RecordCollections, PermissionKey::RecordTransferPayments])],
        ])->assertSessionHasNoErrors();
        $this->withToken($token)->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.payment_methods', ['bank_transfer']);
        $this->collect($token, $salem, ['amount' => '10', 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        // One phone at a time: signing in on a second phone ends the first.
        $secondPhone = $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => self::PASSWORD])->assertOk()->json('token');
        $this->withToken($token)->getJson(route('mobile.me'))->assertUnauthorized();
        $this->withToken($secondPhone)->getJson(route('mobile.me'))->assertOk();

        // A stopped account is signed out of the app at once.
        $this->actingAs($this->superAdmin)->put(route('users.update', $collector), [
            'name' => $collector->name, 'username' => $collector->username, 'branch_id' => $collector->branch_id, 'is_active' => false,
        ])->assertSessionHasNoErrors();
        $this->withToken($secondPhone)->getJson(route('mobile.me'))->assertUnauthorized();
        $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => self::PASSWORD])->assertUnprocessable();

        $this->assertSame(300.0, $salem->balance());
        $this->assertSame(0, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->count());
        $this->assertSame(0, $reader->fresh()->permissions()->where('key', PermissionKey::RecordCollections->value)->count());
    }

    public function test_foreign_cash_is_credited_at_the_companys_rate_whatever_rate_the_collector_types_and_waits_for_a_rate_to_be_set(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        $company = $this->setUpCompany();
        [$salem] = $this->registerTwoSubscribers($company);
        $token = $this->withoutToken()->postJson(route('mobile.login'), ['username' => 'collector-1', 'password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('user.currencies', fn (array $currencies): bool => collect($currencies)->pluck('rate', 'code')->only(['USD', 'JOD'])->all() === ['JOD' => null, 'USD' => '3.7000'])
            ->json('token');

        $this->collect($token, $salem, ['amount' => '10', 'currency' => 'USD', 'exchange_rate' => '3.5', 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('balance_after', '263.00');
        $this->collect($token, $salem, ['amount' => '10', 'currency' => 'JOD', 'exchange_rate' => '5.2', 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('exchange_rate');

        $this->assertSame('3.7000', SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->sole()->exchange_rate);
        $this->assertSame(263.0, $salem->balance());
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array{Subscription, Subscription}
     */
    private function registerTwoSubscribers(array $company): array
    {
        $this->actingAs($company['manager'])->post(route('subscriptions.store'), $this->subscriberForm($company, ['subscription_fee' => '300']))->assertSessionHasNoErrors();
        $this->actingAs($company['manager'])->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'خالد يوسف', 'national_id' => '402345678', 'meter_box_id' => $company['market']->id, 'subscription_fee' => '400',
        ]))->assertSessionHasNoErrors();
        auth()->logout();

        return Subscription::query()->orderBy('id')->get()->all();
    }

    /**
     * Send a payment the way the app does, with an operation id it keeps until the server answers.
     *
     * @param  array<string, mixed>  $details
     */
    private function collect(string $token, Subscription $subscription, array $details, ?string $operationId = null, bool $collectorConfirmed = true): TestResponse
    {
        return $this->withToken($token)->postJson(route('mobile.collections.store'), [
            'mobile_operation_id' => $operationId ?? (string) Str::uuid(), 'collector_confirmed' => $collectorConfirmed,
            'subscription_id' => $subscription->id, 'currency' => 'ILS', ...$details,
        ]);
    }
}
