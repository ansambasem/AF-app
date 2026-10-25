<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\MobileAccessToken;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_displays_only_the_users_devices_permissions_and_activity(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $this->useDatabaseSessions();
        $user = User::factory()->dataEntry()->create(['name' => 'My Account', 'username' => 'my.account']);
        $other = User::factory()->create();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $user->notify(new ActionCompleted('profile-updated'));
        $other->notify(new ActionCompleted('password-updated'));
        MobileAccessToken::issue($user);
        MobileAccessToken::issue($other);
        MobileAccessToken::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
        foreach ([[$user, 'mine', now()->timestamp], [$user, 'expired-session', now()->subMinutes(121)->timestamp], [$other, 'not-mine', now()->timestamp]] as [$owner, $id, $lastActivity]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'user_agent' => 'Chrome Windows', 'payload' => '', 'last_activity' => $lastActivity]);
        }

        $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page
            ->where('user.username', 'my.account')
            ->where('user.name', 'My Account')
            ->where('user.weeklyActions', 1)
            ->where('user.branchName', $user->branch->name)
            ->missing('user.password')
            ->where('permissionGroups', fn ($groups): bool => collect($groups)->flatMap(fn ($group) => $group['permissions'])->where('granted', true)->pluck('key')->all() === ['subscriptions.view'])
            ->where('devices', fn ($devices): bool => count($devices) === 3 && collect($devices)->where('type', 'mobile')->count() === 1 && collect($devices)->where('current', true)->count() === 1 && collect($devices)->contains('id', hash('sha256', 'mine')))
            ->where('activity.recent.0.action', 'profile-updated'));
    }

    public function test_signing_out_other_devices_requires_the_correct_password_and_keeps_the_current_session(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        MobileAccessToken::issue($user);
        MobileAccessToken::issue($other);
        foreach ([[$user, 'mine'], [$other, 'not-mine']] as [$owner, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $this->actingAs($user)->from('/profile');
        $this->delete('/profile/devices', ['type' => 'all', 'password' => 'incorrect'])
            ->assertRedirect('/profile')->assertSessionHasErrorsIn('deviceLogout', 'password');
        $this->assertDatabaseHas('sessions', ['id' => 'mine']);
        $this->assertDatabaseCount('mobile_access_tokens', 2);
        foreach ([[$user, 'mine'], [$user, session()->getId()], [$other, 'not-mine']] as [$owner, $sessionId]) {
            DB::table('user_login_devices')->insert(['user_id' => $owner->id, 'session_hash' => hash('sha256', $sessionId), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->delete('/profile/devices', ['type' => 'all', 'password' => 'password'])
            ->assertRedirect('/profile')->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-devices-logged-out');
        $this->assertDatabaseMissing('sessions', ['id' => 'mine']);
        $this->assertDatabaseHas('sessions', ['id' => 'not-mine']);
        $this->assertDatabaseMissing('mobile_access_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseHas('mobile_access_tokens', ['user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', 'mine')]);
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', session()->getId())]);
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $other->id, 'session_hash' => hash('sha256', 'not-mine')]);
    }

    public function test_one_mobile_device_can_be_signed_out_without_affecting_other_devices(): void
    {
        $user = User::factory()->create();
        MobileAccessToken::issue($user);
        $selected = MobileAccessToken::sole();
        MobileAccessToken::issue($user);
        $this->actingAs($user)->delete('/profile/devices', ['type' => 'mobile', 'id' => (string) $selected->id, 'password' => 'password'])
            ->assertRedirect('/profile')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('mobile_access_tokens', ['id' => $selected->id]);
        $this->assertDatabaseCount('mobile_access_tokens', 1);
        $this->assertAuthenticatedAs($user);
    }

    public function test_devices_owned_by_another_user_cannot_be_signed_out(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create();
        $other = User::factory()->create();
        MobileAccessToken::issue($other);
        $token = MobileAccessToken::sole();
        DB::table('sessions')->insert(['id' => 'not-mine', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($user);
        $this->delete('/profile/devices', ['type' => 'mobile', 'id' => (string) $token->id, 'password' => 'password'])->assertNotFound();
        $this->delete('/profile/devices', ['type' => 'session', 'id' => hash('sha256', 'not-mine'), 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'not-mine']);
        $this->assertDatabaseHas('mobile_access_tokens', ['id' => $token->id]);
    }

    public function test_device_sign_out_redirects_guests_and_rejects_the_current_device(): void
    {
        $this->delete('/profile/devices', ['type' => 'all', 'password' => 'password'])->assertRedirect('/login');
        $this->useDatabaseSessions();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/profile');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->delete('/profile/devices', ['type' => 'session', 'id' => hash('sha256', session()->getId()), 'password' => 'password'])->assertStatus(422);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_selected_web_session_can_be_signed_out_without_removing_other_sessions(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create();
        foreach (['selected', 'kept'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
            DB::table('user_login_devices')->insert(['user_id' => $user->id, 'session_hash' => hash('sha256', $id), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($user)->delete('/profile/devices', ['type' => 'session', 'id' => hash('sha256', 'selected'), 'password' => 'password'])
            ->assertRedirect('/profile')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'selected']);
        $this->assertDatabaseHas('sessions', ['id' => 'kept']);
        $this->assertDatabaseMissing('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', 'selected')]);
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', 'kept')]);
    }

    private function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
    }
}
