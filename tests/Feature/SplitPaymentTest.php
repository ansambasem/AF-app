<?php

namespace Tests\Feature;

use App\Enums\CorrectionReason;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    /** @var array<int, Subscription> */
    private array $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->cashier = $this->userAllowedTo(PermissionKey::RecordCollections);
        $this->subscriptions = array_map(fn (int $owed): Subscription => $this->subscriptionOwing($owed), [640, 380, 270]);
    }

    public function test_one_transfer_becomes_a_payment_on_each_subscription_that_share_its_reference_and_sender(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'split-payment-recorded');

        $split = SplitPayment::sole();
        $this->assertSame('1000.00', $split->total_amount);
        $this->assertSame('BOP-558201', $split->reference_number);
        $this->assertSame($this->cashier->id, $split->recorded_by);

        $payments = $split->payments;
        $this->assertSame(['-300.00', '-350.00', '-350.00'], $payments->pluck('amount')->all());
        $this->assertSame(array_map(fn (Subscription $subscription): int => $subscription->id, $this->subscriptions), $payments->pluck('subscription_id')->all());
        $this->assertSame(['BOP-558201'], $payments->pluck('reference_number')->unique()->values()->all());
        $this->assertSame(['محمد حمدان'], $payments->pluck('sender_name')->unique()->values()->all());
        $this->assertSame(['bank_transfer'], $payments->map(fn (SubscriptionTransaction $payment): string => $payment->payment_method->value)->unique()->values()->all());
        $this->assertSame([340.0, 30.0, -80.0], array_map(fn (Subscription $subscription): float => $subscription->balance(), $this->subscriptions));
        $this->assertSame('1000.00', $split->standingTotal());
    }

    public function test_a_transfer_can_be_split_between_any_subscribers_even_with_different_identities(): void
    {
        $relative = Subscription::factory()->create(['branch_id' => $this->branch->id, 'national_id' => '987654321']);
        $this->assertNotEquals($this->subscriptions[0]->subscriber_profile_id, $relative->subscriber_profile_id);
        $this->assertNotEquals($this->subscriptions[0]->national_id, $relative->national_id);

        $payload = [...$this->payload(), 'parts' => [
            ['subscription_id' => $this->subscriptions[0]->id, 'amount' => 600],
            ['subscription_id' => $relative->id, 'amount' => 400],
        ]];

        $this->actingAs($this->cashier)->post(route('payments.split.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$this->subscriptions[0]->id, $relative->id], SplitPayment::sole()->payments->pluck('subscription_id')->all());
    }

    public function test_only_transfers_are_split_so_a_cash_method_in_the_request_changes_nothing(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), [...$this->payload(), 'payment_method' => 'cash', 'cash_box' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['bank_transfer'], SplitPayment::sole()->payments->map(fn (SubscriptionTransaction $payment): string => $payment->payment_method->value)->unique()->values()->all());
        $this->assertNull(SubscriptionTransaction::where('type', 'payment')->whereNotNull('voucher_number')->first());
    }

    public function test_the_parts_must_add_up_to_what_was_transferred(): void
    {
        $payload = $this->payload();
        $payload['parts'][1]['amount'] = 250;

        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), $payload)
            ->assertSessionHasErrors(['parts' => 'مجموع المبالغ الموزَّعة (900 ₪) لا يساوي المبلغ المحوَّل (1000 ₪).']);

        $this->assertDatabaseCount('split_payments', 0);
        $this->assertDatabaseCount('subscription_transactions', 3);
    }

    public function test_a_transfer_needs_at_least_two_different_subscriptions_and_a_reference(): void
    {
        $this->actingAs($this->cashier);

        $this->post(route('payments.split.store'), [...$this->payload(), 'parts' => [['subscription_id' => $this->subscriptions[0]->id, 'amount' => 1000]]])
            ->assertSessionHasErrors('parts');

        $this->post(route('payments.split.store'), [...$this->payload(), 'parts' => [
            ['subscription_id' => $this->subscriptions[0]->id, 'amount' => 500],
            ['subscription_id' => $this->subscriptions[0]->id, 'amount' => 500],
        ]])->assertSessionHasErrors('parts.1.subscription_id');

        $this->post(route('payments.split.store'), [...$this->payload(), 'reference_number' => ''])
            ->assertSessionHasErrors('reference_number');

        $this->assertDatabaseCount('split_payments', 0);
    }

    public function test_nothing_is_saved_when_one_part_cannot_be_taken(): void
    {
        $outsider = Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $payload = $this->payload();
        $payload['parts'][2]['subscription_id'] = $outsider->id;

        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), $payload)
            ->assertSessionHasErrors('parts.2.subscription_id');

        $this->assertDatabaseCount('split_payments', 0);
        $this->assertSame(0, SubscriptionTransaction::where('type', 'payment')->count());
    }

    public function test_a_reference_already_on_another_payment_is_refused_unless_confirmed(): void
    {
        $other = $this->subscriptionOwing(100);
        SubscriptionTransaction::recordPayment($other, $this->cashier, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Someone', 'reference_number' => 'bop 558201',
        ]);

        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), $this->payload())
            ->assertSessionHasErrors('reference_number');
        $this->assertDatabaseCount('split_payments', 0);
        $this->assertSame(1, SubscriptionTransaction::where('type', 'payment')->count());

        $this->post(route('payments.split.store'), [...$this->payload(), 'confirm_duplicate_reference' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, SplitPayment::sole()->payments()->count());
    }

    public function test_the_form_says_whether_the_reference_is_available_or_repeated(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('payments.split.reference-status', ['reference_number' => 'BOP-558201']))
            ->assertOk()
            ->assertJson(['available' => true, 'conflict' => null]);

        $other = $this->subscriptionOwing(100);
        $payment = SubscriptionTransaction::recordPayment($other, $this->cashier, [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Someone', 'reference_number' => 'bop 558201',
        ]);

        $this->getJson(route('payments.split.reference-status', ['reference_number' => 'BOP-558201']))
            ->assertJsonPath('available', false)
            ->assertJsonPath('conflict.id', $payment->id)
            ->assertJsonPath('conflict.subscriptionName', $other->displayName());

        $this->actingAs(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]))
            ->getJson(route('payments.split.reference-status', ['reference_number' => 'BOP-558201']))
            ->assertForbidden();
    }

    public function test_a_part_far_above_what_its_subscription_owes_needs_confirming(): void
    {
        $payload = $this->payload();
        $payload['total_amount'] = 3000;
        $payload['parts'][0]['amount'] = 2300;

        $this->actingAs($this->cashier)
            ->post(route('payments.split.store'), $payload)
            ->assertSessionHasErrors('confirm_overpayment');
        $this->assertDatabaseCount('split_payments', 0);

        $this->post(route('payments.split.store'), [...$payload, 'confirm_overpayment' => true])->assertSessionHasNoErrors();
        $this->assertSame('3000.00', SplitPayment::sole()->standingTotal());
    }

    public function test_someone_who_may_not_record_payments_cannot_split_one(): void
    {
        $this->actingAs(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]))
            ->post(route('payments.split.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_cancelling_one_part_cancels_the_whole_transfer_with_the_same_reason(): void
    {
        $split = $this->recordSplit();
        $parts = $split->payments;

        $parts[1]->cancel(User::factory()->superAdmin()->create(), CorrectionReason::NotReceived, 'bounced');

        $this->assertSame('0.00', $split->standingTotal());
        foreach ($parts as $part) {
            $this->assertNotNull($part->fresh()->cancelled_at);
            $this->assertSame('bounced', $part->fresh()->cancellation_notes);
        }
        $this->assertSame([640.0, 380.0, 270.0], array_map(fn (Subscription $subscription): float => $subscription->balance(), $this->subscriptions));

        $this->actingAs($this->cashier)->get(route('split-payments.show', $split))
            ->assertOk()
            ->assertJsonPath('total', '1000')
            ->assertJsonPath('standingTotal', '0')
            ->assertJsonPath('isComplete', false)
            ->assertJsonPath('parts.1.isCancelled', true);
    }

    public function test_a_corrected_part_stays_a_part_of_the_transfer_and_keeps_sharing_its_reference(): void
    {
        $split = $this->recordSplit();
        $part = $split->payments[1];
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('subscriptions.transactions.update', [$part->subscription, $part]), [
                'amount' => 340, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
                'sender_name' => 'محمد حمدان', 'reference_number' => 'bop-558201',
                'correction_reason' => CorrectionReason::WrongAmount->value, 'correction_notes' => 'typo',
            ])
            ->assertSessionHasNoErrors();

        $replacement = SubscriptionTransaction::where('corrects_id', $part->id)->sole();
        $this->assertSame($split->id, $replacement->split_payment_id);
        $this->assertSame('990.00', $split->standingTotal());
    }

    public function test_cancelling_the_transfer_releases_its_shared_reference(): void
    {
        $split = $this->recordSplit();
        $part = $split->payments[0];
        $part->cancel(User::factory()->superAdmin()->create(), CorrectionReason::Other, 'oops');

        $this->assertNull(SubscriptionTransaction::activeReferenceConflict('BOP-558201', null, $split->id));
        $this->assertNull(SubscriptionTransaction::activeReferenceConflict('BOP-558201'));
    }

    public function test_the_details_list_the_parts_of_the_users_branch_and_only_count_the_rest(): void
    {
        $split = $this->recordSplit();
        $otherBranch = Branch::factory()->create();
        $elsewhere = Subscription::factory()->create(['branch_id' => $otherBranch->id]);
        SubscriptionTransaction::recordPayment($elsewhere, $this->cashier, [
            'amount' => '50', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'محمد حمدان',
            'reference_number' => 'BOP-558201', 'split_payment_id' => $split->id, 'confirm_duplicate_reference' => true,
        ]);

        $this->actingAs($this->cashier)->get(route('split-payments.show', $split))
            ->assertOk()
            ->assertJsonCount(3, 'parts')
            ->assertJsonPath('partsCount', 4)
            ->assertJsonPath('hiddenPartsCount', 1)
            ->assertJsonPath('hiddenPartsAmount', '50')
            ->assertJsonPath('referenceNumber', 'BOP-558201')
            ->assertJsonPath('senderName', 'محمد حمدان');

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('split-payments.show', $split))
            ->assertJsonCount(4, 'parts')
            ->assertJsonPath('hiddenPartsCount', 0);
    }

    public function test_the_details_are_closed_to_someone_who_works_with_none_of_payments_subscriptions_or_closings(): void
    {
        $split = $this->recordSplit();

        $nobody = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $nobody->permissions()->detach();

        $this->actingAs($nobody)->get(route('split-payments.show', $split))->assertForbidden();
    }

    public function test_the_statement_the_ledger_and_the_receipt_mark_a_part_of_a_split_transfer(): void
    {
        $split = $this->recordSplit();
        $part = $split->payments[0];
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('subscriptions.statement', $part->subscription))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries', fn ($entries): bool => collect($entries)->firstWhere('id', $part->id)['splitPayment'] === ['id' => $split->id, 'total' => '1000']));

        $this->actingAs($superAdmin)->get(route('ledger.index', ['side' => 'credit']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries.data', fn ($entries): bool => collect($entries)->pluck('splitPayment.id')->filter()->unique()->all() === [$split->id]));

        $this->actingAs($superAdmin)->get(route('subscriptions.payments.receipt', [$part->subscription, $part]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.splitPayment', ['id' => $split->id, 'total' => '1000', 'partsCount' => 3]));
    }

    public function test_the_payments_search_finds_subscriptions_and_a_payers_other_subscriptions_under_the_same_identity(): void
    {
        $first = $this->subscriptions[0];
        $sibling = Subscription::factory()->create(['branch_id' => $this->branch->id, 'subscriber_profile_id' => $first->subscriber_profile_id, 'full_name' => 'Hamdan Farm']);
        Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id, 'subscriber_profile_id' => $first->subscriber_profile_id]);

        $this->actingAs($this->cashier)->getJson(route('payments.search', ['siblings_of' => $first->id]))
            ->assertOk()
            ->assertJsonCount(1, 'subscriptions')
            ->assertJsonPath('subscriptions.0.id', $sibling->id)
            ->assertJsonPath('subscriptions.0.balance', '0.00');

        $this->actingAs($this->cashier)->getJson(route('payments.search', ['search' => $first->account_number]))
            ->assertJsonPath('subscriptions.0.id', $first->id);

        $this->actingAs(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]))
            ->getJson(route('payments.search', ['search' => 'x']))
            ->assertForbidden();
    }

    private function recordSplit(): SplitPayment
    {
        $this->actingAs($this->cashier)->post(route('payments.split.store'), $this->payload())->assertSessionHasNoErrors();

        return SplitPayment::sole();
    }

    /**
     * The form's request: 1,000 ₪ sent from one bank account, shared between the three subscriptions.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'total_amount' => 1000,
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'محمد حمدان',
            'reference_number' => 'BOP-558201',
            'parts' => [
                ['subscription_id' => $this->subscriptions[0]->id, 'amount' => 300],
                ['subscription_id' => $this->subscriptions[1]->id, 'amount' => 350],
                ['subscription_id' => $this->subscriptions[2]->id, 'amount' => 350],
            ],
        ];
    }

    private function subscriptionOwing(int $owed): Subscription
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $subscription->transactions()->create(['recorded_by' => $this->cashier->id, 'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE, 'source_key' => 'fee:'.$subscription->id, 'amount' => $owed.'.00']);

        return $subscription;
    }

    private function userAllowedTo(PermissionKey $key): User
    {
        $user = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $user->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['key' => $key->value], ['label' => $key->label()]));

        return $user;
    }
}
