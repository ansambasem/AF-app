<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One subscriber from the day they are registered to the day their file is
 * archived and brought back, through the branch's real pages: waiting to be
 * connected, connected and charged their fee, read and billed week by week,
 * paying, disconnected and charged for it, connected again, paid up,
 * disconnected for good and archived. Their statement is checked at the end
 * line by line.
 */
class SubscriberLifecycleJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subscriber_goes_from_registration_to_archive_and_back_with_a_statement_that_adds_up(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        [$branch, $head, $collector, $dataEntry, $tariff] = $this->arrangeBranch();

        $this->actingAs($head)->post(route('subscriptions.store'), [
            'full_name' => 'سالم عبد الله', 'national_id' => '401234567', 'phone' => '0599123456',
            'tariff_id' => $tariff->id, 'status' => SubscriptionStatus::Suspended->value,
            'minimum_charge' => 10, 'subscription_fee' => '100', 'charge_subscription_fee' => false,
        ])->assertSessionHasNoErrors();
        $subscription = Subscription::sole();
        $this->assertSame('202600001', $subscription->account_number);
        $this->assertNull($subscription->activated_at);
        $this->assertSame(0.0, $subscription->balance());
        $this->actingAs($dataEntry)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-10-16', 'current_reading' => 50,
        ])->assertSessionHasErrors('subscription_id');

        $this->updateSubscription($head, $subscription, ['status' => 'active', 'initial_reading' => 1000, 'charge_subscription_fee' => true])->assertSessionHasNoErrors();
        $subscription->refresh();
        $this->assertNotNull($subscription->activated_at);
        $this->assertSame('2026-10-22', $subscription->subscription_date->toDateString());
        $this->assertSame(100.0, $subscription->balance());
        $this->updateSubscription($head, $subscription, ['status' => 'suspended'])->assertSessionHasErrors('status');
        $this->updateSubscription($head, $subscription, ['charge_subscription_fee' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_SUBSCRIPTION_FEE)->count());

        $this->actingAs($dataEntry)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-10-16', 'current_reading' => 1080,
        ])->assertSessionHasNoErrors();
        $this->assertSame(100.0, $subscription->balance());
        $this->actingAs($head)->post(route('meter-readings.approve'), ['reading_ids' => [MeterReading::sole()->id]])->assertSessionHasNoErrors();
        $this->assertSame(140.0, $subscription->balance());

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '90', 'currency' => 'ILS', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame(50.0, $subscription->balance());

        $this->updateSubscription($head, $subscription, ['status' => 'disconnected'])->assertSessionHasNoErrors();
        $this->actingAs($head)->post(route('subscriptions.charges.store', $subscription), ['type' => 'disconnection_fee', 'amount' => '20'])->assertSessionHasNoErrors();
        $this->assertSame(70.0, $subscription->balance());

        $this->travelTo('2026-10-29 08:00:00');
        $this->actingAs($dataEntry)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-10-23', 'current_reading' => 1090,
        ])->assertSessionHasErrors('subscription_id');
        $this->updateSubscription($head, $subscription->refresh(), ['status' => 'active'])->assertSessionHasNoErrors();
        $subscription->refresh();
        $this->assertSame('2026-10-29', $subscription->reconnected_at->toDateString());
        $this->assertSame('2026-10-22', $subscription->subscription_date->toDateString());

        $this->actingAs($dataEntry)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-10-23', 'current_reading' => 1090,
        ])->assertSessionHasNoErrors();
        $secondWeek = MeterReading::query()->whereDate('week_start', '2026-10-23')->sole();
        $this->assertSame(1080.0, $secondWeek->previous_reading);
        $this->actingAs($head)->post(route('meter-readings.approve'), ['reading_ids' => [$secondWeek->id]])->assertSessionHasNoErrors();
        $this->assertSame('10.00', $secondWeek->fresh()->amount_due);
        $this->assertSame(80.0, $subscription->balance());

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '80', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'سالم عبد الله', 'reference_number' => 'TRF-LIFE-1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $subscription->balance());

        $this->actingAs($head)->post(route('subscriptions.archive.store', $subscription), [
            'service_ended_on' => '2026-10-29', 'reason' => 'إنهاء الخدمة', 'confirmed' => true,
        ])->assertSessionHasErrors('archive');
        $this->updateSubscription($head, $subscription, ['status' => 'disconnected'])->assertSessionHasNoErrors();
        $this->actingAs($head)->delete(route('subscriptions.destroy', $subscription))->assertSessionHasErrors('delete');
        $this->actingAs($head)->post(route('subscriptions.archive.store', $subscription), [
            'service_ended_on' => '2026-10-29', 'reason' => 'إنهاء الخدمة', 'confirmed' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($subscription->fresh()->archived_at);
        $this->actingAs($head)->post(route('subscriptions.charges.store', $subscription), ['type' => 'penalty', 'amount' => '5', 'notes' => 'بعد الأرشفة'])->assertForbidden();
        $this->actingAs($collector)->get(route('subscriptions.statement', $subscription))->assertForbidden();
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('subscription.statusLabel', 'مؤرشف')
            ->where('summary.balance', '0.00'));

        $this->actingAs($head)->delete(route('subscriptions.archive.destroy', $subscription), ['reason' => 'عودة المشترك', 'confirmed' => true])->assertSessionHasNoErrors();

        $subscription->refresh();
        $this->assertNull($subscription->archived_at);
        $this->assertSame(SubscriptionStatus::Disconnected, $subscription->status);
        $this->assertSame(['archive', 'restore'], array_column($subscription->archive_history, 'action'));
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '0.00')
            ->where('summary.charged', '170.00')
            ->where('summary.paid', '170.00')
            ->where('summary.paymentsCount', 2)
            ->where('entries', function ($entries): bool {
                $this->assertSame(['subscription_fee', 'meter_reading', 'payment', 'disconnection_fee', 'meter_reading', 'payment'], $entries->pluck('type')->all());
                $this->assertSame(['100.00', '140.00', '50.00', '70.00', '80.00', '0.00'], $entries->pluck('balance')->all());
                $this->assertSame('000001', $entries[2]['voucherNumber']);
                $this->assertNull($entries[5]['voucherNumber']);
                $this->assertSame('TRF-LIFE-1', $entries[5]['referenceNumber']);

                return true;
            }));
    }

    public function test_a_subscription_added_by_mistake_is_deleted_with_its_fee_but_not_once_it_has_history(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        [, $head, $collector, , $tariff] = $this->arrangeBranch();
        foreach (['401111111', '402222222'] as $nationalId) {
            $this->actingAs($head)->post(route('subscriptions.store'), [
                'full_name' => 'مشترك '.$nationalId, 'national_id' => $nationalId, 'phone' => '0599123456', 'tariff_id' => $tariff->id,
                'status' => 'active', 'initial_reading' => 0, 'minimum_charge' => 0, 'subscription_fee' => '50', 'charge_subscription_fee' => true,
            ])->assertSessionHasNoErrors();
        }
        [$mistake, $kept] = Subscription::query()->orderBy('id')->get()->all();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $kept), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->actingAs($head)->delete(route('subscriptions.destroy', $mistake))->assertSessionHasNoErrors();
        $this->actingAs($head)->delete(route('subscriptions.destroy', $kept))->assertSessionHasErrors('delete');

        $this->assertModelMissing($mistake);
        $this->assertSame(0, SubscriptionTransaction::query()->where('subscription_id', $mistake->id)->count());
        $this->assertModelExists($kept);
        $this->assertSame(40.0, $kept->balance());
    }

    public function test_a_second_subscription_of_the_same_person_shares_their_profile_and_keeps_its_own_account(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        [, $head, $collector, , $tariff] = $this->arrangeBranch();
        $this->actingAs($head)->post(route('subscriptions.store'), [
            'full_name' => 'ليلى حسن', 'national_id' => '403333333', 'phone' => '0599123456', 'tariff_id' => $tariff->id,
            'status' => 'active', 'initial_reading' => 0, 'minimum_charge' => 0, 'subscription_fee' => '60', 'charge_subscription_fee' => true,
        ])->assertSessionHasNoErrors();
        $first = Subscription::sole();

        $this->actingAs($head)->post(route('subscriptions.store'), [
            'source_subscription_id' => $first->id, 'subscription_name' => 'محل ليلى', 'tariff_id' => $tariff->id,
            'status' => 'active', 'initial_reading' => 0, 'minimum_charge' => 0, 'subscription_fee' => '40', 'charge_subscription_fee' => true,
        ])->assertSessionHasNoErrors();
        $second = Subscription::query()->whereKeyNot($first->id)->sole();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $second), ['amount' => '15', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertSame($first->subscriber_profile_id, $second->subscriber_profile_id);
        $this->assertSame('202600002', $second->account_number);
        $this->assertSame(60.0, $first->balance());
        $this->assertSame(25.0, $second->balance());
        $this->actingAs($head)->get(route('subscriptions.statement', $second))->assertInertia(fn ($page) => $page
            ->where('subscription.fullName', 'محل ليلى')
            ->where('subscriptions', fn ($siblings): bool => $siblings->pluck('balance', 'accountNumber')->all() === ['202600001' => '60.00', '202600002' => '25.00']));
    }

    /**
     * @return array{Branch, User, User, User, Tariff}
     */
    private function arrangeBranch(): array
    {
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->withPermissions([
            PermissionKey::DeleteSubscriptions, PermissionKey::ArchiveSubscriptions,
            PermissionKey::ViewArchivedSubscriptions, PermissionKey::RestoreArchivedSubscriptions,
        ])->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $dataEntry = User::factory()->dataEntry()->for($branch)->create();
        $tariff = Tariff::factory()->residential()->create(['rate' => '0.50']);
        CircuitBreaker::factory()->create(['minimum_payment' => '10.00']);

        return [$branch, $head, $collector, $dataEntry, $tariff];
    }

    /**
     * Save the subscription's edit form with the given changes.
     *
     * @param  array<string, mixed>  $changes
     */
    private function updateSubscription(User $actor, Subscription $subscription, array $changes): TestResponse
    {
        return $this->actingAs($actor)->put(route('subscriptions.update', $subscription), [
            'full_name' => $subscription->full_name, 'national_id' => $subscription->national_id, 'phone' => $subscription->phone,
            'tariff_id' => $subscription->tariff_id, 'status' => $subscription->status->value, 'minimum_charge' => $subscription->minimum_charge,
            'initial_reading' => $subscription->initial_reading, 'subscription_fee' => $subscription->subscription_fee,
            'subscription_date' => $subscription->subscription_date?->toDateString(), ...$changes,
        ]);
    }
}
