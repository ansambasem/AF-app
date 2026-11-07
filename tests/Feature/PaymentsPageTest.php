<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PaymentsPageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->collector = $this->userAllowedTo(PermissionKey::RecordCollections);
    }

    public function test_the_page_needs_a_signed_in_user_who_may_record_payments(): void
    {
        $this->get(route('payments.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]))
            ->get(route('payments.index'))
            ->assertForbidden();

        $this->actingAs($this->collector)->get(route('payments.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Payments/Index')->where('can.recordPayments', true));
    }

    public function test_the_table_lists_the_subscribers_of_the_users_branch_and_no_others(): void
    {
        Subscription::factory()->count(2)->create(['branch_id' => $this->branch->id]);
        Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($this->collector)->get(route('payments.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('subscriptions.data', 2)
                ->where('subscriptions.total', 2)
                ->where('scopeLabel', $this->branch->name));
    }

    public function test_a_subscriber_s_statement_opens_over_the_list_only_for_their_own_branch(): void
    {
        $own = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $foreign = Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $reader = $this->userAllowedTo(PermissionKey::RecordCollections);
        $reader->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['key' => PermissionKey::ViewSubscriptions->value], ['label' => PermissionKey::ViewSubscriptions->label()]));

        $this->actingAs($reader)->get(route('payments.index', ['statement' => $own->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('statement.subscription.id', $own->id)->where('subscriptions.data.0.meterBoxName', $own->meterBox?->displayName()));
        $this->get(route('payments.index', ['statement' => $foreign->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('statement', null));
    }

    public function test_a_search_finds_subscriptions_by_name_account_number_phone_or_meter_box_number(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Mohammed Hamdan', 'phone' => '0591234567']);
        $subscription->meterBox->update(['box_number' => '7734']);
        Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Somebody Else', 'phone' => '0569999999']);

        foreach (['Hamdan', $subscription->account_number, '059 123 4567', '7734'] as $search) {
            $this->actingAs($this->collector)->get(route('payments.index', ['search' => $search]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('filters.search', $search)
                    ->has('subscriptions.data', 1)
                    ->where('subscriptions.data.0.id', $subscription->id)
                    ->where('subscriptions.data.0.fullName', 'Mohammed Hamdan')
                    ->where('subscriptions.data.0.accountNumber', $subscription->account_number)
                    ->where('subscriptions.data.0.meterBoxNumber', '7734'));
        }
    }

    public function test_opening_a_statement_does_not_query_the_subscriber_table_again(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->collector->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $version = app(HandleInertiaRequests::class)->version(request());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($this->collector)->getJson(route('payments.index', ['statement' => $subscription->id]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Payments/Index',
            'X-Inertia-Partial-Data' => 'statement',
        ])->assertJsonPath('props.statement.subscription.id', $subscription->id)
            ->assertJsonMissingPath('props.subscriptions')
            ->assertJsonMissingPath('props.filterOptions');

        $tableCounts = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'count(*) as aggregate from "subscriptions"'));
        DB::disableQueryLog();
        $this->assertCount(0, $tableCounts, 'Opening a statement must not paginate the underlying subscriber list.');
    }

    public function test_a_search_never_finds_a_subscription_of_another_branch_unless_the_user_is_the_super_admin(): void
    {
        $other = Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id, 'full_name' => 'Faraway Customer']);

        $this->actingAs($this->collector)->get(route('payments.index', ['search' => 'Faraway']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('subscriptions.data', 0));

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('payments.index', ['search' => 'Faraway']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $other->id)->where('scopeLabel', 'كل الفروع'));
    }

    public function test_a_row_carries_what_the_subscriber_owes(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Owing Customer', 'minimum_charge' => 15]);
        $subscription->transactions()->create(['recorded_by' => $this->collector->id, 'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE, 'source_key' => 'fee:1', 'amount' => '120.00']);
        SubscriptionTransaction::recordPayment($subscription, $this->collector, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($this->collector)->get(route('payments.index', ['search' => 'Owing']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subscriptions.data.0.balance', '100.00')
                ->where('subscriptions.data.0.weeklyMinimumPayment', fn ($minimum) => (float) $minimum === 15.0));
    }

    public function test_the_balance_filter_separates_those_who_owe_from_the_settled_and_those_in_credit(): void
    {
        $owing = $this->subscriptionWithBalance('Owes', 100);
        $settled = $this->subscriptionWithBalance('Settled', 0);
        $credit = $this->subscriptionWithBalance('Credit', -40);

        $idsFor = fn (string $balance): array => collect($this->actingAs($this->collector)->get(route('payments.index', ['filter' => ['balance' => $balance]]))
            ->viewData('page')['props']['subscriptions']['data'])->pluck('id')->all();

        $this->assertSame([$owing->id], $idsFor('owing'));
        $this->assertSame([$settled->id], $idsFor('settled'));
        $this->assertSame([$credit->id], $idsFor('credit'));
    }

    public function test_the_table_filters_by_status_and_sorts_by_balance(): void
    {
        $small = $this->subscriptionWithBalance('Small', 50);
        $large = $this->subscriptionWithBalance('Large', 900);
        $middle = $this->subscriptionWithBalance('Middle', 300);
        $middle->update(['status' => SubscriptionStatus::Disconnected]);

        $this->actingAs($this->collector)->get(route('payments.index', ['sort' => 'outstanding_balance', 'direction' => 'desc']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'outstanding_balance')
                ->where('subscriptions.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$large->id, $middle->id, $small->id]));

        $this->get(route('payments.index', ['filter' => ['status' => 'disconnected']]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.id', $middle->id));
    }

    public function test_the_filter_menu_offers_what_the_balance_status_and_type_filters_choose_from(): void
    {
        $this->actingAs($this->collector)->get(route('payments.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filterOptions', fn ($groups): bool => collect($groups)->pluck('key')->take(3)->all() === ['balance', 'status', 'tariff_id']
                && collect($groups)->firstWhere('key', 'balance')['options'][0]['value'] === 'owing'));
    }

    public function test_the_day_shows_only_the_users_own_live_payments(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $cash = SubscriptionTransaction::recordPayment($subscription, $this->collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);
        SubscriptionTransaction::recordPayment($subscription, $this->collector, [
            'amount' => '250.50', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Payer', 'reference_number' => 'TR-1',
        ]);
        SubscriptionTransaction::recordPayment($subscription, $this->userAllowedTo(PermissionKey::RecordCollections), ['amount' => '999', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $cancelled = SubscriptionTransaction::recordPayment($subscription, $this->collector, ['amount' => '40', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $cancelled->update(['cancelled_at' => now()]);

        $this->actingAs($this->collector)->get(route('payments.summary'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('today.count', 2)
                ->where('today.total', '350.50')
                ->where('today.cash', '100.00')
                ->where('today.transfers', '250.50')
                ->has('today.payments', 2)
                ->where('today.payments.1.id', $cash->id)
                ->where('today.payments.1.amount', '100')
                ->where('today.payments.0.bankName', 'بنك فلسطين'));
    }

    public function test_someone_who_may_only_record_payments_can_print_their_receipt(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $payment = SubscriptionTransaction::recordPayment($subscription, $this->collector, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($this->collector)
            ->get(route('subscriptions.payments.receipt', [$subscription, $payment]))
            ->assertOk();

        $elsewhere = Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $elsewherePayment = SubscriptionTransaction::recordPayment($elsewhere, User::factory()->superAdmin()->create(), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($this->collector)
            ->get(route('subscriptions.payments.receipt', [$elsewhere, $elsewherePayment]))
            ->assertForbidden();
    }

    private function subscriptionWithBalance(string $name, int $balance): Subscription
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => $name]);

        if ($balance !== 0) {
            $subscription->transactions()->create(['recorded_by' => $this->collector->id, 'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE, 'source_key' => 'fee:'.$subscription->id, 'amount' => $balance.'.00']);
        }

        return $subscription;
    }

    public function test_the_charge_discount_and_clearing_actions_each_follow_their_own_permission(): void
    {
        Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $charger = $this->userAllowedTo(PermissionKey::RecordCollections);
        $charger->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::RecordCashPayments, PermissionKey::AddCharges]));

        $this->actingAs($this->collector)->get(route('payments.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subscriptions.data.0.canAddCharge', false)
                ->where('subscriptions.data.0.canAddDiscount', false)
                ->where('subscriptions.data.0.canAddClearing', false)
                ->where('discountSegments', []));

        $this->actingAs($charger)->get(route('payments.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subscriptions.data.0.canAddCharge', true)
                ->where('subscriptions.data.0.canAddDiscount', false)
                ->where('subscriptions.data.0.canAddClearing', false)
                ->has('chargeTypes.0.value'));
    }

    private function userAllowedTo(PermissionKey $key): User
    {
        $user = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $user->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['key' => $key->value], ['label' => $key->label()]));

        return $user;
    }
}
