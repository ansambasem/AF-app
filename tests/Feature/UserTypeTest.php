<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserType;
use Database\Seeders\UserTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_open_type_management(): void
    {
        $this->get(route('user-types.index'))->assertRedirect(route('login'));
    }

    public function test_an_administrator_adds_a_type_and_returns_to_the_types_tab_on_the_users_page(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $collectorType = UserType::factory()->create(['name' => 'محصل']);
        User::factory()->for($collectorType, 'userType')->count(2)->create();

        $this->actingAs($actor)->post(route('user-types.store'), ['name' => 'فني صيانة'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'user-type-created')
            ->assertRedirect(route('users.index', ['tab' => 'types']));

        $this->assertDatabaseHas('user_types', ['name' => 'فني صيانة']);
        $this->assertSame(['action' => 'user-type-created', 'subject' => 'فني صيانة'], $actor->notifications()->sole()->data);
        $this->get(route('users.index', ['tab' => 'types']))
            ->assertInertia(fn ($page) => $page->component('Users/Index')
                ->where('tab', 'types')
                ->where('canCreateUserType', true)
                ->has('userTypes.data', 2)
                ->where('userTypes.data.0.name', 'فني صيانة')
                ->where('userTypes.data.0.usersCount', 0)
                ->where('userTypes.data.1.name', 'محصل')
                ->where('userTypes.data.1.usersCount', 2));
    }

    public function test_the_old_types_page_opens_the_types_tab(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('user-types.index'))
            ->assertRedirect(route('users.index', ['tab' => 'types']));
    }

    public function test_types_use_server_search_sorting_pagination_and_the_shared_export_page_size(): void
    {
        $actor = User::factory()->superAdmin()->create();
        for ($number = 1; $number <= 16; $number++) {
            $type = UserType::factory()->create(['name' => sprintf('نوع %02d', $number)]);
        }
        User::factory()->for($type, 'userType')->count(2)->create();

        $this->actingAs($actor)->get(route('users.index', ['tab' => 'types', 'sort' => 'name', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page->where('users', null)->where('userTypes.total', 16)
                ->has('userTypes.data', 15)->where('userTypes.data.0.name', 'نوع 16')->where('userTypes.last_page', 2));
        $this->get(route('users.index', ['tab' => 'types', 'sort' => 'name', 'direction' => 'desc', 'page' => 2]))
            ->assertInertia(fn ($page) => $page->has('userTypes.data', 1)->where('userTypes.data.0.name', 'نوع 01'));
        $this->get(route('users.index', ['tab' => 'types', 'search' => 'نوع 02']))
            ->assertInertia(fn ($page) => $page->where('userTypes.total', 1)->where('userTypes.data.0.name', 'نوع 02'));
        $this->get(route('users.index', ['tab' => 'types', 'sort' => 'users_count', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page->where('userTypes.data.0.id', $type->id)->where('userTypes.data.0.usersCount', 2));
        $this->get(route('users.index', ['tab' => 'types', 'print_all' => 1]))
            ->assertInertia(fn ($page) => $page->has('userTypes.data', 16)->where('userTypes.last_page', 1));
    }

    public function test_the_users_page_has_no_types_tab_without_the_permission(): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewUsers]));
        UserType::factory()->create();

        $this->actingAs($actor)->get(route('users.index', ['tab' => 'types']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'users')->where('userTypes', null)->where('canCreateUserType', false));
    }

    #[TestWith(['', 'حقل اسم نوع المستخدم مطلوب.'])]
    #[TestWith([null, 'حقل اسم نوع المستخدم مطلوب.'])]
    public function test_a_type_requires_a_name(?string $name, string $message): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->post(route('user-types.store'), ['name' => $name])
            ->assertSessionHasErrors(['name' => $message]);

        $this->assertDatabaseCount('user_types', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_type_names_cannot_be_duplicated_or_exceed_the_limit(): void
    {
        $actor = User::factory()->superAdmin()->create();
        UserType::factory()->create(['name' => 'كهربائي']);

        $this->actingAs($actor)->post(route('user-types.store'), ['name' => 'كهربائي'])
            ->assertSessionHasErrors(['name' => 'قيمة حقل اسم نوع المستخدم مُستخدمة من قبل.']);
        $this->post(route('user-types.store'), ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['name' => 'يجب ألا يكون طول حقل اسم نوع المستخدم أكبر من 255 حرفًا.']);

        $this->assertDatabaseCount('user_types', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_type_can_be_renamed_without_losing_its_user_assignments(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);
        $employee = User::factory()->for($type, 'userType')->create();

        $this->actingAs($actor)->put(route('user-types.update', $type), ['name' => 'فني كهرباء'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'user-type-updated')
            ->assertRedirect(route('users.index', ['tab' => 'types']));

        $this->assertDatabaseHas('user_types', ['id' => $type->id, 'name' => 'فني كهرباء']);
        $this->assertSame('فني كهرباء', $employee->fresh()->userType->name);
        $this->put(route('user-types.update', $type), ['name' => 'فني كهرباء'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('user_types', 1);
    }

    public function test_renaming_a_type_to_an_existing_name_is_rejected(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);
        UserType::factory()->create(['name' => 'محصل']);

        $this->actingAs($actor)->put(route('user-types.update', $type), ['name' => 'محصل'])
            ->assertSessionHasErrors(['name' => 'قيمة حقل اسم نوع المستخدم مُستخدمة من قبل.']);

        $this->assertDatabaseHas('user_types', ['id' => $type->id, 'name' => 'كهربائي']);
    }

    public function test_unused_types_can_be_deleted(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $type = UserType::factory()->create();

        $this->actingAs($actor)->from(route('users.index', ['tab' => 'types']))->delete(route('user-types.destroy', $type))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'user-type-deleted')
            ->assertRedirect(route('users.index', ['tab' => 'types']));

        $this->assertModelMissing($type);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_type_assigned_to_users_is_kept_when_deletion_is_requested(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $type = UserType::factory()->create();
        $employee = User::factory()->for($type, 'userType')->create();

        $this->actingAs($actor)->from(route('users.index', ['tab' => 'types']))->delete(route('user-types.destroy', $type))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف نوع المستخدم لوجود سجلات مرتبطة به — المستخدمون: 1.']);

        $this->assertModelExists($type);
        $this->assertSame($type->id, $employee->fresh()->user_type_id);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_type_management_requires_the_corresponding_permission(): void
    {
        $actor = User::factory()->collector()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);

        $this->actingAs($actor)->get(route('user-types.index'))->assertForbidden();
        $this->post(route('user-types.store'), ['name' => 'فني'])->assertForbidden();
        $this->put(route('user-types.update', $type), ['name' => 'فني'])->assertForbidden();
        $this->delete(route('user-types.destroy', $type))->assertForbidden();

        $this->assertDatabaseHas('user_types', ['id' => $type->id, 'name' => 'كهربائي']);
        $this->assertDatabaseCount('user_types', 1);
        $this->assertDatabaseCount('notifications', 0);

        $actor->permissions()->attach(Permission::idsFor([PermissionKey::CreateUserTypes]));
        $this->actingAs($actor->fresh())->post(route('user-types.store'), ['name' => 'فني'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('user_types', ['name' => 'فني']);
    }

    public function test_new_user_forms_offer_custom_types_on_the_user_page_and_dashboard(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);
        $options = [['value' => $type->id, 'label' => 'كهربائي']];

        $this->actingAs($actor)->get(route('users.create'))
            ->assertInertia(fn ($page) => $page->where('userTypeOptions', $options));
        $this->get(route('users.index'))
            ->assertInertia(fn ($page) => $page->where('userTypeOptions', $options));
        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->missing('userForm')
                ->reloadOnly('userForm', fn ($reload) => $reload->where('userForm.userTypeOptions', $options)));
    }

    public function test_a_user_can_be_created_with_a_job_title_and_no_automatic_permissions(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);

        $this->actingAs($actor)->post(route('users.store'), [...$this->userPayload(), 'user_type_id' => $type->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $employee = User::where('username', 'new.employee')->sole();
        $this->assertSame($type->id, $employee->user_type_id);
        $this->assertSame('collector', $employee->role->value);
        $this->assertSame(0, $employee->permissions()->count());
        $this->assertSame($actor->branch_id, $employee->branch_id);
        $this->get(route('users.index', ['search' => 'new.employee']))->assertInertia(fn ($page) => $page
            ->where('users.data.0.userTypeName', 'كهربائي')->where('users.data.0.user_type_id', $type->id));
    }

    public function test_changing_or_clearing_a_user_type_preserves_custom_permissions(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $firstType = UserType::factory()->create(['name' => 'كهربائي']);
        $secondType = UserType::factory()->create(['name' => 'فني']);
        $employee = User::factory()->dataEntry()->for($firstType, 'userType')->create(['branch_id' => $actor->branch_id]);
        $employee->permissions()->attach(Permission::idsFor([PermissionKey::AddCharges]));
        $permissions = $employee->permissions()->orderBy('id')->pluck('id')->all();

        $this->actingAs($actor)->put(route('users.update', $employee), [...$this->userPayload(), 'user_type_id' => $secondType->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($secondType->id, $employee->fresh()->user_type_id);
        $this->assertSame($permissions, $employee->permissions()->orderBy('id')->pluck('id')->all());
        $this->get(route('users.edit', $employee))->assertInertia(fn ($page) => $page->where('user.user_type_id', $secondType->id));

        $this->put(route('users.update', $employee), [...$this->userPayload(), 'user_type_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->user_type_id);
        $this->assertSame($permissions, $employee->permissions()->orderBy('id')->pluck('id')->all());
    }

    public function test_invalid_type_ids_are_rejected_when_creating_and_editing_users(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $employee = User::factory()->dataEntry()->create(['branch_id' => $actor->branch_id]);
        $payload = [...$this->userPayload(), 'user_type_id' => 999999];

        $this->actingAs($actor)->post(route('users.store'), $payload)
            ->assertSessionHasErrors(['user_type_id' => 'القيمة المحددة لحقل نوع المستخدم غير صالحة.']);
        $this->put(route('users.update', $employee), $payload)
            ->assertSessionHasErrors(['user_type_id' => 'القيمة المحددة لحقل نوع المستخدم غير صالحة.']);

        $this->assertDatabaseCount('users', 2);
        $this->assertNull($employee->fresh()->user_type_id);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_new_users_start_without_permissions_until_they_are_assigned(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $type = UserType::factory()->create(['name' => 'كهربائي']);
        $payload = $this->userPayload();
        unset($payload['role']);

        $this->actingAs($actor)->post(route('users.store'), [
            ...$payload,
            'branch_id' => $branch->id,
            'user_type_id' => $type->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $employee = User::where('username', 'new.employee')->sole();
        $this->assertSame('collector', $employee->role->value);
        $this->assertSame($type->id, $employee->user_type_id);
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame([], $employee->permissions()->pluck('key')->all());
    }

    #[TestWith(['branch_admin'])]
    #[TestWith(['data_entry'])]
    public function test_editing_without_a_permission_role_preserves_existing_role_and_custom_permissions(string $role): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = User::factory()->create(['role' => $role]);
        $employee->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $type = UserType::factory()->create(['name' => 'فني']);
        $payload = $this->userPayload();
        unset($payload['role']);

        $this->actingAs($actor)->put(route('users.update', $employee), [
            ...$payload,
            'branch_id' => $employee->branch_id,
            'user_type_id' => $type->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertSame($role, $employee->fresh()->role->value);
        $this->assertSame($type->id, $employee->fresh()->user_type_id);
        $this->assertSame(['subscriptions.view'], $employee->permissions()->pluck('key')->all());
    }

    public function test_the_default_types_can_be_seeded_without_duplicates(): void
    {
        $this->seed(UserTypeSeeder::class);
        $this->seed(UserTypeSeeder::class);

        $this->assertDatabaseHas('user_types', ['name' => 'كهربائي']);
        $this->assertDatabaseHas('user_types', ['name' => 'محصل']);
        $this->assertDatabaseCount('user_types', 2);
    }

    /** @return array<string, mixed> */
    private function userPayload(): array
    {
        return [
            'name' => 'New Employee',
            'username' => 'new.employee',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
