<?php

namespace Tests\Feature\Auth;

use App\Models\MobileAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password-2026',
                'password_confirmation' => 'new-password-2026',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password-2026', $user->refresh()->password));
    }

    public function test_changing_the_password_signs_out_other_devices_but_keeps_this_one(): void
    {
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();
        $user = User::factory()->superAdmin()->create(['remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        MobileAccessToken::issue($user);
        MobileAccessToken::issue($other);
        DB::table('sessions')->insert([
            ['id' => 'my-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'not-mine', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $this->actingAs($user)->get('/profile');
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->from('/profile')->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-2026',
            'password_confirmation' => 'new-password-2026',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertDatabaseHas('sessions', ['id' => 'my-phone']);
        $this->assertDatabaseHas('mobile_access_tokens', ['user_id' => $user->id]);

        $this->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password-2026',
            'password_confirmation' => 'new-password-2026',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertDatabaseMissing('sessions', ['id' => 'my-phone']);
        $this->assertDatabaseMissing('mobile_access_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'not-mine']);
        $this->assertDatabaseHas('mobile_access_tokens', ['user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-2026',
                'password_confirmation' => 'new-password-2026',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }
}
