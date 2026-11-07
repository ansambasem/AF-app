<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPersonalDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_personal_details_change_on_every_subscription_of_the_person(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'full_name' => 'Old Name', 'national_id' => '111111111']);
        $secondSubscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'subscriber_profile_id' => $subscription->subscriber_profile_id]);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.personal-details.update', $subscription), [
                'full_name' => 'New Name',
                'national_id' => '٢٢٢ ٢٢٢ ٢٢٢',
                'phone' => '056-222-2222',
                'address' => 'Gaza',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'subscription-personal-details-updated');

        foreach ([$subscription, $secondSubscription] as $sibling) {
            $sibling->refresh();
            $this->assertSame(['New Name', '222222222', '0562222222', 'Gaza'], [$sibling->full_name, $sibling->national_id, $sibling->phone, $sibling->address]);
        }

        $this->assertSame('222222222', $subscription->profile->fresh()->national_id);
    }

    public function test_an_old_record_keeps_its_personal_details_without_an_identity_or_mobile_number(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'national_id' => null, 'phone' => null, 'subscription_phone' => null]);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.personal-details.update', $subscription), ['full_name' => 'Old Record', 'national_id' => '', 'phone' => '', 'address' => 'Nuseirat'])
            ->assertSessionHasNoErrors();
        $this->patch(route('subscriptions.personal-details.update', $subscription), ['full_name' => 'Old Record', 'national_id' => '12345', 'phone' => '0511111111'])
            ->assertSessionHasErrors(['national_id', 'phone']);

        $subscription->refresh();
        $this->assertSame(['Old Record', null, null, 'Nuseirat'], [$subscription->full_name, $subscription->national_id, $subscription->phone, $subscription->address]);
        $this->assertNull($subscription->profile->fresh()->national_id);
    }

    public function test_another_persons_identity_number_is_refused(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'national_id' => '333333333']);
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'national_id' => '111111111']);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.personal-details.update', $subscription), ['full_name' => 'Name', 'national_id' => '333333333', 'phone' => '0591111111'])
            ->assertSessionHasErrors(['national_id' => 'رقم الهوية مسجل لشخص آخر.']);

        $this->assertSame('111111111', $subscription->fresh()->national_id);
    }

    public function test_another_branchs_subscription_cannot_be_changed(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreign = Subscription::factory()->create(['full_name' => 'Kept Name']);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.personal-details.update', $foreign), ['full_name' => 'Changed', 'national_id' => '444444444', 'phone' => '0591111111'])
            ->assertForbidden();

        $this->assertSame('Kept Name', $foreign->fresh()->full_name);
    }
}
