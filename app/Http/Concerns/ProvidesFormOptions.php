<?php

namespace App\Http\Concerns;

use App\Models\Branch;
use App\Models\UserType;
use App\Support\BranchDomainContext;
use Illuminate\Support\Collection;

/**
 * The dropdown options the branch and user forms need, shared by their own
 * pages and by the dashboard's quick "new …" buttons.
 */
trait ProvidesFormOptions
{
    /**
     * The branch form's choices: none any more — a branch is no longer
     * placed in a governorate or an area; its areas are managed on the
     * branch areas page. Kept so the form's loading stays as it was.
     *
     * @return array<string, never>
     */
    protected function branchFormOptions(): array
    {
        return [];
    }

    /**
     * The branch options for the user forms, and whether the actor may
     * choose the branch themselves.
     *
     * @return array{branches: Collection, canChooseBranch: bool, canCreateUserType: bool, userTypeOptions: Collection}
     */
    protected function userBranchOptions(): array
    {
        $canChooseBranch = auth()->user()->isSuperAdmin();

        return [
            'branches' => $canChooseBranch ? Branch::orderBy('name')->get() : collect(),
            'canChooseBranch' => $canChooseBranch,
            'canCreateUserType' => BranchDomainContext::id() === null && auth()->user()->can('create', UserType::class),
            'userTypeOptions' => UserType::orderBy('name')->get(['id', 'name'])
                ->map(fn (UserType $type): array => ['value' => $type->id, 'label' => $type->name]),
        ];
    }
}
