<?php

namespace Tests\Feature\Users;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashTransfer;
use App\Models\Closing;
use App\Models\ClosingEvent;
use App\Models\MessageBatch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionBulkChange;
use App\Models\SubscriptionTransaction;
use App\Models\TariffRateChange;
use App\Models\TransactionAmendment;
use App\Models\TransactionDeletion;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_can_delete_their_own_account_from_the_profile_page(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)->delete('/profile', ['password' => 'password'])->assertStatus(405);

        $this->assertModelExists($collector);
        $this->assertAuthenticatedAs($collector);
    }

    public function test_the_only_super_admin_cannot_set_their_own_account_inactive(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->updateUser($superAdmin, $superAdmin, ['is_active' => false])->assertSessionHasErrors('is_active');

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_a_super_admin_cannot_deactivate_themselves_even_when_another_is_active(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();

        $this->updateUser($superAdmin, $superAdmin, ['is_active' => false])->assertSessionHasErrors(['is_active' => 'لا يمكنك إيقاف حسابك بنفسك.']);

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_a_super_admin_can_deactivate_another_super_admin_while_one_stays_active(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();

        $this->updateUser($superAdmin, $other, ['is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_staff_who_may_edit_users_cannot_touch_a_super_admin_at_all(): void
    {
        $lastActive = User::factory()->superAdmin()->create();
        $manager = User::factory()->accountant()->create();
        $manager->permissions()->sync(Permission::idsFor([PermissionKey::ViewUsers, PermissionKey::UpdateUsers, PermissionKey::DeleteUsers]));

        $this->updateUser($manager, $lastActive, ['is_active' => false])->assertForbidden();
        $this->actingAs($manager)->delete(route('users.destroy', $lastActive))->assertForbidden();

        $this->assertTrue($lastActive->fresh()->is_active);
    }

    public function test_a_member_of_staff_cannot_deactivate_their_own_account(): void
    {
        $branch = Branch::factory()->create();
        $accountant = User::factory()->accountant()->create(['branch_id' => $branch->id]);
        $accountant->permissions()->sync(Permission::idsFor([PermissionKey::ViewUsers, PermissionKey::UpdateUsers]));

        $this->updateUser($accountant, $accountant, ['is_active' => false, 'role' => UserRole::Accountant->value])->assertSessionHasErrors('is_active');

        $this->assertTrue($accountant->fresh()->is_active);
    }

    public function test_a_super_admins_role_cannot_be_changed_through_the_form(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->updateUser($superAdmin, $superAdmin, ['role' => UserRole::Collector->value, 'branch_id' => Branch::factory()->create()->id])->assertSessionHasErrors('role');

        $this->assertSame(UserRole::SuperAdmin, $superAdmin->fresh()->role);
    }

    public function test_the_model_refuses_to_deactivate_demote_or_delete_the_last_active_super_admin(): void
    {
        $lastActive = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(['is_active' => false]);

        foreach ([
            fn () => $lastActive->update(['is_active' => false]),
            fn () => $lastActive->update(['role' => UserRole::BranchAdmin]),
            fn () => $lastActive->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The last active super admin must stay a super admin, active and present.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $lastActive->refresh();
        $this->assertSame([UserRole::SuperAdmin, true], [$lastActive->role, $lastActive->is_active]);
    }

    public function test_another_super_admin_may_still_be_demoted_deactivated_or_removed_while_one_stays_active(): void
    {
        User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();

        $other->update(['is_active' => false]);
        $other->update(['is_active' => true]);
        $other->update(['role' => UserRole::BranchAdmin]);

        $this->assertSame(UserRole::BranchAdmin, $other->fresh()->role);
    }

    /**
     * @return array<string, array{0: Closure(User): mixed}>
     */
    public static function recordsCarryingAUsersName(): array
    {
        return [
            'a closing they prepared' => [fn (User $user) => Closing::factory()->create(['prepared_by' => $user->id])],
            'a closing they reviewed' => [fn (User $user) => Closing::factory()->approved()->create(['reviewed_by' => $user->id])],
            'an event in a closing' => [fn (User $user) => ClosingEvent::create(['closing_id' => Closing::factory()->create()->id, 'user_id' => $user->id, 'action' => 'counted', 'description' => 'x'])],
            'a cash hand-over they sent' => [fn (User $user) => CashTransfer::factory()->create(['sent_by' => $user->id])],
            'a cash hand-over sent to them' => [fn (User $user) => CashTransfer::factory()->create(['recipient_id' => $user->id])],
            'a cash hand-over they received' => [fn (User $user) => CashTransfer::factory()->create(['received_by' => $user->id])],
            'an amendment to a payment' => [fn (User $user) => TransactionAmendment::create(['transaction_id' => SubscriptionTransaction::factory()->create()->id, 'user_id' => $user->id, 'changes' => ['notes' => ['a', 'b']], 'reason' => 'x'])],
            'a permanent deletion' => [fn (User $user) => TransactionDeletion::record($user, 'delete', 'x', [['subscription_id' => Subscription::factory()->create()->id, 'branch_id' => null]])],
            'a bulk change' => [fn (User $user) => SubscriptionBulkChange::create(['field' => 'status', 'value' => 'active', 'description' => 'x', 'user_id' => $user->id])],
            'an undone bulk change' => [fn (User $user) => SubscriptionBulkChange::create(['field' => 'status', 'value' => 'active', 'description' => 'x', 'undone_by' => $user->id])],
            'a price change' => [fn (User $user) => TariffRateChange::factory()->create(['changed_by' => $user->id])],
            'a message batch' => [fn (User $user) => MessageBatch::factory()->create(['created_by' => $user->id])],
        ];
    }

    /**
     * @param  Closure(User): mixed  $record
     */
    #[DataProvider('recordsCarryingAUsersName')]
    public function test_a_user_whose_name_is_on_a_record_is_kept_and_told_to_deactivate_instead(Closure $record): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->accountant()->create();
        $record($user);

        $this->actingAs($superAdmin)->delete(route('users.destroy', $user))
            ->assertSessionHasErrors('delete')
            ->assertSessionDoesntHaveErrors('status');

        $this->assertModelExists($user);
        $this->assertStringContainsString('يمكنك إيقاف حسابه بدلًا من حذفه', session('errors')->first('delete'));
    }

    public function test_a_closing_keeps_the_name_of_whoever_prepared_it_after_they_are_deactivated(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $preparer = User::factory()->accountant()->create(['name' => 'Preparer Name']);
        $closing = Closing::factory()->create(['prepared_by' => $preparer->id]);

        $this->updateUser($superAdmin, $preparer, ['is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($preparer->fresh()->is_active);
        $this->assertSame($preparer->id, $closing->fresh()->prepared_by);
    }

    public function test_a_user_with_no_history_is_still_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->collector()->create();

        $this->actingAs($superAdmin)->delete(route('users.destroy', $user))->assertSessionHasNoErrors();

        $this->assertModelMissing($user);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateUser(User $actor, User $target, array $changes)
    {
        return $this->actingAs($actor)->put(route('users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'branch_id' => $target->branch_id ?? Branch::factory()->create()->id,
            'is_active' => true,
            ...$changes,
        ]);
    }
}
