<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\PermissionKey;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Sami']);
    }

    public function test_a_subscription_added_by_mistake_is_deleted_with_their_subscription_fee(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
        SubscriptionTransaction::factory()->for($subscription)->create();

        $this->deleteAs($this->superAdmin, route('subscriptions.destroy', $subscription))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'subscription-deleted')
            ->assertRedirect(route('subscriptions.index'));

        $this->assertModelMissing($subscription);
        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertSame(['action' => 'subscription-deleted', 'subject' => 'Ahmad'], $this->superAdmin->notifications()->sole()->data);
    }

    public function test_a_subscription_with_payments_is_kept_and_the_user_is_told_why(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        SubscriptionTransaction::factory()->for($subscription)->create();
        SubscriptionTransaction::recordPayment($subscription, $this->superAdmin, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->deleteAs($this->superAdmin, route('subscriptions.destroy', $subscription))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف المشترك لوجود سجلات مرتبطة به — الحركات المالية: 1. يمكنك تغيير حالته إلى «مفصول» بدلًا من حذفه.']);

        $this->assertModelExists($subscription);
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_a_subscription_with_a_subscription_fee_loaded_later_cannot_be_deleted(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        SubscriptionTransaction::recordCharge($subscription, $this->superAdmin, ChargeType::SubscriptionFee, '50', null);

        $this->deleteAs($this->superAdmin, route('subscriptions.destroy', $subscription))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف المشترك لوجود سجلات مرتبطة به — الحركات المالية: 1. يمكنك تغيير حالته إلى «مفصول» بدلًا من حذفه.']);

        $this->assertModelExists($subscription);
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_deleting_takes_the_delete_permission_within_the_users_own_branch(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $otherBranchAdmin = User::factory()->branchAdmin()->create();
        $otherBranchAdmin->permissions()->attach(Permission::idsFor([PermissionKey::DeleteSubscriptions]));

        $this->deleteAs($branchAdmin, route('subscriptions.destroy', $subscription))->assertForbidden();
        $this->deleteAs($otherBranchAdmin, route('subscriptions.destroy', $subscription))->assertForbidden();

        $branchAdmin->permissions()->attach(Permission::idsFor([PermissionKey::DeleteSubscriptions]));
        $this->actingAs($branchAdmin->fresh())
            ->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.canDelete', true));
        $this->deleteAs($branchAdmin->fresh(), route('subscriptions.destroy', $subscription))->assertSessionHasNoErrors();
        $this->assertModelMissing($subscription);
    }

    public function test_an_unused_user_account_is_deleted_and_signed_out(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $collector->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->deleteAs($this->superAdmin, route('users.destroy', $collector))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'user-deleted');

        $this->assertModelMissing($collector);
        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);
        $this->assertDatabaseMissing('permission_user', ['user_id' => $collector->id]);
    }

    public function test_a_user_who_recorded_work_is_kept_and_nobody_deletes_their_own_account(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        Subscription::factory()->create(['branch_id' => $this->branch->id, 'registered_by' => $collector->id]);

        $this->deleteAs($this->superAdmin, route('users.destroy', $collector))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف المستخدم لوجود سجلات مرتبطة به — المشتركون المسجّلون: 1. يمكنك إيقاف حسابه بدلًا من حذفه.']);
        $this->deleteAs($this->superAdmin, route('users.destroy', $this->superAdmin))->assertForbidden();

        $this->assertModelExists($collector);
    }

    public function test_a_tariff_or_segment_in_use_is_kept_and_an_unused_one_is_deleted(): void
    {
        $tariff = Tariff::factory()->create();
        $segment = TariffSegment::factory()->create();
        Subscription::factory()->create(['tariff_id' => $tariff->id, 'tariff_segment_id' => $segment->id]);
        $unusedSegment = TariffSegment::factory()->create();

        $this->deleteAs($this->superAdmin, route('tariffs.destroy', $tariff))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف التعرفة لوجود سجلات مرتبطة به — المشتركون: 1.']);
        $this->deleteAs($this->superAdmin, route('tariff-segments.destroy', $segment))->assertSessionHasErrors('delete');
        $this->deleteAs($this->superAdmin, route('tariff-segments.destroy', $unusedSegment))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'tariff-segment-deleted');

        $this->assertModelExists($tariff);
        $this->assertModelExists($segment);
        $this->assertModelMissing($unusedSegment);
    }

    public function test_places_are_deleted_only_once_nothing_is_under_them(): void
    {
        $governorate = Governorate::factory()->create();
        $area = Area::factory()->create(['governorate_id' => $governorate->id]);
        $meterBox = MeterBox::factory()->create(['branch_id' => $this->branch->id]);

        $this->deleteAs($this->superAdmin, route('governorates.destroy', $governorate))->assertSessionHasErrors('delete');
        $this->deleteAs($this->superAdmin, route('branches.destroy', $this->branch))->assertSessionHasErrors('delete');
        $this->deleteAs($this->superAdmin, route('meter-boxes.destroy', $meterBox))->assertSessionHasNoErrors();
        $this->deleteAs($this->superAdmin, route('areas.destroy', $area))->assertSessionHasNoErrors();
        $this->deleteAs($this->superAdmin, route('governorates.destroy', $governorate))->assertSessionHasNoErrors();

        $this->assertModelMissing($meterBox);
        $this->assertModelMissing($area);
        $this->assertModelMissing($governorate);
        $this->assertModelExists($this->branch);
    }

    private function deleteAs(User $user, string $uri): TestResponse
    {
        return $this->actingAs($user)->from(route('subscriptions.index'))->delete($uri);
    }
}
