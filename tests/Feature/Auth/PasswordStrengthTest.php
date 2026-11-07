<?php

namespace Tests\Feature\Auth;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordStrengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function shortPasswords(): array
    {
        return [
            'seven digits' => ['1234567'],
            'seven letters' => ['abcdefg'],
            'letters and digits but short' => ['abc1234'],
        ];
    }

    #[DataProvider('shortPasswords')]
    public function test_a_short_password_is_refused_when_creating_a_user(string $password): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('users.store'), $this->newUser(['password' => $password, 'password_confirmation' => $password]))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['username' => 'new.person']);
    }

    #[DataProvider('shortPasswords')]
    public function test_a_short_password_is_refused_when_changing_a_users_password(string $password): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->collector()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($superAdmin)->put(route('users.update', $user), [
            'name' => $user->name, 'username' => $user->username, 'branch_id' => $user->branch_id, 'is_active' => true,
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors('password');
    }

    #[DataProvider('shortPasswords')]
    public function test_a_short_password_is_refused_when_changing_your_own(string $password): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password', 'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    /** @return array<string, array{0: string}> */
    public static function simplePasswords(): array
    {
        return [
            'eight digits' => ['12345678'],
            'eight letters' => ['abcdefgh'],
            'eight mixed characters' => ['abcd1234'],
        ];
    }

    #[DataProvider('simplePasswords')]
    public function test_an_eight_character_password_is_accepted_when_creating_a_user(string $password): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('users.store'), $this->newUser(['password' => $password, 'password_confirmation' => $password]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(password_verify($password, User::where('username', 'new.person')->sole()->password));
    }

    #[DataProvider('simplePasswords')]
    public function test_an_eight_character_password_is_accepted_when_changing_an_employee_password(string $password): void
    {
        $manager = User::factory()->collector()->create();
        $manager->permissions()->sync(Permission::idsFor([PermissionKey::UpdateUsers, PermissionKey::ManageUserPasswords]));
        $employee = User::factory()->collector()->for($manager->branch)->create();

        $this->actingAs($manager)->put(route('users.update', $employee), [
            'name' => $employee->name, 'username' => $employee->username,
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(password_verify($password, $employee->fresh()->password));
    }

    #[DataProvider('simplePasswords')]
    public function test_an_eight_character_password_is_accepted_when_changing_your_own(string $password): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->from('/profile')->put('/password', ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasNoErrors();

        $this->assertTrue(password_verify($password, $user->fresh()->password));
    }

    public function test_production_also_accepts_simple_passwords_without_an_external_check(): void
    {
        $this->app['env'] = 'production';
        Http::fake();

        $this->assertTrue(Validator::make(['password' => '12345678'], ['password' => Password::default()])->passes());
        $this->assertTrue(Validator::make(['password' => 'abcdefgh'], ['password' => Password::default()])->passes());
        Http::assertNothingSent();
    }

    public function test_outside_production_no_outside_service_is_asked(): void
    {
        Http::fake();

        Validator::make(['password' => 'Summer2026ab'], ['password' => Password::default()])->passes();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newUser(array $overrides = []): array
    {
        return [
            'name' => 'New Person',
            'username' => 'new.person',
            'branch_id' => Branch::factory()->create()->id,
            ...$overrides,
        ];
    }
}
