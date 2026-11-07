<?php

namespace Tests\Feature\Disconnections;

use App\Enums\DisconnectionEventType;
use App\Enums\DisconnectionItemStatus;
use App\Enums\MessageKind;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendSubscriptionMessage;
use App\Models\Branch;
use App\Models\DisconnectionEvent;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class DisconnectionListTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private DisconnectionList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->list = DisconnectionList::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->branchAdmin->id, 'title' => 'قائمة أكتوبر']);
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::factory()->create(['branch_id' => $this->branch->id, 'circuit_breaker_id' => null, 'phone' => '0599123456', ...$attributes]);
    }

    private function charge(Subscription $subscription, string $amount): void
    {
        $subscription->transactions()->create([
            'recorded_by' => $this->branchAdmin->id,
            'type' => 'penalty',
            'source_key' => 'charge:'.Str::ulid(),
            'amount' => $amount,
        ]);
    }

    private function item(Subscription $subscription, array $attributes = []): DisconnectionListItem
    {
        return DisconnectionListItem::factory()->create([
            'disconnection_list_id' => $this->list->id,
            'subscription_id' => $subscription->id,
            'added_by' => $this->branchAdmin->id,
            ...$attributes,
        ]);
    }

    private function inDays(int $days): string
    {
        return now(config('app.business_timezone'))->addDays($days)->toDateString();
    }

    public function test_guests_and_users_without_the_permission_cannot_open_the_lists(): void
    {
        $this->get(route('disconnection-lists.index'))->assertRedirect(route('login'));

        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($collector)->get(route('disconnection-lists.index'))->assertForbidden();
        $this->actingAs($collector)->get(route('disconnection-lists.show', $this->list))->assertForbidden();
    }

    public function test_each_branch_sees_and_works_on_its_own_lists_only(): void
    {
        $otherAdmin = User::factory()->branchAdmin()->create(['branch_id' => Branch::factory()->create()->id]);
        $subscription = $this->subscription();
        $item = $this->item($subscription);

        $this->actingAs($otherAdmin)->get(route('disconnection-lists.index'))
            ->assertInertia(fn ($page) => $page->component('Disconnections/Index')->where('lists.total', 0));
        $this->actingAs($otherAdmin)->get(route('disconnection-lists.show', $this->list))->assertForbidden();
        $this->actingAs($otherAdmin)->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '20'], 'confirmed' => true])->assertForbidden();

        $this->assertSame(DisconnectionItemStatus::Scheduled, $item->fresh()->status);
    }

    public function test_a_branch_admin_opens_a_list_for_their_own_branch(): void
    {
        $other = Branch::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.store'), ['title' => 'حملة نوفمبر', 'branch_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->branch->id, DisconnectionList::query()->where('title', 'حملة نوفمبر')->sole()->branch_id);
    }

    public function test_a_subscriber_with_no_debt_can_be_listed_with_a_reason_and_the_addition_is_recorded(): void
    {
        $subscription = $this->subscription();

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.store', $this->list), [
                'ids' => [$subscription->id],
                'reason' => 'violation',
                'note' => 'توصيل غير نظامي',
                'scheduled_for' => $this->inDays(2),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'disconnection-items-added');

        $item = DisconnectionListItem::query()->sole();
        $this->assertSame(['violation', 'توصيل غير نظامي', $this->inDays(2), '0.00'], [$item->reason->value, $item->note, $item->scheduled_for->toDateString(), $item->balance_when_added]);
        $this->assertSame(DisconnectionEventType::Added, $item->events()->sole()->type);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_a_reason_is_required_and_the_date_cannot_be_in_the_past(): void
    {
        $subscription = $this->subscription();

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.store', $this->list), ['ids' => [$subscription->id], 'reason' => '', 'scheduled_for' => $this->inDays(-1)])
            ->assertSessionHasErrors(['reason', 'scheduled_for']);

        $this->assertSame(0, DisconnectionListItem::query()->count());
    }

    public function test_subscriptions_that_cannot_be_added_are_reported_one_by_one(): void
    {
        $eligible = $this->subscription();
        $disconnected = $this->subscription(['status' => SubscriptionStatus::Disconnected]);
        $alreadyListed = $this->subscription();
        $other = DisconnectionList::factory()->create(['branch_id' => $this->branch->id]);
        DisconnectionListItem::factory()->create(['disconnection_list_id' => $other->id, 'subscription_id' => $alreadyListed->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.store', $this->list), [
                'ids' => [$eligible->id, $disconnected->id, $alreadyListed->id],
                'reason' => 'late_payment',
                'scheduled_for' => $this->inDays(1),
            ])
            ->assertSessionHas('status', 'disconnection-items-added')
            ->assertSessionHas('inertia.flash_data.disconnectionResult', fn (array $result): bool => $result['succeeded'] === 1
                && collect($result['failed'])->pluck('reason', 'subscriptionId')->all() === [
                    $disconnected->id => DisconnectionList::SKIP_REASONS['not_active'],
                    $alreadyListed->id => DisconnectionList::SKIP_REASONS['already_listed'],
                ]);

        $this->assertSame([$eligible->id], $this->list->items()->pluck('subscription_id')->all());
    }

    public function test_every_matching_candidate_can_be_added_at_once(): void
    {
        $owing = $this->subscription();
        $this->charge($owing, '80');
        $clear = $this->subscription();
        $otherBranch = Subscription::factory()->create();
        $this->charge($otherBranch, '80');

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.store', $this->list), [
                'all' => true,
                'filter' => ['balance' => 'owing'],
                'reason' => 'accumulated_debt',
                'scheduled_for' => $this->inDays(1),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([$owing->id], $this->list->items()->pluck('subscription_id')->all());
        $this->assertSame('80.00', $this->list->items()->sole()->balance_when_added);
        $this->assertNotContains($clear->id, $this->list->items()->pluck('subscription_id'));
    }

    public function test_a_subscriber_who_pays_stays_on_the_list_with_the_new_balance_shown(): void
    {
        $subscription = $this->subscription();
        $this->charge($subscription, '100');
        $this->item($subscription, ['balance_when_added' => '100.00']);
        $this->charge($subscription, '-100');

        $this->actingAs($this->branchAdmin)->get(route('disconnection-lists.show', $this->list))
            ->assertInertia(fn ($page) => $page
                ->component('Disconnections/Show')
                ->where('items.data.0.status', 'scheduled')
                ->where('items.data.0.balance', '0.00')
                ->where('items.data.0.balanceWhenAdded', '100.00')
                ->where('items.data.0.isPaid', true)
                ->where('items.data.0.fee', '20.00')
                ->where('usualFee', '20.00')
                ->where('abilities.changeFee', true));
    }

    public function test_an_overdue_item_waits_for_a_manual_action(): void
    {
        $subscription = $this->subscription();
        $item = $this->item($subscription, ['scheduled_for' => $this->inDays(-3)]);

        $this->actingAs($this->branchAdmin)->get(route('disconnection-lists.show', $this->list))
            ->assertInertia(fn ($page) => $page->where('items.data.0.isOverdue', true)->where('summary.overdue', 1));

        $this->assertSame(DisconnectionItemStatus::Scheduled, $item->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_dates_can_be_extended_any_number_of_times_without_sending_anything(): void
    {
        Queue::fake();
        $items = collect([$this->item($this->subscription()), $this->item($this->subscription())]);

        foreach ([5, 9, 14] as $days) {
            $this->actingAs($this->branchAdmin)
                ->post(route('disconnection-lists.items.reschedule', $this->list), ['ids' => $items->pluck('id')->all(), 'scheduled_for' => $this->inDays($days), 'reason' => 'طلب مهلة'])
                ->assertSessionHas('status', 'disconnection-items-rescheduled');
        }

        foreach ($items as $item) {
            $item->refresh();
            $this->assertSame([3, $this->inDays(14)], [$item->extensions_count, $item->scheduled_for->toDateString()]);
            $this->assertSame(
                [[$this->inDays(3), $this->inDays(5)], [$this->inDays(5), $this->inDays(9)], [$this->inDays(9), $this->inDays(14)]],
                $item->events()->where('type', DisconnectionEventType::Extended)->orderBy('id')->get()->map(fn (DisconnectionEvent $event) => [$event->old_values['scheduled_for'], $event->new_values['scheduled_for']])->all(),
            );
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, MessageBatch::query()->count());
        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_removing_keeps_the_history_and_frees_the_subscription_for_another_list(): void
    {
        $subscription = $this->subscription();
        $item = $this->item($subscription);

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.remove', $this->list), ['ids' => [$item->id], 'reason' => 'سدّد'])
            ->assertSessionHas('status', 'disconnection-items-removed');

        $item->refresh();
        $this->assertSame([DisconnectionItemStatus::Removed, null, 'سدّد'], [$item->status, $item->active_subscription_id, $item->removal_reason]);
        $this->assertSame(DisconnectionEventType::Removed, $item->events()->latest('id')->first()->type);

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.store', $this->list), ['ids' => [$subscription->id], 'reason' => 'late_payment', 'scheduled_for' => $this->inDays(1)])
            ->assertSessionHas('status', 'disconnection-items-added');

        $this->assertSame(2, $this->list->items()->count());
    }

    public function test_the_preview_of_every_match_reaches_exactly_the_filtered_items_across_pages(): void
    {
        $overdue = collect(range(1, 30))->map(fn () => $this->item($this->subscription(), ['scheduled_for' => $this->inDays(-1)]));
        $this->item($this->subscription(), ['scheduled_for' => $this->inDays(4)]);

        $this->actingAs($this->branchAdmin)
            ->postJson(route('disconnection-lists.items.preview', $this->list), ['all' => true, 'filter' => ['timing' => 'overdue']])
            ->assertOk()
            ->assertJsonCount(30, 'items')
            ->assertJsonPath('tooMany', false)
            ->assertJson(fn ($json) => $json->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $overdue->pluck('id')->sort()->values()->all())->etc());
    }

    public function test_confirming_a_disconnection_disconnects_the_service_and_charges_the_fee_once(): void
    {
        $subscription = $this->subscription();
        $item = $this->item($subscription);
        $payload = ['ids' => [$item->id], 'fees' => [$item->id => '20.00'], 'confirmed' => true];

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), $payload)
            ->assertSessionHas('status', 'disconnections-confirmed');
        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), $payload)
            ->assertSessionHasErrors('ids')
            ->assertSessionHas('inertia.flash_data.disconnectionResult', fn (array $result): bool => $result['failed'][0]['reason'] === DisconnectionListItem::OUTCOMES['already_disconnected']);

        $item->refresh();
        $fee = SubscriptionTransaction::query()->sole();
        $this->assertSame(['disconnection_fee', '20.00', 'disconnection:'.$item->id], [$fee->type, $fee->amount, $fee->source_key]);
        $this->assertSame([DisconnectionItemStatus::Disconnected, '20.00', $fee->id], [$item->status, $item->fee_amount, $item->fee_transaction_id]);
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->fresh()->status);
        $this->assertSame(20.0, $subscription->fresh()->balance());
    }

    public function test_nothing_is_charged_for_listing_warning_or_extending_until_the_disconnection_is_confirmed(): void
    {
        Queue::fake();
        $item = $this->item($this->subscription());

        $this->actingAs($this->branchAdmin)->post(route('disconnection-lists.items.reschedule', $this->list), ['ids' => [$item->id], 'scheduled_for' => $this->inDays(5)]);
        $this->actingAs($this->branchAdmin)->post(route('disconnection-lists.items.warn', $this->list), ['ids' => [$item->id], 'channel' => 'sms', 'body' => 'إنذار', 'operation_id' => (string) Str::uuid()]);

        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_a_disconnection_must_be_explicitly_confirmed_and_needs_its_permission(): void
    {
        $item = $this->item($this->subscription());
        $viewer = User::factory()->dataEntry()->withPermissions([PermissionKey::ViewDisconnections, PermissionKey::ManageDisconnections])->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '20']])
            ->assertSessionHasErrors('confirmed');
        $this->actingAs($viewer)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '20'], 'confirmed' => true])
            ->assertForbidden();

        $this->assertSame(DisconnectionItemStatus::Scheduled, $item->fresh()->status);
        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_the_fee_chosen_when_confirming_is_charged_for_each_subscriber(): void
    {
        $changed = $this->subscription();
        $exempt = $this->subscription();
        $items = [$this->item($changed), $this->item($exempt)];

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), [
                'ids' => [$items[0]->id, $items[1]->id],
                'fees' => [$items[0]->id => '35.50', $items[1]->id => '0'],
                'confirmed' => true,
            ])
            ->assertSessionHas('status', 'disconnections-confirmed')
            ->assertSessionHas('inertia.flash_data.disconnectionResult', fn (array $result): bool => $result['succeeded'] === 2 && $result['feesTotal'] === '35.50');

        $this->assertSame(['35.50'], SubscriptionTransaction::query()->pluck('amount')->all());
        $this->assertSame(['35.50', '0.00'], [$items[0]->fresh()->fee_amount, $items[1]->fresh()->fee_amount]);
        $this->assertSame(SubscriptionStatus::Disconnected, $exempt->fresh()->status);
    }

    public function test_without_its_permission_the_usual_fee_is_charged_whatever_is_sent(): void
    {
        $item = $this->item($this->subscription());
        $executor = User::factory()->dataEntry()->withPermissions([PermissionKey::ViewDisconnections, PermissionKey::ExecuteDisconnections])->create(['branch_id' => $this->branch->id]);

        $this->actingAs($executor)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '0'], 'confirmed' => true])
            ->assertSessionHas('status', 'disconnections-confirmed');

        $this->assertSame('20.00', SubscriptionTransaction::query()->sole()->amount);
    }

    public function test_a_fee_that_is_missing_or_negative_confirms_nothing(): void
    {
        $item = $this->item($this->subscription());

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '-5'], 'confirmed' => true])
            ->assertSessionHasErrors('fees.'.$item->id);
        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => ''], 'confirmed' => true])
            ->assertSessionHasErrors('fees.'.$item->id);

        $this->assertSame(DisconnectionItemStatus::Scheduled, $item->fresh()->status);
        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_a_subscriber_whose_service_is_no_longer_on_is_not_charged(): void
    {
        $subscription = $this->subscription();
        $item = $this->item($subscription);
        $subscription->update(['status' => SubscriptionStatus::Disconnected]);

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '20'], 'confirmed' => true])
            ->assertSessionHasErrors('ids');

        $this->assertSame(0, SubscriptionTransaction::query()->count());
        $this->assertSame(DisconnectionItemStatus::Scheduled, $item->fresh()->status);
    }

    public function test_confirming_the_same_item_again_never_charges_twice(): void
    {
        $item = $this->item($this->subscription());

        $this->assertSame('disconnected', $item->confirmDisconnection($this->branchAdmin, '20'));
        $this->assertSame('already_disconnected', DisconnectionListItem::query()->findOrFail($item->id)->confirmDisconnection($this->branchAdmin, '20'));

        $this->assertSame(1, SubscriptionTransaction::query()->where('type', 'disconnection_fee')->count());
    }

    public function test_a_warning_is_sent_once_per_send_with_each_subscribers_details_and_recorded(): void
    {
        Queue::fake();
        $subscription = $this->subscription(['full_name' => 'سامي']);
        $this->charge($subscription, '75');
        $item = $this->item($subscription, ['scheduled_for' => '2026-10-25']);
        $noPhone = $this->item($this->subscription(['phone' => null]));
        $payload = [
            'ids' => [$item->id, $noPhone->id],
            'channel' => 'sms',
            'body' => '{الاسم}: عليك {الرصيد} شيكل، القطع بتاريخ {موعد_القطع}',
            'operation_id' => (string) Str::uuid(),
        ];

        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.warn', $this->list), $payload)
            ->assertSessionHas('status', 'disconnection-warnings-queued')
            ->assertSessionHas('inertia.flash_data.disconnectionResult', fn (array $result): bool => $result['succeeded'] === 1 && $result['failed'][0]['id'] === $noPhone->id);
        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.warn', $this->list), $payload)
            ->assertRedirect(route('messages.show', MessageBatch::query()->sole()));

        $batch = MessageBatch::query()->sole();
        $this->assertSame(MessageKind::DisconnectionWarning, $batch->kind);
        $this->assertSame('سامي: عليك 75 شيكل، القطع بتاريخ 25/10/2026', $batch->messages()->sole()->body);
        Queue::assertPushed(SendSubscriptionMessage::class, 1);

        $item->refresh();
        $this->assertSame(1, $item->warnings_count);
        $this->assertNotNull($item->last_warned_at);
        $this->assertSame($batch->messages()->sole()->id, $item->events()->where('type', DisconnectionEventType::Warned)->sole()->subscription_message_id);
    }

    public function test_a_deliberate_second_warning_is_a_new_send(): void
    {
        Queue::fake();
        $item = $this->item($this->subscription());

        foreach (range(1, 2) as $time) {
            $this->actingAs($this->branchAdmin)->post(route('disconnection-lists.items.warn', $this->list), ['ids' => [$item->id], 'channel' => 'whatsapp', 'body' => 'إنذار', 'operation_id' => (string) Str::uuid()]);
        }

        $this->assertSame(2, MessageBatch::query()->count());
        $this->assertSame(2, $item->fresh()->warnings_count);
        Queue::assertNothingPushed();
    }

    public function test_warnings_need_their_own_permission(): void
    {
        $item = $this->item($this->subscription());
        $manager = User::factory()->dataEntry()->withPermissions([PermissionKey::ManageDisconnections])->create(['branch_id' => $this->branch->id]);

        $this->actingAs($manager)
            ->post(route('disconnection-lists.items.warn', $this->list), ['ids' => [$item->id], 'channel' => 'sms', 'body' => 'إنذار', 'operation_id' => (string) Str::uuid()])
            ->assertForbidden();
        $this->actingAs($manager)
            ->postJson(route('disconnection-lists.items.preview', $this->list), ['ids' => [$item->id]])
            ->assertJsonPath('items.0.phone', null);
    }

    public function test_a_closed_list_takes_no_more_changes(): void
    {
        $item = $this->item($this->subscription());

        $this->actingAs($this->branchAdmin)->patch(route('disconnection-lists.update', $this->list), ['closed' => true])
            ->assertSessionHas('status', 'disconnection-list-closed');
        $this->actingAs($this->branchAdmin)
            ->post(route('disconnection-lists.items.confirm', $this->list), ['ids' => [$item->id], 'fees' => [$item->id => '20'], 'confirmed' => true])
            ->assertSessionHasErrors('ids');

        $this->assertSame(0, SubscriptionTransaction::query()->count());
    }

    public function test_the_history_of_an_item_is_never_changed_or_deleted(): void
    {
        $this->actingAs($this->branchAdmin)->post(route('disconnection-lists.items.store', $this->list), ['ids' => [$this->subscription()->id], 'reason' => 'other', 'scheduled_for' => $this->inDays(1)]);
        $event = DisconnectionEvent::query()->sole();

        $this->expectException(LogicException::class);

        $event->delete();
    }
}
