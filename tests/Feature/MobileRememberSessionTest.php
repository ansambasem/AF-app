<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use App\Support\UserDeviceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MobileRememberSessionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([false, 12])]
    #[TestWith([true, 720])]
    public function test_remember_me_controls_the_token_lifetime_and_expiration(bool $remember, int $hours): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $plain = $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password', 'remember' => $remember])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('token');
        $this->assertTrue(MobileAccessToken::sole()->expires_at->equalTo(now()->addHours($hours)));
        $this->assertNotSame($plain, MobileAccessToken::sole()->token_hash);
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson(route('mobile.me'))->assertOk();
        $this->travel($hours)->hours();
        $this->getJson(route('mobile.me'))->assertUnauthorized();
    }

    public function test_remember_can_be_changed_on_the_same_device_without_consuming_another_slot(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $credentials = ['username' => $user->username, 'password' => 'password'];
        $plain = $this->postJson(route('mobile.login'), $credentials)->assertOk()->json('token');
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson(route('mobile.login'), [...$credentials, 'remember' => true])
            ->assertOk()->assertJsonPath('token', $plain);
        $this->assertTrue(MobileAccessToken::sole()->expires_at->equalTo(now()->addDays(30)));
        $this->postJson(route('mobile.login'), [...$credentials, 'remember' => false])->assertOk()->assertJsonPath('token', $plain);
        $this->assertDatabaseCount('mobile_access_tokens', 1);
        $this->assertTrue(MobileAccessToken::sole()->expires_at->equalTo(now()->addHours(12)));
        $this->postJson(route('mobile.login'), [...$credentials, 'remember' => 'unlimited'])->assertUnprocessable()->assertJsonValidationErrors('remember');
        $this->assertTrue(MobileAccessToken::sole()->expires_at->equalTo(now()->addHours(12)));
    }

    public function test_an_administrative_password_reset_revokes_existing_browser_and_mobile_access(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        MobileAccessToken::issue($user);
        MobileAccessToken::issue($other);
        DB::table('user_login_devices')->insert(['user_id' => $user->id, 'session_hash' => hash('sha256', 'stolen'), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->superAdmin()->create())->put(route('users.update', $user), [
            'name' => $user->name, 'username' => $user->username, 'branch_id' => $user->branch_id,
            'password' => 'New-password-2026', 'password_confirmation' => 'New-password-2026',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, app(UserDeviceAccess::class)->activeCount($user));
        $this->assertSame(1, app(UserDeviceAccess::class)->activeCount($other));
    }

    public function test_sensitive_mobile_errors_cannot_be_cached(): void
    {
        $this->getJson(route('mobile.me'))->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }
}
