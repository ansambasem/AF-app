<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Concerns\DeletesRecords;
use App\Http\Concerns\FiltersDataTable;
use App\Http\Concerns\ProvidesFormOptions;
use App\Http\Concerns\ReturnsToDashboard;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserType;
use App\Notifications\ActionCompleted;
use App\Support\UserDeviceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserController extends Controller
{
    use DeletesRecords, FiltersDataTable, ProvidesFormOptions, ReturnsToDashboard;

    private const SORTABLE = ['name', 'username', 'role', 'is_active', 'created_at'];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', User::class);

        $actor = auth()->user();

        $query = User::query()->visibleTo($actor)->with(['branch', 'userType']);
        $this->applyDataTableFilters($query, $request, ['name', 'username'], self::SORTABLE, 'name');
        $this->applyDataTableFilterSelects($query, $request, ['user_type_id', 'is_active', 'branch_id']);

        $users = $query->paginate($this->dataTablePerPage($request))
            ->withQueryString()
            ->through(fn (User $user) => [
                ...$this->editableFields($user),
                'roleLabel' => $user->isSuperAdmin() ? __($user->role->label()) : ($user->userType?->name ?? 'موظف'),
                'branchName' => $user->branch?->name,
                'userTypeName' => $user->userType?->name,
                'canUpdate' => $actor->can('update', $user),
                'canDelete' => $actor->can('delete', $user),
            ]);

        $canViewUserTypes = $actor->can('viewAny', UserType::class);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'canCreate' => $actor->can('create', User::class),
            'filters' => $this->dataTableState($request, 'name'),
            'filterOptions' => $this->filterOptions($actor),
            'tab' => $canViewUserTypes && $request->query('tab') === 'types' ? 'types' : 'users',
            'userTypes' => $canViewUserTypes ? $this->userTypeRows($actor) : null,
            'canCreateUserType' => $actor->can('create', UserType::class),
            ...$this->userBranchOptions(),
        ]);
    }

    /**
     * The user types for the Users page's types tab, with how many users
     * each one is given to.
     *
     * @return array<int, array{id: int, name: string, usersCount: int, canUpdate: bool, canDelete: bool}>
     */
    private function userTypeRows(User $actor): array
    {
        return UserType::query()->withCount('users')->orderBy('name')->get()
            ->map(fn (UserType $userType): array => [
                'id' => $userType->id,
                'name' => $userType->name,
                'usersCount' => $userType->users_count,
                'canUpdate' => $actor->can('update', $userType),
                'canDelete' => $actor->can('delete', $userType),
            ])
            ->all();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Create', [
            ...$this->userBranchOptions(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $actor = auth()->user();
        $data = $request->validated();
        $companyWide = $actor->isSuperAdmin() && $request->boolean('company_wide');
        unset($data['company_wide']);
        // A Super Admin works over every branch, with no branch of their own.
        $data['role'] = $companyWide ? UserRole::SuperAdmin : UserRole::Collector;
        $data['branch_id'] = $companyWide ? null : ($actor->isSuperAdmin() ? $data['branch_id'] : $actor->workBranchId());

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        $request->user()->notify(new ActionCompleted('user-created', $user->name));

        return $this->redirectAfterSave($request, 'users.index', 'user-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user): InertiaResponse
    {
        $this->authorize('update', $user);

        return Inertia::render('Users/Edit', [
            'user' => $this->editableFields($user),
            ...$this->userBranchOptions(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $resetDevices = (bool) ($data['reset_devices'] ?? false);
        unset($data['company_wide'], $data['reset_devices']);

        if ($request->user()->isSuperAdmin() && $request->companyWide() !== $user->isSuperAdmin()) {
            // Made a Super Admin over every branch, or sent to one branch as an employee.
            $data['role'] = $request->companyWide() ? UserRole::SuperAdmin : UserRole::Collector;
        }
        if ($request->user()->isSuperAdmin() && $request->companyWide()) {
            $data['branch_id'] = null;
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data, $resetDevices): void {
            $user->update($data);
            if ($resetDevices || array_key_exists('password', $data) || (array_key_exists('is_active', $data) && ! $data['is_active'])) {
                app(UserDeviceAccess::class)->revokeAll($user);
            }
        });
        $request->user()->notify(new ActionCompleted('user-updated', $user->name));

        return redirect()->route('users.index')->with('status', 'user-updated');
    }

    /**
     * Delete the user account, once nothing uses it any more.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        return $this->deleteRecord($request, $user, 'user-deleted', $user->name, fn () => $user->deleteAccount());
    }

    /**
     * A user's editable fields — used both for the dedicated edit page and
     * for the edit modal's initial form data on the index page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'max_devices' => $user->max_devices,
            'user_type_id' => $user->user_type_id,
            'role' => $user->role->value,
            'branch_id' => $user->branch_id,
            'is_active' => $user->is_active,
            'canUpdatePassword' => auth()->user()->can('updatePassword', $user),
            'isSuperAdmin' => $user->isSuperAdmin(),
        ];
    }

    /**
     * The Filter menu's dropdown groups for the index page. The branch
     * filter only makes sense for a Super Admin — everyone else's list is
     * already scoped to their own single branch.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function filterOptions(User $actor): array
    {
        $groups = [
            $this->filterGroup('user_type_id', 'نوع المستخدم', UserType::query()->orderBy('name')->get()->map(fn (UserType $type): array => ['value' => (string) $type->id, 'label' => $type->name])->all()),
            $this->activeStatusFilterGroup(),
        ];

        if ($actor->isSuperAdmin()) {
            $groups[] = $this->branchFilterGroup();
        }

        return $groups;
    }
}
