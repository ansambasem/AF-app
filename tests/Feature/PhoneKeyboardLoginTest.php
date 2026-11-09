<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A phone's Arabic keyboard types its digits as ٠١٢٣٤٥٦٧٨٩ (and a Persian
 * one as ۰۱۲۳۴۵۶۷۸۹), and may slip in an invisible direction mark, where a
 * computer types 0-9: the same password or username looks the same but is
 * other characters. Signing in reads them as the computer's, on the app and
 * the website alike, without touching any stored password.
 */
class PhoneKeyboardLoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $password, array $attributes = []): User
    {
        return User::factory()->collector()->create(['username' => $username, 'password' => Hash::make($password), ...$attributes]);
    }

    public function test_the_app_signs_in_with_a_password_typed_in_arabic_digits(): void
    {
        $user = $this->user('collector1', '12345678');
        $stored = $user->password;

        $this->postJson(route('mobile.login'), ['username' => 'collector1', 'password' => '١٢٣٤٥٦٧٨'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token']);
        $this->postJson(route('mobile.login'), ['username' => 'collector1', 'password' => '۱۲۳۴۵۶۷۸'])->assertOk();

        // The stored password is the same one: nothing was rehashed or changed.
        $this->assertSame($stored, $user->fresh()->password);
    }

    public function test_the_app_signs_in_with_a_username_typed_in_arabic_digits_or_with_a_direction_mark(): void
    {
        $user = $this->user('محمد 7', 'secret-pass-1');

        $this->postJson(route('mobile.login'), ['username' => 'محمد ٧', 'password' => 'secret-pass-1'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson(route('mobile.login'), ['username' => "\u{200F}محمد 7", 'password' => "secret-pass-1\u{200E}"])->assertOk();
    }

    public function test_the_website_signs_in_with_a_password_typed_on_a_phone_keyboard(): void
    {
        $user = $this->user('collector2', '12345678');

        $this->post(route('login'), ['username' => 'collector2', 'password' => '١٢٣٤٥٦٧٨'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_set_with_arabic_digits_still_signs_in_as_it_was_set(): void
    {
        // Someone who once set their password from a phone keeps it exactly.
        $this->user('collector3', '٩٨٧٦٥٤٣٢');

        $this->postJson(route('mobile.login'), ['username' => 'collector3', 'password' => '٩٨٧٦٥٤٣٢'])->assertOk();
    }

    public function test_an_admin_signs_in_on_the_app_uses_the_token_signs_out_and_back_in(): void
    {
        $admin = User::factory()->branchAdmin()->create(['username' => 'manager9', 'password' => Hash::make('Admin-2026')]);
        $credentials = ['username' => 'manager9', 'password' => 'Admin-٢٠٢٦'];

        $token = $this->postJson(route('mobile.login'), $credentials)->assertOk()->json('token');
        $this->withToken($token)->getJson(route('mobile.me'))->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->withToken($token)->postJson(route('mobile.logout'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('mobile.me'))->assertUnauthorized();

        $again = $this->withToken('')->postJson(route('mobile.login'), $credentials)->assertOk()->json('token');
        $this->assertNotSame($token, $again);
        $this->withToken($again)->getJson(route('mobile.me'))->assertOk();
    }

    public function test_an_admin_signs_in_on_the_website_signs_out_and_back_in(): void
    {
        $admin = User::factory()->branchAdmin()->create(['username' => 'manager8', 'password' => Hash::make('Admin-2026')]);

        $this->post(route('login'), ['username' => 'manager8', 'password' => 'Admin-٢٠٢٦'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($admin);
        $this->post(route('logout'));
        $this->assertGuest();
        $this->post(route('login'), ['username' => 'manager8', 'password' => 'Admin-2026'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_password_or_an_unknown_user_is_still_refused_in_any_digits(): void
    {
        $this->user('collector4', '12345678');

        $this->postJson(route('mobile.login'), ['username' => 'collector4', 'password' => '١٢٣٤٥٦٧٩'])->assertStatus(422)->assertJsonMissingPath('token');
        $this->postJson(route('mobile.login'), ['username' => 'nobody', 'password' => '١٢٣٤٥٦٧٨'])->assertStatus(422);
        $this->post(route('login'), ['username' => 'collector4', 'password' => '87654321'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_a_stopped_account_stays_refused_in_any_digits(): void
    {
        $this->user('collector5', '12345678', ['is_active' => false]);

        $this->postJson(route('mobile.login'), ['username' => 'collector5', 'password' => '١٢٣٤٥٦٧٨'])->assertStatus(422);
    }
}
