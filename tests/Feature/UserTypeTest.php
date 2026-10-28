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

    public function test_user_type_chips_count_all_visible_accounts_independently_of_the_users_search(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $unused = UserType::factory()->create(['name' => 'أمين']);
        $used = UserType::factory()->create(['name' => 'محصل']);
        User::factory()->count(2)->for($used, 'userType')->create();

        $this->actingAs($actor)->get(route('users.index', ['search' => 'لا توجد نتيجة بهذا الاسم']))->assertInertia(fn ($page) => $page
            ->where('users.total', 0)->has('userTypeItems', 2)
            ->where('userTypeItems.0.id', $unused->id)->where('userTypeItems.0.count', 0)
            ->where('userTypeItems.0.canUpdate', true)->where('userTypeItems.0.canDelete', true)
            ->where('userTypeItems.1.id', $used->id)->where('userTypeItems.1.count', 2)
            ->where('userTypeItems.1.canUpdate', true)->where('userTypeItems.1.canDelete', false));
    }

    public function test_user_type_chips_keep_other_branches_account_counts_private_and_protect_used_types(): void
    {
        $actor = User::factory()->collector()->withPermissions([PermissionKey::ViewUsers, PermissionKey::ViewUserTypes, PermissionKey::UpdateUserTypes, PermissionKey::DeleteUserTypes])->create();
        $local = UserType::factory()->create(['name' => 'أمين']);
        $foreign = UserType::factory()->create(['name' => 'محصل']);
        User::factory()->for($local, 'userType')->create(['branch_id' => $actor->branch_id]);
        User::factory()->for($foreign, 'userType')->create();

        $this->actingAs($actor)->get(route('users.index'))->assertInertia(fn ($page) => $page
            ->where('userTypeItems.0.id', $local->id)->where('userTypeItems.0.count', 1)
            ->where('userTypeItems.1.id', $foreign->id)->where('userTypeItems.1.count', 0)
            ->where('userTypeItems.1.canUpdate', true)->where('userTypeItems.1.canDelete', false));
    }

    public function test_read_only_type_access_has_no_chip_management_actions(): void
    {
        $actor = User::factory()->collector()->withPermissions([PermissionKey::ViewUsers, PermissionKey::ViewUserTypes])->create();
        UserType::factory()->create();

        $this->actingAs($actor)->get(route('users.index'))->assertInertia(fn ($page) => $page
            ->where('canCreateUserType', false)->has('userTypeItems', 1)
            ->where('userTypeItems.0.canUpdate', false)->where('userTypeItems.0.canDelete', false));
    }

    public function test_a_guest_cannot_open_type_management(): void
    {
        $this->get(route('user-types.index'))->assertRedirect(route('login'));
    }

    public function test_an_administrator_adds_a_type_and_returns_to_the_normal_users_page(): void
    {
        $actor = User::factory()->superAdmin()->create();
        UserType::factory()->create(['name' => 'محصل']);

        $this->actingAs($actor)->post(route('user-types.store'), ['name' => 'فني صيانة'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'user-type-created')
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('user_types', ['name' => 'فني صيانة']);
        $this->assertSame(['action' => 'user-type-created', 'subject' => 'فني صيانة'], $actor->notifications()->sole()->data);
        $this->get(route('users.index', ['tab' => 'types']))
            ->assertInertia(fn ($page) => $page->component('Users/Index')
                ->where('canCreateUserType', true)
                ->missing('tab')->missing('userTypes')
                ->has('userTypeOptions', 2)
                ->where('userTypeOptions.0.label', 'فني صيانة')
                ->where('userTypeOptions.1.label', 'محصل'));
    }

    public function test_the_old_types_page_opens_the_users_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('user-types.index'))
            ->assertRedirect(route('users.index'));
    }

    public function test_inline_type_creation_returns_a_selected_option_and_keeps_user_creation_permissions_separate(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $response = $this->actingAs($actor)->postJson(route('user-types.store'), ['name' => 'فني صيانة'])
            ->assertCreated()->assertJsonStructure(['id', 'name'])->assertJsonPath('name', 'فني صيانة');
        $this->assertDatabaseHas('user_types', ['id' => $response->json('id'), 'name' => 'فني صيانة']);
        $this->assertSame(['action' => 'user-type-created', 'subject' => 'فني صيانة'], $actor->notifications()->sole()->data);
        $this->get(route('users.create'))->assertInertia(fn ($page) => $page
            ->where('canCreateUserType', true)->where('userTypeOptions.0.value', $response->json('id')));
        $this->postJson(route('user-types.store'), ['name' => 'فني صيانة'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('user-types.store'), ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('user_types', 1);
    }

    public function test_branch_user_forms_offer_existing_types_but_keep_shared_type_creation_on_the_central_domain(): void
    {
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        Branch::factory()->create(['domain' => 'branch.company.test', 'domain_enabled' => true]);
        $type = UserType::factory()->create();
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->get('https://branch.company.test/users/create')->assertInertia(fn ($page) => $page
            ->where('canCreateUserType', false)->where('userTypeOptions.0.value', $type->id));
        $this->get('https://branch.company.test/users')->assertInertia(fn ($page) => $page
            ->where('userTypeItems.0.canUpdate', false)->where('userTypeItems.0.canDelete', false));
        $this->postJson('https://branch.company.test/user-types', ['name' => 'مسمى مشترك'])->assertForbidden();
        $this->assertDatabaseMissing('user_types', ['name' => 'مسمى مشترك']);
        $this->get('https://admin.company.test/users/create')->assertInertia(fn ($page) => $page
            ->where('canCreateUserType', true)->where('userTypeOptions.0.value', $type->id));
    }

    public function test_the_users_page_has_no_types_tab_without_the_permission(): void
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::ViewUsers]));
        UserType::factory()->create();

        $this->actingAs($actor)->get(route('users.index', ['tab' => 'types']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('tab')->missing('userTypes')->where('canCreateUserType', false)->where('userTypeItems', null));
        $this->postJson(route('user-types.store'), ['name' => 'غير مسموح'])->assertForbidden();
        $this->assertDatabaseMissing('user_types', ['name' => 'غير مسموح']);
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
            ->assertRedirect(route('users.index'));

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
