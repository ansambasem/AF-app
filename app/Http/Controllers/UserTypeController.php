<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreUserTypeRequest;
use App\Http\Requests\UpdateUserTypeRequest;
use App\Models\UserType;
use App\Notifications\ActionCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User types can be created directly from the user form's dropdown.
 */
class UserTypeController extends Controller
{
    use DeletesRecords;

    public function index(): RedirectResponse
    {
        $this->authorize('viewAny', UserType::class);

        return redirect()->route('users.index');
    }

    public function store(StoreUserTypeRequest $request): JsonResponse|RedirectResponse
    {
        $userType = UserType::create($request->validated());
        $request->user()->notify(new ActionCompleted('user-type-created', $userType->name));

        if ($request->expectsJson()) {
            return response()->json($userType->only(['id', 'name']), 201);
        }

        return redirect()->route('users.index')->with('status', 'user-type-created');
    }

    public function update(UpdateUserTypeRequest $request, UserType $userType): RedirectResponse
    {
        $userType->update($request->validated());
        $request->user()->notify(new ActionCompleted('user-type-updated', $userType->name));

        return redirect()->route('users.index')->with('status', 'user-type-updated');
    }

    public function destroy(Request $request, UserType $userType): RedirectResponse
    {
        return $this->deleteRecord($request, $userType, 'user-type-deleted', $userType->name);
    }
}
