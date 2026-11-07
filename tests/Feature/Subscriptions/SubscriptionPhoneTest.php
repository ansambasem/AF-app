<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_personal_number_changes_when_the_subscription_has_none_of_its_own(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => '0591111111', 'subscription_phone' => null]);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.phone.update', $subscription), ['phone' => '٠٥٦ ٢٢٢-٢٢٢٢'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'subscription-phone-updated');

        $this->assertSame('0562222222', $subscription->fresh()->phone);
        $this->assertSame('0562222222', $subscription->fresh()->profile->phone);
    }

    public function test_the_subscriptions_own_number_changes_when_it_has_one(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => '0591111111', 'subscription_phone' => '0593333333']);

        $this->actingAs($branchAdmin)->patch(route('subscriptions.phone.update', $subscription), ['phone' => '0594444444'])->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertSame('0594444444', $subscription->subscription_phone);
        $this->assertSame('0591111111', $subscription->phone);
    }

    public function test_a_number_in_the_wrong_format_is_refused(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $branchAdmin->branch_id, 'phone' => '0591111111', 'subscription_phone' => null]);

        $this->actingAs($branchAdmin)
            ->patch(route('subscriptions.phone.update', $subscription), ['phone' => '0521234567'])
            ->assertSessionHasErrors(['phone' => 'رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056.']);

        $this->assertSame('0591111111', $subscription->fresh()->phone);
    }

    public function test_another_branchs_subscription_cannot_be_changed(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $foreign = Subscription::factory()->create(['phone' => '0591111111', 'subscription_phone' => null]);

        $this->actingAs($branchAdmin)->patch(route('subscriptions.phone.update', $foreign), ['phone' => '0594444444'])->assertForbidden();

        $this->assertSame('0591111111', $foreign->fresh()->phone);
    }
}
