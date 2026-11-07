<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Requests\UpdatePermissionsRequest;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserType;
use App\Notifications\ActionCompleted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PermissionController extends Controller
{
    use FiltersDataTable;

    private const SORTABLE = ['name', 'username'];

    /**
     * The employees whose permissions the actor may manage, beside the
     * permissions of the selected one (?selected=<id>, or the first one
     * listed). Super Admins already hold every permission implicitly and
     * are never listed.
     *
     * A Branch Admin sees only their own branch's staff (Collector, Data
     * Entry, Accountant) — never another branch's users, another
     * Branch Admin, or a Super Admin — and only the permissions they may
     * grant: never the company-wide ones.
     */
    public function edit(Request $request): InertiaResponse
    {
        $this->authorize('manage', Permission::class);

        $actor = $request->user();
        $grantablePermissionIds = $this->grantablePermissionIds($actor);

        $query = $this->manageableUsers($actor)->with(['branch', 'permissions', 'userType']);
        $this->applyDataTableFilters($query, $request, ['name', 'username'], self::SORTABLE, 'name');
        $this->applyDataTableFilterSelects($query, $request, ['user_type_id', 'branch_id']);

        $page = $query->paginate($this->dataTablePerPage($request))->withQueryString();

        $findManageable = fn (int $id) => $this->manageableUsers($actor)->with(['branch', 'permissions', 'userType'])->find($id);
        $selectedUser = $findManageable($request->integer('selected')) ?? $findManageable($page->first()?->id ?? 0);
        $granters = User::query()
            ->whereIn('id', collect($page->items())->push($selectedUser)->filter()->flatMap(fn (User $user) => $user->permissions->pluck('pivot.granted_by'))->filter()->unique())
            ->pluck('name', 'id')
            ->all();
        $users = $page->through(fn (User $user) => $this->employeeSummary($user, $grantablePermissionIds, $granters));

        return Inertia::render('Settings/Permissions', [
            'users' => $users,
            'selectedUser' => $selectedUser ? [
                ...$this->employeeSummary($selectedUser, $grantablePermissionIds, $granters),
                // How many staff hold each permission because this user handed it on: they lose it with them.
                'grantsGiven' => DB::table('permission_user')->where('granted_by', $selectedUser->id)
                    ->selectRaw('permission_id, count(*) as holders')->groupBy('permission_id')->pluck('holders', 'permission_id'),
            ] : null,
            // Only the Super Admin gives the right to hand permissions on, and only to whoever manages permissions.
            'delegation' => $actor->isSuperAdmin() ? [
                'managePermissionsId' => Permission::query()->where('key', PermissionKey::ManagePermissions->value)->value('id'),
                'delegableIds' => Permission::all()->filter(fn (Permission $permission): bool => PermissionKey::tryFrom($permission->key)?->isDelegable() ?? false)->values()->modelKeys(),
            ] : null,
            'permissionGroups' => $this->permissionGroups($actor),
            'roleTemplates' => [],
            'scopedToOwnBranch' => ! $actor->isSuperAdmin(),
            'auditOptions' => $actor->isSuperAdmin() ? [
                'permissionId' => Permission::query()->where('key', PermissionKey::AuditClosings->value)->value('id'),
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
                'actions' => array_map(fn (AuditAction $action): array => ['value' => $action->value, 'label' => $action->label(), 'hint' => $action->description()], AuditAction::cases()),
            ] : null,
            'filters' => $this->dataTableState($request, 'name'),
            'filterOptions' => $this->filterOptions($actor),
        ]);
    }

    /**
     * Sync each submitted user's custom permission grants, then go back to
     * the page the save came from — same employee, search and filters.
     *
     * Only users actually present in the payload are touched — the list is
     * paginated/searchable, so a save only ever carries the users visible
     * on screen at the time. Looping over every user in the table instead
     * would silently wipe the grants of anyone on another page.
     *
     * The same branch/role scoping as edit() is re-applied here, not just
     * trusted from the page: without it, a Branch Admin could craft a
     * payload naming a user outside their branch (or another Branch Admin)
     * and edit permissions they have no business touching.
     *
     * Likewise only the permissions the actor may grant are changed. A
     * Branch Admin's save ignores company-wide permissions in the payload
     * and keeps any the Super Admin already gave that user.
     */
    public function update(UpdatePermissionsRequest $request): RedirectResponse
    {
        $this->authorize('manage', Permission::class);

        $actor = $request->user();
        $grantablePermissionIds = $this->grantablePermissionIds($actor);
        $payload = $request->validated('permissions');
        $keys = $actor->isSuperAdmin() ? $request->validated('grantable', []) : [];
        $permissionKeys = Permission::query()->pluck('key', 'id');
        $handedOn = [];

        $users = $this->manageableUsers($actor)->with('permissions')->whereIn('id', array_keys($payload))->get();

        DB::transaction(function () use ($users, $payload, $keys, $permissionKeys, $grantablePermissionIds, $actor, $request, &$handedOn): void {
            foreach ($users as $user) {
                $held = $user->permissions->keyBy('id');
                $selected = array_intersect(array_map(intval(...), (array) ($payload[$user->id] ?? [])), $grantablePermissionIds);
                $keptAsTheyWere = array_diff($held->keys()->all(), $grantablePermissionIds);
                $final = array_values(array_unique([...$selected, ...$keptAsTheyWere]));
                $keyed = array_key_exists($user->id, $keys) ? array_map(intval(...), $keys[$user->id]) : null;

                $user->permissions()->sync(collect($final)->mapWithKeys(fn (int $id): array => [$id => [
                    'grantable' => $keyed === null
                        ? (bool) $held->get($id)?->pivot->grantable
                        : in_array($id, $keyed, true) && (PermissionKey::tryFrom($permissionKeys[$id] ?? '')?->isDelegable() ?? false),
                    // Who handed it on stays as it was; a new one is the actor's, unless the Super Admin gave it.
                    'granted_by' => $held->has($id) ? $held->get($id)->pivot->granted_by : ($actor->isSuperAdmin() ? null : $actor->id),
                ]])->all());

                $user->withdrawGrantsOf(array_values(array_diff($held->keys()->all(), $final)));

                $added = array_diff($final, $held->keys()->all());
                if (! $actor->isSuperAdmin() && $added !== []) {
                    $handedOn[$user->name] = array_map(fn (int $id): string => __(PermissionKey::tryFrom($permissionKeys[$id])?->label() ?? $permissionKeys[$id]), $added);
                }

                if ($request->user()->isSuperAdmin() && $request->has("audit_settings.{$user->id}")) {
                    $settings = $request->validated("audit_settings.{$user->id}");
                    $settings['branch_ids'] = $settings['scope'] === 'all' ? [] : array_values(array_unique(array_map(intval(...), $settings['branch_ids'])));
                    $settings['actions'] = array_values(array_unique($settings['actions']));
                    $user->audit_settings = $settings;
                    $user->save();
                }
            }
        });

        $actor->notify(new ActionCompleted('permissions-updated', $users->pluck('name')->join('، ')));

        // What a branch manager hands on works at once; the Super Admins are told who got what.
        foreach ($handedOn as $name => $labels) {
            User::query()->where('role', UserRole::SuperAdmin)->get()
                ->each(fn (User $superAdmin) => $superAdmin->notify(new ActionCompleted('permissions-delegated', "{$actor->name} منح {$name}: ".implode('، ', $labels))));
        }

        return redirect()
            ->back(fallback: route('settings.permissions.edit'))
            ->with('status', 'permissions-updated');
    }

    /**
     * How an employee appears in the list and above the editor, with the
     * permissions they hold that the actor may change — the ones the
     * editor shows, counts, and copies to another employee.
     *
     * @param  array<int, int>  $grantablePermissionIds
     * @param  array<int, string>  $granters  the names of those who handed permissions on, by id
     * @return array{id: int, name: string, username: string, role: string, roleLabel: string, branchName: ?string, permissionIds: array<int, int>, grantableIds: array<int, int>, grantedBy: array<int, string>, lockedPermissions: array<int, string>}
     */
    private function employeeSummary(User $user, array $grantablePermissionIds, array $granters = []): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'roleLabel' => $user->userType?->name ?? 'غير محدد',
            'userTypeName' => $user->userType?->name,
            'branchName' => $user->branch?->name,
            'branchId' => $user->branch_id,
            'auditSettings' => $user->auditSettings(),
            'auditSettingsConfigured' => $user->audit_settings !== null,
            'isActive' => $user->is_active,
            'permissionIds' => array_values(array_intersect($user->permissions->modelKeys(), $grantablePermissionIds)),
            'grantableIds' => $user->permissions->filter(fn (Permission $permission): bool => (bool) $permission->pivot->grantable)->values()->modelKeys(),
            'grantedBy' => $user->permissions
                ->filter(fn (Permission $permission): bool => isset($granters[$permission->pivot->granted_by]))
                ->mapWithKeys(fn (Permission $permission): array => [$permission->id => $granters[$permission->pivot->granted_by]])
                ->all(),
            'lockedPermissions' => $user->permissions
                ->reject(fn (Permission $permission): bool => PermissionKey::tryFrom($permission->key)?->isRetired() ?? false)
                ->reject(fn (Permission $permission): bool => in_array($permission->id, $grantablePermissionIds, true))
                ->map(fn (Permission $permission): string => __(PermissionKey::tryFrom($permission->key)?->label() ?? $permission->label))
                ->values()->all(),
        ];
    }

    /**
     * The users whose permissions the actor may manage: every non-Super-
     * Admin for a Super Admin; for anyone else, only the staff roles in
     * their own branch.
     *
     * @return Builder<User>
     */
    private function manageableUsers(User $actor): Builder
    {
        return User::query()
            ->where('role', '!=', UserRole::SuperAdmin)
            ->when(! $actor->isSuperAdmin(), fn (Builder $query) => $query
                ->where('branch_id', $actor->workBranchId())
                ->whereDoesntHave('permissions', fn (Builder $permissions) => $permissions->whereIn('key', array_map(
                    fn (PermissionKey $key): string => $key->value,
                    [PermissionKey::ManagePermissions, PermissionKey::AuditClosings, PermissionKey::ManageBranchDomains],
                ))));
    }

    /**
     * Whether the actor may hand out this permission: a Super Admin may
     * grant anything; a branch manager only the ones the Super Admin gave
     * them the right to hand on (see User::canGrantPermission()).
     */
    private function canGrant(User $actor, PermissionKey $permissionKey): bool
    {
        return $actor->canGrantPermission($permissionKey);
    }

    /**
     * The ids of every permission the actor may grant or revoke.
     *
     * @return array<int, int>
     */
    private function grantablePermissionIds(User $actor): array
    {
        return Permission::all()
            ->filter(function (Permission $permission) use ($actor) {
                $permissionKey = PermissionKey::tryFrom($permission->key);

                return $permissionKey !== null && ! $permissionKey->isRetired() && $this->canGrant($actor, $permissionKey);
            })
            ->modelKeys();
    }

    /**
     * The permissions the actor may grant, grouped by resource, in the shape
     * the Permissions matrix renders: a resource label plus its ordered
     * action columns (view/create/update, or view/record/confirm for
     * Collections). A missing action (e.g. Collections has no "create")
     * comes back as null so the page can render an empty cell instead of a
     * checkbox. A resource with nothing the actor may grant is left out.
     *
     * @return array<int, array{key: string, label: string, actions: array<int, array{action: string, permission: array{id: int, label: string}|null}>}>
     */
    private function permissionGroups(User $actor): array
    {
        $permissionsByKey = Permission::all()->keyBy('key');

        return collect(PermissionKey::resourceGroups())
            ->map(fn (array $group) => [
                ...$group,
                'actions' => array_filter($group['actions'], fn (PermissionKey $permissionKey) => $this->canGrant($actor, $permissionKey)),
            ])
            ->filter(fn (array $group) => $group['actions'] !== [])
            ->map(function (array $group, string $resourceKey) use ($permissionsByKey) {
                return [
                    'key' => $resourceKey,
                    'label' => __($group['label']),
                    'actions' => collect($group['actions'])
                        ->map(function (PermissionKey $permissionKey, string $action) use ($permissionsByKey) {
                            $permission = $permissionsByKey->get($permissionKey->value);

                            return [
                                'action' => $action,
                                'permission' => $permission ? ['id' => $permission->id, 'key' => $permissionKey->value, 'label' => __($permissionKey->label()), 'centralOnly' => $permissionKey->isCompanyWide()] : null,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The Filter menu's dropdown groups for the index page. The branch
     * filter only makes sense for a Super Admin — a Branch Admin's list is
     * already scoped to their own single branch.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = [
            $this->filterGroup('user_type_id', 'نوع المستخدم', UserType::query()->orderBy('name')->get()->map(fn (UserType $type): array => ['value' => (string) $type->id, 'label' => $type->name])->all()),
        ];

        if ($actor->isSuperAdmin()) {
            $groups[] = $this->branchFilterGroup();
        }

        return $groups;
    }
}
