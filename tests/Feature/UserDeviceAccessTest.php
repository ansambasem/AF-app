<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Support\UserDeviceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserDeviceAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{username: string, password: string} */
    private function credentials(User $user): array
    {
        return ['username' => $user->username, 'password' => 'password'];
    }

    private function anotherBrowser(): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        $id = Str::random(40);
        session()->setId($id);
        session()->replace([]);
        $this->withCookie(config('session.cookie'), $id);
        Auth::forgetGuards();
    }

    public function test_session_checks_refresh_the_device_without_redundant_registry_lookups(): void
    {
        $this->freezeTime();
        $user = User::factory()->superAdmin()->create();
        $this->post(route('login'), $this->credentials($user))->assertRedirect();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())->getJson(route('auth.session'))->assertNoContent();

        $deviceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"user_login_devices"'));
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, $deviceQueries->count());
        $this->assertDatabaseCount('user_login_devices', 1);
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id, 'expires_at' => now()->addMinutes(config('session.lifetime'))->toDateTimeString()]);
    }

    public function test_a_new_mobile_login_replaces_the_old_device_even_for_a_super_admin(): void
    {
        $user = User::factory()->superAdmin()->create();
        $first = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->postJson(route('mobile.login'), [...$this->credentials($user), 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('mobile_access_tokens', ['token_hash' => hash('sha256', $first)]);
        $second = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('mobile_access_tokens', 1);
        $this->withHeader('Authorization', 'Bearer '.$first)->getJson(route('mobile.me'))->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$second)->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->assertJsonPath('token', $second);
        $this->postJson(route('mobile.logout'))->assertOk();
        $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk();
        $this->assertDatabaseCount('mobile_access_tokens', 1);
    }

    public function test_a_new_browser_login_ends_the_old_browser_session(): void
    {
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();
        $user = User::factory()->superAdmin()->create();
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $firstId = session()->getId();
        $this->anotherBrowser();
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $secondId = session()->getId();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('user_login_devices', 1);
        $this->assertDatabaseMissing('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', $firstId)]);
        $this->assertDatabaseMissing('sessions', ['id' => $firstId]);
        Auth::forgetGuards();
        $this->withCredentials()->withCookie(config('session.cookie'), $firstId)->getJson(route('auth.session'))->assertUnauthorized();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $secondId)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($secondId, session()->getId(), 'The current browser session changed after opening the dashboard.');
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', session()->getId())]);
        $response = $this->withCookie(config('session.cookie'), session()->getId())->getJson(route('auth.session'));
        $this->assertSame(204, $response->status(), $response->getContent());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_configured_limit_is_shared_between_the_website_and_mobile_and_logout_releases_a_slot(): void
    {
        $user = User::factory()->superAdmin()->create(['max_devices' => 2]);
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $firstId = session()->getId();
        $this->travel(1)->seconds();
        $token = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->travel(1)->seconds();
        $newToken = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->assertDatabaseMissing('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', $firstId)]);
        $this->assertDatabaseHas('mobile_access_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->assertSame(2, app(UserDeviceAccess::class)->activeCount($user));
        $this->withHeader('Authorization', 'Bearer '.$newToken)->postJson(route('mobile.logout'))->assertOk();
        $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk();
        $this->assertSame(2, app(UserDeviceAccess::class)->activeCount($user));
    }

    public function test_failed_and_throttled_browser_logins_do_not_end_the_existing_device(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $firstId = session()->getId();
        $this->anotherBrowser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), [...$this->credentials($user), 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post(route('login'), $this->credentials($user))->assertSessionHasErrors('username');
        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id, 'session_hash' => hash('sha256', $firstId)]);
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $firstId)->get(route('dashboard'))->assertOk();
    }

    public function test_replacement_removes_only_the_oldest_device_and_does_not_touch_other_users(): void
    {
        $user = User::factory()->create(['max_devices' => 2]);
        $other = User::factory()->create();
        $otherToken = MobileAccessToken::issue($other);
        $first = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->travel(1)->minutes();
        $second = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        MobileAccessToken::query()->where('token_hash', hash('sha256', $first))->update(['last_used_at' => now()]);
        $third = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->withHeader('Authorization', 'Bearer '.$first)->getJson(route('mobile.me'))->assertUnauthorized();
        foreach ([$second, $third, $otherToken] as $token) {
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('mobile.me'))->assertOk();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_replaced_browser_cannot_return_with_remember_me_but_the_new_remember_cookie_still_works(bool $previousExpired): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->superAdmin()->create();
        $cookieName = Auth::guard('web')->getRecallerName();
        $first = $this->post(route('login'), [...$this->credentials($user), 'remember' => true])->assertSessionHasNoErrors();
        $oldCookie = $first->getCookie($cookieName)->getValue();
        if ($previousExpired) {
            $this->travel(121)->minutes();
        }
        $this->anotherBrowser();
        $second = $this->post(route('login'), [...$this->credentials($user), 'remember' => true])->assertSessionHasNoErrors();
        $newCookie = $second->getCookie($cookieName)->getValue();
        $this->travel(121)->minutes();
        $this->anotherBrowser();
        $this->withCredentials()->withCookie($cookieName, $oldCookie)->getJson(route('dashboard'))->assertUnauthorized();
        $this->anotherBrowser();
        $this->withCookie($cookieName, $newCookie)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_web_login_can_replace_a_mobile_device(): void
    {
        $user = User::factory()->superAdmin()->create();
        $token = $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk()->json('token');
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('mobile.me'))->assertUnauthorized();
        $this->assertSame(1, app(UserDeviceAccess::class)->activeCount($user));
    }

    public function test_expired_browser_and_mobile_sessions_do_not_block_a_new_login(): void
    {
        $user = User::factory()->create();
        $token = MobileAccessToken::issue($user);
        MobileAccessToken::query()->where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subMinute()]);
        DB::table('user_login_devices')->insert(['user_id' => $user->id, 'session_hash' => hash('sha256', 'expired-session'), 'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->postJson(route('mobile.login'), $this->credentials($user))->assertOk();
        $this->assertSame(1, app(UserDeviceAccess::class)->activeCount($user));
    }

    public function test_revoking_devices_cannot_be_undone_by_a_stale_browser_session(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $id = session()->getId();
        app(UserDeviceAccess::class)->revokeAll($user);
        Auth::forgetGuards();
        $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson(route('dashboard'))->assertUnauthorized();
        $this->assertDatabaseCount('user_login_devices', 0);
        $this->assertGuest();
    }

    public function test_managers_can_change_the_limit_and_revoke_lost_devices_with_existing_password_management_permissions(): void
    {
        $target = User::factory()->create(['max_devices' => 2]);
        MobileAccessToken::issue($target);
        MobileAccessToken::issue($target);
        $admin = User::factory()->superAdmin()->create();
        $data = ['name' => $target->name, 'username' => $target->username, 'branch_id' => $target->branch_id, 'max_devices' => 1];
        $this->actingAs($admin)->put(route('users.update', $target), $data)->assertSessionHasErrors('max_devices');
        $this->assertSame(2, $target->fresh()->max_devices);
        $this->put(route('users.update', $target), [...$data, 'reset_devices' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $target->fresh()->max_devices);
        $this->assertSame(0, app(UserDeviceAccess::class)->activeCount($target));
        $this->assertDatabaseCount('mobile_access_tokens', 0);
        $this->put(route('users.update', $target), [...$data, 'max_devices' => 0])->assertSessionHasErrors('max_devices');
    }

    public function test_editing_names_does_not_allow_changing_device_limits_without_password_management_permission(): void
    {
        $target = User::factory()->collector()->create();
        $actor = User::factory()->collector()->for($target->branch)->withPermissions([PermissionKey::ViewUsers, PermissionKey::UpdateUsers])->create();
        MobileAccessToken::issue($target);
        $this->actingAs($actor)->put(route('users.update', $target), ['name' => 'Updated Name', 'username' => $target->username, 'max_devices' => 20, 'reset_devices' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $target->fresh()->max_devices);
        $this->assertSame('Updated Name', $target->fresh()->name);
        $this->assertDatabaseCount('mobile_access_tokens', 1);
    }

    public function test_existing_recent_browser_access_is_kept_over_an_idle_legacy_mobile_session(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->post(route('login'), $this->credentials($user))->assertSessionHasNoErrors();
        $id = session()->getId();
        $token = MobileAccessToken::issue($user);
        MobileAccessToken::query()->where('token_hash', hash('sha256', $token))->update(['created_at' => now()->subHours(2), 'last_used_at' => now()->subHour()]);
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $id)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('mobile.me'))->assertUnauthorized();
        $this->assertDatabaseCount('mobile_access_tokens', 0);
    }
}
