<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['محمد حمدان'])]
    #[TestWith(['مُحَمَّد حمدان'])]
    #[TestWith(['mohammad.hamdan_1-2'])]
    #[TestWith(['محمد hamdan ١٢'])]
    public function test_creates_users_with_arabic_letters_spaces_and_existing_username_characters(string $username): void
    {
        $admin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'موظف جديد',
            'username' => $username,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $branch->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['username' => $username, 'branch_id' => $branch->id]);
    }

    public function test_updates_and_resaves_an_arabic_username_with_spaces(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $payload = ['name' => $user->name, 'username' => 'محمد حمدان', 'branch_id' => $user->branch_id];

        $this->actingAs($admin)->put(route('users.update', $user), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => 'محمد حمدان']);

        $this->put(route('users.update', $user), $payload)->assertSessionHasNoErrors();
    }

    public function test_cannot_create_a_duplicate_arabic_username_with_spaces(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $existing = User::factory()->create(['username' => 'محمد حمدان']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'موظف آخر',
            'username' => 'محمد حمدان',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $existing->branch_id,
        ])->assertSessionHasErrors(['username' => trans('validation.unique', ['attribute' => 'اسم المستخدم'])]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_cannot_update_to_another_users_arabic_username_with_spaces(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->create(['username' => 'محمد حمدان']);
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name, 'username' => 'محمد حمدان', 'branch_id' => $user->branch_id,
        ])->assertSessionHasErrors(['username' => trans('validation.unique', ['attribute' => 'اسم المستخدم'])]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username]);
    }

    #[TestWith(['محمد@حمدان'])]
    #[TestWith(["محمد\nحمدان"])]
    public function test_rejects_unsupported_characters_when_creating_and_updating(string $username): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $payload = ['name' => $user->name, 'username' => $username, 'branch_id' => $user->branch_id];
        $error = ['username' => trans('validation.regex', ['attribute' => 'اسم المستخدم'])];

        $this->actingAs($admin)->post(route('users.store'), [
            ...$payload, 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasErrors($error);

        $this->put(route('users.update', $user), $payload)->assertSessionHasErrors($error);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username]);
    }

    public function test_arabic_username_with_spaces_can_sign_in_on_the_website(): void
    {
        $user = User::factory()->create(['username' => 'محمد حمدان']);

        $this->post(route('login'), ['username' => 'محمد حمدان', 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_arabic_username_with_spaces_can_sign_in_on_mobile(): void
    {
        $user = User::factory()->create(['username' => 'محمد حمدان']);

        $this->postJson(route('mobile.login'), ['username' => 'محمد حمدان', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.username', 'محمد حمدان');
    }
}
