<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Branch;
use App\Models\SubscriberProfile;
use App\Models\SubscriberProfileChange;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalDetailsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_persons_details_records_who_changed_what_from_what(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $admin->branch_id, 'full_name' => 'Old Name', 'phone' => '0591111111', 'address' => 'Rafah']);

        $this->travelTo('2026-10-05 10:00:00');
        $this->actingAs($admin)
            ->patch(route('subscriptions.personal-details.update', $subscription), [
                'full_name' => 'New Name',
                'national_id' => $subscription->national_id,
                'phone' => '0591111111',
                'address' => 'Gaza',
            ])
            ->assertSessionHasNoErrors();

        $change = SubscriberProfileChange::sole();
        $this->assertSame($subscription->subscriber_profile_id, $change->subscriber_profile_id);
        $this->assertSame($admin->id, $change->user_id);
        $this->assertSame($admin->branch_id, $change->branch_id);
        $this->assertSame('2026-10-05 10:00:00', $change->created_at->toDateTimeString());
        $this->assertEquals([
            'full_name' => ['from' => 'Old Name', 'to' => 'New Name'],
            'address' => ['from' => 'Rafah', 'to' => 'Gaza'],
        ], $change->changes);
    }

    public function test_saving_without_changing_anything_and_creating_a_person_record_no_history(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $admin->branch_id]);
        $this->assertSame(0, SubscriberProfileChange::count(), 'a new person is not a change');

        $this->actingAs($admin)
            ->patch(route('subscriptions.personal-details.update', $subscription), [
                'full_name' => $subscription->full_name,
                'national_id' => $subscription->national_id,
                'phone' => $subscription->phone,
                'address' => $subscription->address,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, SubscriberProfileChange::count());
    }

    public function test_a_change_made_from_the_subscription_form_is_recorded_too(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $admin->branch_id, 'full_name' => 'Old Name']);

        $this->actingAs($admin)
            ->put(route('subscriptions.update', $subscription), [
                'full_name' => 'Renamed Person',
                'national_id' => $subscription->national_id,
                'phone' => $subscription->phone,
                'tariff_id' => $subscription->tariff_id,
                'status' => $subscription->status->value,
                'minimum_charge' => $subscription->minimum_charge,
                'initial_reading' => $subscription->initial_reading,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(['full_name' => ['from' => 'Old Name', 'to' => 'Renamed Person']], SubscriberProfileChange::sole()->changes);
    }

    public function test_the_history_lists_every_change_newest_first_including_one_made_from_another_branch(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $otherBranch = Branch::factory()->create(['name' => 'Khan Younis']);
        $otherAdmin = User::factory()->branchAdmin()->create(['branch_id' => $otherBranch->id, 'name' => 'Other Admin']);
        $mine = Subscription::factory()->create(['branch_id' => $admin->branch_id, 'full_name' => 'First Name']);
        $inOtherBranch = Subscription::factory()->create(['branch_id' => $otherBranch->id, 'subscriber_profile_id' => $mine->subscriber_profile_id]);

        $this->travelTo('2026-10-01 09:00:00');
        $this->actingAs($admin)->patch(route('subscriptions.personal-details.update', $mine), ['full_name' => 'Second Name', 'national_id' => $mine->national_id, 'phone' => $mine->phone])->assertSessionHasNoErrors();
        $this->travelTo('2026-10-02 09:00:00');
        $this->actingAs($otherAdmin)->patch(route('subscriptions.personal-details.update', $inOtherBranch), ['full_name' => 'Third Name', 'national_id' => $mine->national_id, 'phone' => '0561234567'])->assertSessionHasNoErrors();

        $history = $this->actingAs($admin)->getJson(route('subscriptions.personal-details.history', $mine))->assertOk()->json('data');

        $this->assertCount(2, $history);
        $this->assertSame('Other Admin', $history[0]['userName']);
        $this->assertMatchesRegularExpression('#^02/10/2026 \d{2}:\d{2}$#', $history[0]['at']);
        $this->assertSame('Khan Younis', $history[0]['branchName']);
        $this->assertSame(['full_name', 'phone'], array_column($history[0]['changes'], 'field'));
        $this->assertSame(['Second Name', 'Third Name'], [$history[0]['changes'][0]['from'], $history[0]['changes'][0]['to']]);
        $this->assertSame('الاسم الكامل', $history[0]['changes'][0]['label']);
        $this->assertSame($admin->name, $history[1]['userName']);
    }

    public function test_the_history_is_only_for_someone_who_may_see_the_subscription(): void
    {
        $admin = User::factory()->branchAdmin()->create();
        $foreign = Subscription::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        SubscriberProfileChange::create(['subscriber_profile_id' => $foreign->subscriber_profile_id, 'changes' => ['full_name' => ['from' => 'A', 'to' => 'B']]]);

        $this->actingAs($admin)->getJson(route('subscriptions.personal-details.history', $foreign))->assertForbidden();
    }

    public function test_a_change_without_a_signed_in_user_is_still_recorded(): void
    {
        $profile = SubscriberProfile::factory()->create(['full_name' => 'Before']);

        $profile->update(['full_name' => 'After']);

        $change = SubscriberProfileChange::sole();
        $this->assertNull($change->user_id);
        $this->assertEquals(['full_name' => ['from' => 'Before', 'to' => 'After']], $change->changes);
    }
}
