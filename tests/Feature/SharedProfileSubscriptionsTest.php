<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\MeterBox;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\SubArea;
use App\Models\SubscriberProfile;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SharedProfileSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_subscription_cards_include_the_meter_box_name_suffix_number_and_area(): void
    {
        $area = Area::factory()->create(['name' => 'المخيم']);
        $subArea = SubArea::factory()->create(['name' => 'الحي الشرقي', 'area_id' => $area->id]);
        $actor = User::factory()->branchAdmin()->create();
        $actor->branch->update(['area_id' => $area->id]);
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'sub_area_id' => $subArea->id, 'name' => 'طبلون السوق', 'name_suffix' => 'أ', 'box_number' => '110303']);
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id]);
        Subscription::factory()->for($subscription->profile, 'profile')->create(['branch_id' => $actor->branch_id, 'meter_box_id' => null]);

        $this->actingAs($actor)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->has('subscriptions', 2)
            ->where('subscriptions.0.meterBoxName', 'طبلون السوق أ')
            ->where('subscriptions.0.meterBoxNumber', '110303')
            ->where('subscriptions.0.areaName', 'المخيم')
            ->where('subscriptions.0.subAreaName', 'الحي الشرقي')
            ->where('subscriptions.1.meterBoxName', null)
            ->where('subscriptions.1.subAreaName', null));
    }

    public function test_an_additional_subscription_shares_personal_details_but_has_its_own_account_and_financial_history(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create([
            'branch_id' => $actor->branch_id,
            'full_name' => 'Ahmad',
            'national_id' => '012345678',
            'phone' => '0591234567',
            'address' => 'Shared address',
            'initial_reading' => 500,
            'minimum_charge' => 35,
        ]);
        SubscriptionTransaction::factory()->for($source)->create(['amount' => '100.00']);
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.store'), [
            ...$this->subscriptionPayload($source),
            'full_name' => 'Untrusted replacement',
            'national_id' => '999999999',
            'phone' => 'invalid',
            'address' => 'Different address',
            'meter_box_id' => $box->id,
            'subscription_fee' => 25,
            'charge_subscription_fee' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $additional = Subscription::whereKeyNot($source->id)->sole();
        $this->assertSame($source->subscriber_profile_id, $additional->subscriber_profile_id);
        $this->assertNotSame($source->account_number, $additional->account_number);
        $this->assertSame(['Ahmad', '012345678', '0591234567', 'Shared address'], array_values($additional->only(SubscriberProfile::PERSONAL_FIELDS)));
        $this->assertSame($box->id, $additional->meter_box_id);
        $this->assertSame(0.0, $additional->initial_reading);
        $this->assertSame(12.0, (float) $additional->minimum_charge);
        $this->assertSame(100.0, $source->balance());
        $this->assertSame(25.0, $additional->balance());
        $this->assertSame(0, $additional->meterReadings()->count());
        $this->assertDatabaseCount('subscriber_profiles', 1);
        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $additional->id,
            'type' => SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE,
            'amount' => 25,
        ]);

        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.subscriptionCount', 2)
            ->where('subscriptions.data.1.subscriptionCount', 2));
    }

    public function test_editing_personal_details_updates_all_subscriptions_without_changing_their_subscription_fields(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'minimum_charge' => 35]);
        $additional = Subscription::factory()->for($source->profile, 'profile')->create([
            'branch_id' => $actor->branch_id,
            'minimum_charge' => 75,
            'initial_reading' => 450,
        ]);
        $boxId = $additional->meter_box_id;

        $this->actingAs($actor)->put(route('subscriptions.update', $source), [
            'full_name' => 'Updated name',
            'national_id' => '023456789',
            'phone' => '0561234567',
            'address' => 'Updated address',
            'tariff_id' => $source->tariff_id,
            'status' => 'active',
            'minimum_charge' => 40,
            'initial_reading' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        foreach ([$source, $additional] as $sibling) {
            $this->assertDatabaseHas('subscriptions', [
                'id' => $sibling->id,
                'full_name' => 'Updated name',
                'national_id' => '023456789',
                'phone' => '0561234567',
                'address' => 'Updated address',
            ]);
        }

        $this->assertDatabaseHas('subscriber_profiles', ['id' => $source->subscriber_profile_id, 'national_id' => '023456789']);
        $this->assertDatabaseHas('subscriptions', ['id' => $source->id, 'minimum_charge' => 40]);
        $this->assertDatabaseHas('subscriptions', ['id' => $additional->id, 'minimum_charge' => 75, 'initial_reading' => 450, 'meter_box_id' => $boxId]);
    }

    public function test_a_shared_identity_number_still_cannot_be_changed_to_another_persons_identity(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'national_id' => '012345678']);
        Subscription::factory()->for($source->profile, 'profile')->create(['branch_id' => $actor->branch_id]);
        Subscription::factory()->create(['national_id' => '023456789']);

        $this->actingAs($actor)->put(route('subscriptions.update', $source), [
            'full_name' => 'Name',
            'national_id' => '023456789',
            'phone' => '0591234567',
            'tariff_id' => $source->tariff_id,
            'status' => 'active',
            'minimum_charge' => 10,
            'initial_reading' => 0,
        ])->assertSessionHasErrors('national_id');

        $this->assertSame(2, Subscription::where('national_id', '012345678')->count());
    }

    public function test_adding_a_subscription_cannot_read_personal_details_from_another_branch(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create();

        $this->actingAs($actor)->post(route('subscriptions.store'), $this->subscriptionPayload($source))->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscriber_profiles', 1);
    }

    public function test_adding_a_subscription_requires_the_create_subscriptions_permission(): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.store'), $this->subscriptionPayload($source))->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_adding_a_subscription_requires_authentication(): void
    {
        $source = Subscription::factory()->create();

        $this->post(route('subscriptions.store'), $this->subscriptionPayload($source))->assertRedirect(route('login'));

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_a_new_subscription_still_requires_its_own_subscription_details(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.store'), ['source_subscription_id' => $source->id])
            ->assertSessionHasErrors(['tariff_id', 'status', 'minimum_charge']);

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_a_cash_payment_can_be_recorded_without_a_cash_box_number(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '15',
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $subscription->id, 'amount' => -15, 'cash_box' => null]);
    }

    public function test_the_table_exposes_area_two_effective_minimum_and_a_signed_balance(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subArea = SubArea::factory()->create(['name' => 'Area two']);
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'sub_area_id' => $subArea->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'minimum_charge' => null]);
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '-20.00']);

        $this->actingAs($actor)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.subAreaName', 'Area two')
            ->where('subscriptions.data.0.weeklyMinimumPayment', (string) $subscription->circuitBreaker->minimum_payment)
            ->where('subscriptions.data.0.outstandingBalance', fn ($value): bool => (float) $value === -20.0));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_deleting_one_subscription_keeps_the_profile_until_its_last_subscription_is_deleted(bool $deleteLast): void
    {
        $actor = User::factory()->superAdmin()->create();
        $source = Subscription::factory()->create();
        $additional = Subscription::factory()->for($source->profile, 'profile')->create(['branch_id' => $source->branch_id]);

        $this->actingAs($actor)->delete(route('subscriptions.destroy', $source))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscriber_profiles', ['id' => $additional->subscriber_profile_id]);
        $this->assertDatabaseHas('subscriptions', ['id' => $additional->id]);

        if ($deleteLast) {
            $this->delete(route('subscriptions.destroy', $additional))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseMissing('subscriber_profiles', ['id' => $additional->subscriber_profile_id]);
        }
    }

    public function test_an_additional_subscription_can_have_its_own_editable_name(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'Mohammed Hamdan']);

        $this->actingAs($actor)->post(route('subscriptions.store'), [
            ...$this->subscriptionPayload($source),
            'subscription_name' => 'Mohammed Hamdan house',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $additional = Subscription::whereKeyNot($source->id)->sole();
        $this->assertSame('Mohammed Hamdan', $additional->full_name);
        $this->assertSame('Mohammed Hamdan house', $additional->displayName());
        $this->assertSame('Mohammed Hamdan', $source->refresh()->displayName());
        $this->assertSame('Mohammed Hamdan', $source->profile->full_name);

        $this->put(route('subscriptions.update', $additional), [
            ...$this->editPayload($additional),
            'subscription_name' => 'Mohammed Hamdan shop',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', ['id' => $additional->id, 'subscription_name' => 'Mohammed Hamdan shop']);
        $this->assertSame('Mohammed Hamdan', $source->refresh()->displayName());
        $this->assertSame('Mohammed Hamdan', $source->profile->fresh()->full_name);
    }

    public function test_personal_name_changes_preserve_the_names_of_individual_subscriptions_and_clearing_a_name_restores_the_fallback(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'Mohammed Hamdan']);
        $house = Subscription::factory()->for($source->profile, 'profile')->create([
            'branch_id' => $actor->branch_id,
            'subscription_name' => 'Mohammed Hamdan house',
        ]);

        $this->actingAs($actor)->put(route('subscriptions.update', $source), [
            ...$this->editPayload($source),
            'full_name' => 'Updated personal name',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $this->assertSame('Updated personal name', $house->refresh()->full_name);
        $this->assertSame('Mohammed Hamdan house', $house->displayName());
        $this->assertSame('Updated personal name', $source->refresh()->displayName());

        $this->put(route('subscriptions.update', $house), [
            ...$this->editPayload($house),
            'subscription_name' => '',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $this->assertNull($house->refresh()->subscription_name);
        $this->assertSame('Updated personal name', $house->displayName());
    }

    public function test_search_returns_two_distinct_names_and_each_statement_keeps_its_own_history(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'Mohammed Hamdan']);
        $house = Subscription::factory()->for($source->profile, 'profile')->create([
            'branch_id' => $actor->branch_id,
            'subscription_name' => 'Mohammed Hamdan house',
        ]);
        SubscriptionTransaction::factory()->for($source)->create(['amount' => '15.00']);
        SubscriptionTransaction::factory()->for($house)->create(['amount' => '75.00']);

        $this->actingAs($actor)->get(route('subscriptions.index', ['search' => 'Mohammed Hamdan']))
            ->assertInertia(fn ($page) => $page
                ->has('subscriptions.data', 2)
                ->where('subscriptions.data.0.id', $source->id)
                ->where('subscriptions.data.0.display_name', 'Mohammed Hamdan')
                ->where('subscriptions.data.1.id', $house->id)
                ->where('subscriptions.data.1.display_name', 'Mohammed Hamdan house'));

        $this->get(route('subscriptions.index', ['search' => 'house']))->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $house->id));

        $this->get(route('subscriptions.statement', $source))->assertInertia(fn ($page) => $page
            ->where('subscription.fullName', 'Mohammed Hamdan')
            ->has('entries', 1)
            ->where('summary.balance', '15.00'));
        $this->get(route('subscriptions.statement', $house))->assertInertia(fn ($page) => $page
            ->where('subscription.fullName', 'Mohammed Hamdan house')
            ->has('entries', 1)
            ->where('summary.balance', '75.00'));
    }

    public function test_subscription_name_search_stays_scoped_to_the_users_branch(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        Subscription::factory()->create(['subscription_name' => 'Hidden house']);

        $this->actingAs($actor)->get(route('subscriptions.index', ['search' => 'Hidden house']))
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 0));
    }

    public function test_subscription_names_are_limited_to_255_characters(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.store'), [
            ...$this->subscriptionPayload($source),
            'subscription_name' => str_repeat('a', 256),
        ])->assertSessionHasErrors('subscription_name');

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_mobile_search_and_collection_history_display_the_subscription_name(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $house = Subscription::factory()->create([
            'branch_id' => $actor->branch_id,
            'full_name' => 'Mohammed Hamdan',
            'subscription_name' => 'Mohammed Hamdan house',
        ]);
        $this->actingAs($actor)->post(route('subscriptions.payments.store', $house), [
            'amount' => 15,
            'currency' => 'ILS',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->withHeader('Authorization', 'Bearer '.MobileAccessToken::issue($actor));
        $this->getJson(route('mobile.readings.index', ['search' => 'house']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Mohammed Hamdan house');
        $this->getJson(route('mobile.collections.subscriptions', ['search' => 'house']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Mohammed Hamdan house');
        $this->getJson(route('mobile.collections.index'))
            ->assertOk()->assertJsonPath('data.0.subscription', 'Mohammed Hamdan house');
    }

    public function test_a_subscription_can_use_its_own_phone_and_name_without_changing_the_original_subscription(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $original = Subscription::factory()->create([
            'branch_id' => $actor->branch_id,
            'full_name' => 'Mohammed Hamdan',
            'phone' => '0591234567',
        ]);

        $this->actingAs($actor)->post(route('subscriptions.store'), [
            ...$this->subscriptionPayload($original),
            'subscription_name' => 'Mohammed Hamdan house',
            'subscription_phone' => '0561234567',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $house = Subscription::whereKeyNot($original->id)->sole();
        $this->assertSame('Mohammed Hamdan house', $house->displayName());
        $this->assertSame('0561234567', $house->contactPhone());
        $this->assertSame('Mohammed Hamdan', $original->refresh()->displayName());
        $this->assertSame('0591234567', $original->contactPhone());
        $this->assertSame('0591234567', $original->profile->phone);

        $this->put(route('subscriptions.update', $house), [
            ...$this->editPayload($house),
            'subscription_name' => 'Mohammed Hamdan shop',
            'subscription_phone' => '0597654321',
        ])->assertSessionHasNoErrors()->assertRedirect(route('subscriptions.index'));

        $this->assertDatabaseHas('subscriptions', ['id' => $house->id, 'subscription_name' => 'Mohammed Hamdan shop', 'subscription_phone' => '0597654321']);
        $this->assertSame('Mohammed Hamdan', $original->refresh()->displayName());
        $this->assertSame('0591234567', $original->contactPhone());
        $this->get(route('subscriptions.index', ['search' => '0597654321']))->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $house->id)
            ->where('subscriptions.data.0.contact_phone', '0597654321'));
        $this->get(route('subscriptions.statement', $house))->assertInertia(fn ($page) => $page
            ->where('subscription.fullName', 'Mohammed Hamdan shop')
            ->where('subscription.phone', '0597654321'));
    }

    public function test_a_subscription_phone_keeps_its_override_when_shared_personal_details_change(): void
    {
        $original = Subscription::factory()->create(['phone' => '0591234567']);
        $house = Subscription::factory()->for($original->profile, 'profile')->create(['subscription_phone' => '0561234567']);

        $original->update(['phone' => '0597654321']);

        $this->assertSame('0561234567', $house->refresh()->contactPhone());
        $this->assertSame('0597654321', $original->contactPhone());
        $this->assertDatabaseHas('subscriptions', ['id' => $house->id, 'subscription_phone' => '0561234567']);
    }

    #[TestWith(['123'])]
    #[TestWith(['0581234567'])]
    #[TestWith(['not-a-phone'])]
    public function test_an_additional_subscription_rejects_invalid_contact_phone_numbers(string $phone): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $original = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('subscriptions.store'), [
            ...$this->subscriptionPayload($original),
            'subscription_phone' => $phone,
        ])->assertSessionHasErrors('subscription_phone');

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_an_additional_subscription_keeps_the_persons_subscriber_number_while_a_new_person_gets_the_next_one(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $this->actingAs($actor);

        $this->post(route('subscriptions.store'), $this->subscriptionPayload($source))->assertSessionHasNoErrors();
        $newPerson = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $additional = Subscription::whereKeyNot([$source->id, $newPerson->id])->sole();
        $this->assertSame(1, $source->profile->subscriber_number);
        $this->assertSame(1, $additional->profile->subscriber_number);
        $this->assertSame(2, $newPerson->profile->subscriber_number);
    }

    public function test_searching_a_subscriber_number_lists_all_of_that_persons_subscriptions_in_the_users_branch(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'account_number' => '202600011']);
        $house = Subscription::factory()->for($source->profile, 'profile')->create(['branch_id' => $actor->branch_id, 'account_number' => '202600012']);
        Subscription::factory()->for($source->profile, 'profile')->create();
        Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        $source->profile->forceFill(['subscriber_number' => 7350])->save();

        $response = $this->actingAs($actor)->get(route('subscriptions.index', ['search' => '7350']));

        $response->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 2)
            ->where('subscriptions.data.0.id', $source->id)
            ->where('subscriptions.data.0.subscriber_number', 7350)
            ->where('subscriptions.data.1.id', $house->id)
            ->where('subscriptions.data.1.subscriber_number', 7350));
    }

    public function test_the_statement_lists_the_persons_other_subscriptions_with_their_balances_but_not_those_in_other_branches(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $source = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'account_number' => '202600001', 'full_name' => 'Mohammed Hamdan']);
        $house = Subscription::factory()->for($source->profile, 'profile')->create([
            'branch_id' => $actor->branch_id,
            'account_number' => '202600002',
            'subscription_name' => 'Mohammed Hamdan house',
        ]);
        Subscription::factory()->for($source->profile, 'profile')->create();
        SubscriptionTransaction::factory()->for($source)->create(['amount' => '15.00']);
        SubscriptionTransaction::factory()->for($house)->create(['amount' => '-20.50']);

        $response = $this->actingAs($actor)->get(route('subscriptions.statement', $source));

        $response->assertInertia(fn ($page) => $page
            ->where('subscription.subscriberNumber', $source->profile->subscriber_number)
            ->has('entries', 1)
            ->has('subscriptions', 2)
            ->where('subscriptions.0.id', $source->id)
            ->where('subscriptions.0.fullName', 'Mohammed Hamdan')
            ->where('subscriptions.0.balance', '15.00')
            ->where('subscriptions.1.id', $house->id)
            ->where('subscriptions.1.fullName', 'Mohammed Hamdan house')
            ->where('subscriptions.1.balance', '-20.50'));
    }

    /** @return array<string, mixed> */
    private function editPayload(Subscription $subscription): array
    {
        return [
            ...$subscription->only(SubscriberProfile::PERSONAL_FIELDS),
            'tariff_id' => $subscription->tariff_id,
            'status' => $subscription->status->value,
            'minimum_charge' => $subscription->minimum_charge,
            'initial_reading' => $subscription->initial_reading,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(Subscription $source): array
    {
        return [
            'source_subscription_id' => $source->id,
            'tariff_id' => $source->tariff_id,
            'status' => 'suspended',
            'minimum_charge' => 12,
            'initial_reading' => 0,
        ];
    }
}
