<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionBalanceFilterTest extends TestCase
{
    use RefreshDatabase;

    private function withBalance(User $actor, string $amount, array $attributes = []): Subscription
    {
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'minimum_charge' => 10, ...$attributes]);
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => $amount]);

        return $subscription;
    }

    #[DataProvider('balanceCases')]
    public function test_balance_filters_use_the_chosen_side_and_exact_boundaries(string $side, string $operator, string $amount, ?string $to, array $expected): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscriptions = collect(['0.00', '19.99', '20.00', '20.01', '-19.99', '-20.00', '-20.01'])
            ->map(fn (string $balance): Subscription => $this->withBalance($actor, $balance));
        Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $foreign = Subscription::factory()->create();
        SubscriptionTransaction::factory()->for($foreign)->create(['amount' => $side === 'debit' ? 30 : -30]);
        $filter = ['balance_side' => $side, 'balance_operator' => $operator, 'balance_amount' => $amount, 'balance_to' => $to];
        $expectedIds = collect($expected)->map(fn (int $index): int => $subscriptions[$index]->id)->sort()->values()->all();

        $response = $this->actingAs($actor)->get(route('subscriptions.index', ['filter' => $filter]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscriptions.data', count($expected))
            ->where('subscriptions.total', count($expected))
            ->where('subscriptions.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === $expectedIds));
    }

    public static function balanceCases(): array
    {
        return [
            'debt above' => ['debit', 'gt', '20', null, [3]],
            'debt below excludes settled and credit' => ['debit', 'lt', '20', null, [1]],
            'debt equal' => ['debit', 'eq', '20', null, [2]],
            'debt range includes endpoints' => ['debit', 'between', '19.99', '20', [1, 2]],
            'credit above' => ['credit', 'gt', '20', null, [6]],
            'credit below excludes settled and debt' => ['credit', 'lt', '20', null, [4]],
            'credit equal' => ['credit', 'eq', '20', null, [5]],
            'credit range includes endpoints' => ['credit', 'between', '19.99', '20', [4, 5]],
        ];
    }

    public function test_bulk_minimum_changes_only_matching_net_balances_and_preserves_transactions(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $match = $this->withBalance($actor, '30.00', ['status' => SubscriptionStatus::Active]);
        $refunded = $this->withBalance($actor, '30.00', ['status' => SubscriptionStatus::Active]);
        SubscriptionTransaction::factory()->for($refunded)->create(['source_key' => 'refund-test', 'amount' => '-30.00']);
        $small = $this->withBalance($actor, '10.00', ['status' => SubscriptionStatus::Active]);
        $excludedStatus = $this->withBalance($actor, '30.00', ['status' => SubscriptionStatus::Disconnected]);
        $foreign = Subscription::factory()->create(['minimum_charge' => 10]);
        SubscriptionTransaction::factory()->for($foreign)->create(['amount' => '30.00']);
        $transactions = SubscriptionTransaction::query()->orderBy('id')->get(['id', 'amount'])->toArray();

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 25, 'all' => true, 'expected_count' => 1,
            'filter' => ['balance_side' => 'debit', 'balance_operator' => 'gt', 'balance_amount' => '20', 'status' => 'active'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(25.0, (float) $match->fresh()->minimum_charge);
        foreach ([$refunded, $small, $excludedStatus, $foreign] as $unchanged) {
            $this->assertSame(10.0, (float) $unchanged->fresh()->minimum_charge);
        }
        $this->assertSame($transactions, SubscriptionTransaction::query()->orderBy('id')->get(['id', 'amount'])->toArray());
        $this->assertSame(1, SubscriptionBulkChange::sole()->changed_count);
    }

    public function test_changed_selection_count_stops_the_bulk_change(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = $this->withBalance($actor, '30');

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 25, 'all' => true, 'expected_count' => 2,
            'filter' => ['balance_side' => 'debit', 'balance_operator' => 'gt', 'balance_amount' => '20'],
        ])->assertSessionHasErrors('ids');

        $this->assertSame(10.0, (float) $subscription->fresh()->minimum_charge);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_balance_filters_cannot_broaden_a_bulk_action(array $filter, string $error): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = $this->withBalance($actor, '30');

        $this->actingAs($actor)->post(route('subscriptions.bulk-changes.store'), [
            'field' => 'minimum_charge', 'mode' => 'amount', 'value' => 25, 'all' => true, 'filter' => $filter,
        ])->assertSessionHasErrors($error);

        $this->assertSame(10.0, (float) $subscription->fresh()->minimum_charge);
        $this->assertDatabaseCount('subscription_bulk_changes', 0);
    }

    public static function invalidFilters(): array
    {
        $valid = ['balance_side' => 'debit', 'balance_operator' => 'gt', 'balance_amount' => '20'];

        return [
            'missing amount' => [['balance_side' => 'debit'], 'filter.balance_amount'],
            'injected operator' => [[...$valid, 'balance_operator' => '> 0 OR 1=1'], 'filter.balance_operator'],
            'negative amount' => [[...$valid, 'balance_amount' => '-1'], 'filter.balance_amount'],
            'precision' => [[...$valid, 'balance_amount' => '20.001'], 'filter.balance_amount'],
            'reversed range' => [[...$valid, 'balance_operator' => 'between', 'balance_to' => '10'], 'filter.balance_to'],
        ];
    }

    public function test_message_preview_resolves_the_same_filters_without_crossing_branches(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $match = $this->withBalance($actor, '-19.99', ['full_name' => 'Ali Target', 'status' => SubscriptionStatus::Disconnected]);
        $this->withBalance($actor, '-10', ['full_name' => 'Other Person']);
        $this->withBalance($actor, '10', ['full_name' => 'Ali Debt']);
        $foreign = Subscription::factory()->create(['full_name' => 'Ali Foreign']);
        SubscriptionTransaction::factory()->for($foreign)->create(['amount' => '-10']);

        $this->actingAs($actor)->postJson(route('messages.recipients.preview'), [
            'kind' => 'custom', 'selection' => ['all' => true, 'search' => 'Ali', 'filter' => ['balance_side' => 'credit', 'balance_operator' => 'lt', 'balance_amount' => '20']],
        ])->assertJsonCount(1, 'recipients')->assertJsonPath('recipients.0.id', $match->id);
    }

    public function test_unauthorized_users_cannot_resolve_a_bulk_message_selection(): void
    {
        $actor = User::factory()->collector()->create();

        $this->actingAs($actor)->postJson(route('messages.recipients.preview'), ['selection' => ['all' => true]])->assertForbidden();
    }

    public function test_bulk_message_preview_does_not_truncate_selections_to_the_old_400_limit(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        Subscription::factory()->count(401)->create(['branch_id' => $actor->branch_id, 'registered_by' => $actor->id, 'meter_box_id' => null, 'circuit_breaker_id' => null]);

        $this->actingAs($actor)->postJson(route('messages.recipients.preview'), [
            'kind' => 'custom', 'selection' => ['all' => true],
        ])->assertJsonCount(401, 'recipients');

        $this->assertDatabaseCount('message_batches', 0);
        $this->assertDatabaseCount('subscription_messages', 0);
    }

    public function test_invalid_balance_in_a_message_selection_returns_validation_errors(): void
    {
        $actor = User::factory()->branchAdmin()->create();

        $this->actingAs($actor)->postJson(route('messages.recipients.preview'), [
            'kind' => 'custom', 'selection' => ['all' => true, 'filter' => ['balance_side' => 'credit']],
        ])->assertJsonValidationErrors('filter.balance_amount');
    }
}
